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
 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\ProcessTemplateServiceInterface;
use OCA\Decidiq\Lifecycle\UrgencyTriggerGuard;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the guarded urgency declaration.
 *
 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
 */
class UrgencyDeclarationService {

	/**
	 * The urgency fields only the urgency flows write: the declaration, and
	 * the report to the ratifying body (UrgentReportService).
	 *
	 * @var list<string>
	 *
	 * @spec openspec/changes/urgent-decisions-register/tasks.md#task-1-fields
	 */
	public const URGENCY_FIELDS = [
		'isUrgent',
		'urgencyReason',
		'urgencyDeclaredBy',
		'urgencyDeclaredAt',
		'urgencyDeclaredByBody',
		'urgencyDeclaredInOffice',
		'reportedToRatifyingBodyAt',
	];

	/**
	 * The longest office name accepted, as the schema declares it.
	 *
	 * @var int
	 */
	public const MAX_OFFICE_LENGTH = 255;

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
	 * The declaration names the body on whose behalf it is made and, when the
	 * power belongs to one office, that office. A declaration without a body
	 * is refused after the role check, so an unauthorised caller learns
	 * nothing about what a valid request holds.
	 *
	 * @param string             $decisionId   The decision.
	 * @param string             $userId       The acting user.
	 * @param string             $reason       Why it is urgent.
	 * @param string|null        $bodyOverride A ratifying body other than the policy's, or null.
	 * @param array<string, mixed> $declarer   `body` (governance body uuid, required) and `office` (optional).
	 *
	 * @return array{status: int, data: array<string, mixed>} The HTTP status and the body to answer with.
	 *
	 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-020-the-declaration-records-the-declaring-body-or-office
	 */
	public function declare(string $decisionId, string $userId, string $reason, ?string $bodyOverride=null, array $declarer=[]): array {
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

		$declaredBy = self::declaredBy(declarer: $declarer);
		if (is_string($declaredBy) === true) {
			return self::envelope(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: $declaredBy);
		}

		if (($decision['isUrgent'] ?? false) === true) {
			return self::envelope(status: Http::STATUS_CONFLICT, message: 'This decision has already been declared urgent.');
		}

		$urgent = $decision;
		unset($urgent['@self']);
		$urgent['isUrgent']          = true;
		$urgent['urgencyReason']     = trim($reason);
		$urgent['urgencyDeclaredBy'] = $userId;
		$urgent['urgencyDeclaredAt'] = $this->timeFactory->getDateTime()->format(DATE_ATOM);
		$urgent = array_merge($urgent, $declaredBy);

		$this->grant->grant(decisionId: $decisionId);
		try {
			$saved = $objectService->saveObject(
				object: $urgent,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'decision'), uuid: $decisionId)
			);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: declaring a decision urgent failed', ['decision' => $decisionId, 'reason' => $e->getMessage()]);
			return self::envelope(status: Http::STATUS_INTERNAL_SERVER_ERROR, message: 'The urgency declaration could not be saved.');
		} finally {
			$this->grant->revoke(decisionId: $decisionId);
		}

		return [
			'status' => Http::STATUS_OK,
			'data'   => $this->withRatification(
				objectService: $objectService,
				decisionId: $decisionId,
				saved: (array)$saved->jsonSerialize(),
				policy: (array)$policy,
				bodyOverride: $bodyOverride
			),
		];
	}//end declare()

	/**
	 * The response body: the saved decision with its appended ratification stage,
	 * or with a warning when the stage could not be added (the decision stays urgent).
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $decisionId    The decision.
	 * @param array<string, mixed> $saved         The saved urgent decision.
	 * @param array<string, mixed> $policy        The body's urgency policy.
	 * @param string|null          $bodyOverride  The ratifying body asked for, if any.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
	 */
	private function withRatification(object $objectService, string $decisionId, array $saved, array $policy, ?string $bodyOverride): array {
		$override = null;
		if ($bodyOverride !== null && trim($bodyOverride) !== '') {
			$override = trim($bodyOverride);
		}

		try {
			$ratification = $this->ratification->appendRatification(
				objectService: $objectService,
				decision: $saved,
				policy: $policy,
				bodyOverride: $override
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: the ratification of an urgent decision could not be appended',
				['decision' => $decisionId, 'reason' => $e->getMessage()]
			);
			$warning = 'The decision is urgent, but its ratification stage could not be added: ' . $e->getMessage();
			return ['decision' => $saved, 'ratification' => null, 'warning' => $warning];
		}

		return ['decision' => $saved, 'ratification' => $ratification];
	}//end withRatification()

	/**
	 * The declaring body and office as decision fields, or why they are refused.
	 *
	 * @param array<string, mixed> $declarer `body` and `office` as posted.
	 *
	 * @return array<string, string|null>|string The fields, or the refusal message naming the field.
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-020-the-declaration-records-the-declaring-body-or-office
	 */
	private static function declaredBy(array $declarer): array|string {
		$body = $declarer['body'] ?? null;
		if (is_string($body) === false || trim($body) === '') {
			return 'urgencyDeclaredByBody is required: name the body on whose behalf the decision is declared urgent.';
		}

		$office = $declarer['office'] ?? null;
		if (is_string($office) === false || trim($office) === '') {
			$office = null;
		}

		if ($office !== null && mb_strlen(trim($office)) > self::MAX_OFFICE_LENGTH) {
			return 'urgencyDeclaredInOffice is longer than ' . self::MAX_OFFICE_LENGTH . ' characters.';
		}

		return ['urgencyDeclaredByBody' => trim($body), 'urgencyDeclaredInOffice' => ($office === null ? null : trim($office))];
	}//end declaredBy()

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
