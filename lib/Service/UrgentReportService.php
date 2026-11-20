<?php

/**
 * Urgent Report Service
 *
 * Records that an urgent decision was reported to the body that ratifies it.
 * Loads the decision with the caller's object rights, lets only the roles the
 * body's urgency policy allows to declare urgency run it (fail closed, checked
 * before anything about the decision is revealed), and sets the report date
 * once: running it again keeps the first date.
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
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the report action on an urgent decision.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
 */
class UrgentReportService {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface              $container Resolves OpenRegister's object service.
	 * @param DecisionContextResolver         $context   The decision's meeting, body and chair.
	 * @param ProcessTemplateServiceInterface $templates The body's urgency policy.
	 * @param UrgencyTriggerGuard             $guard     Who may act on the urgency.
	 * @param RatifyingBodyReport             $report    Sets the report date once.
	 * @param LoggerInterface                 $logger    Logs a failed save.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly DecisionContextResolver $context,
		private readonly ProcessTemplateServiceInterface $templates,
		private readonly UrgencyTriggerGuard $guard,
		private readonly RatifyingBodyReport $report,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record that the urgent decision was reported to its ratifying body.
	 *
	 * @param string $decisionId The decision.
	 * @param string $userId     The acting user.
	 *
	 * @return array{status: int, data: array<string, mixed>} The HTTP status and the body to answer with.
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
	 */
	public function report(string $decisionId, string $userId): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable) {
			return self::envelope(status: Http::STATUS_SERVICE_UNAVAILABLE, message: 'OpenRegister is not available.');
		}

		$decision = $this->context->loadDecision(objectService: $objectService, decisionId: $decisionId);
		if ($decision === null) {
			return self::envelope(status: Http::STATUS_NOT_FOUND, message: sprintf('Decision "%s" not found.', $decisionId));
		}

		$refusal = $this->refusal(objectService: $objectService, decision: $decision, userId: $userId);
		if ($refusal !== null) {
			return $refusal;
		}

		if ($this->report->isDue(decision: $decision) === false) {
			return ['status' => Http::STATUS_OK, 'data' => ['decision' => $decision, 'alreadyReported' => true]];
		}

		return $this->save(objectService: $objectService, decisionId: $decisionId, decision: $decision);
	}//end report()

	/**
	 * Why the actor may not report this decision, or null when they may.
	 *
	 * The role is checked before the decision's state, so a caller without
	 * the role learns nothing about it.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param array<string, mixed> $decision      The decision.
	 * @param string               $userId        The acting user.
	 *
	 * @return array{status: int, data: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
	 */
	private function refusal(object $objectService, array $decision, string $userId): ?array {
		$meeting = $this->context->resolveLinkedMeeting(objectService: $objectService, decision: $decision);
		$policy  = $this->templates->resolveUrgencyPolicyForBody(
			governanceBodyId: $this->context->resolveGovernanceBodyId(decision: $decision, meeting: $meeting)
		);
		if ($policy === null) {
			return self::envelope(status: Http::STATUS_UNPROCESSABLE_ENTITY, message: 'The urgent procedure is not configured for this body.');
		}

		$allowed = $this->guard->mayTrigger(
			policy: $policy,
			meetingId: $this->context->resolveRelationId(value: $this->context->readMeetingReference(decision: $decision)),
			chairUserId: $this->context->resolveChairUserId(objectService: $objectService, meeting: $meeting),
			userId: $userId
		);
		if ($allowed === false) {
			return self::envelope(
				status: Http::STATUS_FORBIDDEN,
				message: 'Only the chair or a role the urgency policy allows may record the report to the ratifying body.'
			);
		}

		if (($decision['isUrgent'] ?? false) !== true) {
			return self::envelope(status: Http::STATUS_CONFLICT, message: 'This decision has not been declared urgent.');
		}

		return null;
	}//end refusal()

	/**
	 * Save today's report date on the decision.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $decisionId    The decision.
	 * @param array<string, mixed> $decision      The decision.
	 *
	 * @return array{status: int, data: array<string, mixed>}
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-021-the-report-to-the-ratifying-body-is-recorded-once
	 */
	private function save(object $objectService, string $decisionId, array $decision): array {
		$stamped = $this->report->stamped(decision: $decision);
		try {
			$saved = $this->report->granted(
				decisionId: $decisionId,
				save: static fn (): mixed => $objectService->saveObject(
					object: $stamped,
					options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'decidiq', schema: 'decision'), uuid: $decisionId)
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: recording the report of an urgent decision failed', ['decision' => $decisionId, 'reason' => $e->getMessage()]);
			return self::envelope(status: Http::STATUS_INTERNAL_SERVER_ERROR, message: 'The report to the ratifying body could not be saved.');
		}

		return ['status' => Http::STATUS_OK, 'data' => ['decision' => (array)$saved->jsonSerialize(), 'alreadyReported' => false]];
	}//end save()

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
