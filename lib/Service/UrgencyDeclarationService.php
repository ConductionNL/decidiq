<?php

/**
 * Urgency Declaration Service
 *
 * Declares a decision urgent: loads it with the caller's object rights,
 * resolves its body's urgency policy and the caller's role through
 * UrgencyTriggerGuard (fail closed), stores the urgency fields under a write
 * grant so the field guard lets this save through, and appends the
 * ratification. The decision's lifecycle, outcome and publication are not
 * touched. The save lands in OpenRegister's audit trail with the actor, the
 * time and the reason.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\ProcessTemplateServiceInterface;
use OCA\Decidiq\Lifecycle\UrgencyTriggerGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the guarded urgency declaration.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 */
class UrgencyDeclarationService {

	/**
	 * The urgency fields only this flow writes.
	 *
	 * @var list<string>
	 */
	public const URGENCY_FIELDS = ['isUrgent', 'urgencyReason', 'urgencyDeclaredBy', 'urgencyDeclaredAt'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface              $container    Resolves OpenRegister's object service.
	 * @param DecisionContextResolver         $context      The decision's meeting, body and chair.
	 * @param ProcessTemplateServiceInterface $templates    The body's urgency policy.
	 * @param UrgencyTriggerGuard             $guard        Who may declare urgency.
	 * @param UrgencyWriteGrant               $grant        Lets this flow's own save past the field guard.
	 * @param UrgentRatificationService       $ratification Appends and places the ratification.
	 * @param ITimeFactory                    $timeFactory  The declaration time.
	 * @param LoggerInterface                 $logger       Logs a failed save.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly DecisionContextResolver $context,
		private readonly ProcessTemplateServiceInterface $templates,
		private readonly UrgencyTriggerGuard $guard,
		private readonly UrgencyWriteGrant $grant,
		private readonly UrgentRatificationService $ratification,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Declare a decision urgent.
	 *
	 * @param string      $decisionId   The decision.
	 * @param string      $userId       The acting user.
	 * @param string      $reason       Why it is urgent.
	 * @param string|null $bodyOverride A ratifying body other than the policy's, or null.
	 *
	 * @return array{status: int, data: array<string, mixed>} The HTTP status and the body to answer with.
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	public function declare(string $decisionId, string $userId, string $reason, ?string $bodyOverride=null): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable) {
			return self::envelope(status: Http::STATUS_SERVICE_UNAVAILABLE, message: 'OpenRegister is not available.');
		}

		$decision = $this->context->loadDecision(objectService: $objectService, decisionId: $decisionId);
		if ($decision === null) {
			return self::envelope(status: Http::STATUS_NOT_FOUND, message: sprintf('Decision "%s" not found.', $decisionId));
		}

		$meeting = $this->context->resolveLinkedMeeting(objectService: $objectService, decision: $decision);
		$bodyId  = $this->context->resolveGovernanceBodyId(decision: $decision, meeting: $meeting);
		$policy  = $this->templates->resolveUrgencyPolicyForBody(governanceBodyId: $bodyId);
		$refusal = $this->guard->refusal(
			policy: $policy,
			meetingId: $this->context->resolveRelationId(value: $this->context->readMeetingReference(decision: $decision)),
			chairUserId: $this->context->resolveChairUserId(objectService: $objectService, meeting: $meeting),
			userId: $userId,
			reason: $reason
		);
		if ($refusal !== null) {
			return self::envelope(status: $refusal['status'], message: $refusal['message']);
		}

		if (($decision['isUrgent'] ?? false) === true) {
			return self::envelope(status: Http::STATUS_CONFLICT, message: 'This decision has already been declared urgent.');
		}

		$override = null;
		if ($bodyOverride !== null && trim($bodyOverride) !== '') {
			$override = trim($bodyOverride);
		}

		$urgent = $decision;
		unset($urgent['@self']);
		$urgent['isUrgent']          = true;
		$urgent['urgencyReason']     = trim($reason);
		$urgent['urgencyDeclaredBy'] = $userId;
		$urgent['urgencyDeclaredAt'] = $this->timeFactory->getDateTime()->format(DATE_ATOM);

		$this->grant->grant(decisionId: $decisionId);
		try {
			$saved = $objectService->saveObject(object: $urgent, register: 'decidiq', schema: 'decision', uuid: $decisionId);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: declaring a decision urgent failed', ['decision' => $decisionId, 'reason' => $e->getMessage()]);
			return self::envelope(status: Http::STATUS_INTERNAL_SERVER_ERROR, message: 'The urgency declaration could not be saved.');
		} finally {
			$this->grant->revoke(decisionId: $decisionId);
		}

		$saved = (array)$saved->jsonSerialize();
		try {
			$ratification = $this->ratification->appendRatification(
				objectService: $objectService,
				decision: $saved,
				policy: (array)$policy,
				bodyOverride: $override
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: the ratification of an urgent decision could not be appended',
				['decision' => $decisionId, 'reason' => $e->getMessage()]
			);
			$warning = 'The decision is urgent, but its ratification stage could not be added: ' . $e->getMessage();
			return ['status' => Http::STATUS_OK, 'data' => ['decision' => $saved, 'ratification' => null, 'warning' => $warning]];
		}

		return ['status' => Http::STATUS_OK, 'data' => ['decision' => $saved, 'ratification' => $ratification]];
	}//end declare()

	/**
	 * A refusal with its message.
	 *
	 * @param int    $status  The HTTP status.
	 * @param string $message The message.
	 *
	 * @return array{status: int, data: array<string, mixed>}
	 */
	private static function envelope(int $status, string $message): array {
		return ['status' => $status, 'data' => ['message' => $message]];
	}//end envelope()
}//end class
