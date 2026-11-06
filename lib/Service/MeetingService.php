<?php

/**
 * Decidiq Meeting Service
 *
 * Service for managing meeting lifecycle state transitions.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
 * @spec openspec/specs/meeting-management/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\Lifecycle\MeetingTransitionGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for managing meeting lifecycle state transitions.
 *
 * CRUD operations (create/read/update/delete) are handled directly by the
 * frontend via OpenRegister's object API. This service is responsible only
 * for the guarded lifecycle state machine.
 *
 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.3
 */
class MeetingService {

	/**
	 * Valid lifecycle transitions keyed by action name.
	 *
	 * Each entry defines:
	 * - `from`: the set of states from which this action is permitted
	 * - `to`:   the resulting state after the transition
	 *
	 * @var array<string, array{from: string[], to: string}>
	 */
	private const TRANSITIONS = [
		'schedule' => ['from' => ['draft'],                                        'to' => 'scheduled'],
		'open' => ['from' => ['scheduled', 'adjourned'],                       'to' => 'opened'],
		'pause' => ['from' => ['opened'],                                        'to' => 'paused'],
		'resume' => ['from' => ['paused'],                                        'to' => 'opened'],
		'adjourn' => ['from' => ['opened', 'paused'],                             'to' => 'adjourned'],
		'close' => ['from' => ['scheduled', 'opened', 'paused', 'adjourned'],   'to' => 'closed'],
	];

	/**
	 * Constructor for MeetingService.
	 *
	 * @param ContainerInterface $container The DI container (used to retrieve ObjectService)
	 * @param LoggerInterface $logger The logger
	 * @param WorkflowService $workflowService Domain-specific transition rules and chair-only gates
	 * @param MeetingTransitionGuard $transitionGuard Reads quorumMet field for the open transition
	 * @param MeetingCostService $meetingCostService Computes the final meetingCost stamped on close (meeting-efficiency)
	 * @param GovernanceScopeGuard $scopeGuard Consumes the OR-projected chair scope for chair-only transitions
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
	 * @spec openspec/specs/meeting-management/spec.md
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly WorkflowService $workflowService,
		private readonly MeetingTransitionGuard $transitionGuard,
		private readonly MeetingCostService $meetingCostService,
		private readonly GovernanceScopeGuard $scopeGuard,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns the list of valid action names for a given lifecycle state.
	 *
	 * @param string $currentLifecycle The current lifecycle value of the meeting
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.3
	 *
	 * @return string[] List of action names the caller may invoke
	 */
	public function getAvailableActions(string $currentLifecycle): array {
		$available = [];
		foreach (self::TRANSITIONS as $action => $transition) {
			if (in_array($currentLifecycle, $transition['from'], true) === true) {
				$available[] = $action;
			}
		}

		return $available;
	}//end getAvailableActions()

	/**
	 * Tell a caller the meeting's stage and the steps they may take from it.
	 *
	 * Runs the same state machine, domain rules and chair-only gate that
	 * transition() runs, so a stage button shows exactly when the server would
	 * accept the step. Quorum is left to the step itself: it can change between
	 * reading the page and pressing the button, and its refusal says why.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string $userId    Nextcloud UID of the caller
	 *
	 * @return array{lifecycle: string, actions: string[]}|null Null when the caller cannot read the meeting
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
	 */
	public function availableActionsFor(string $meetingId, string $userId): ?array {
		$context = $this->stageContext(meetingId: $meetingId, userId: $userId);
		if ($context === null) {
			return null;
		}

		$actions = [];
		foreach ($this->getAvailableActions(currentLifecycle: $context['lifecycle']) as $action) {
			if ($this->ruleGateRefusal(action: $action, context: $context) === null) {
				$actions[] = $action;
			}
		}

		return ['lifecycle' => $context['lifecycle'], 'actions' => $actions];

	}//end availableActionsFor()

	/**
	 * The gate context of a stage button: the meeting's stage and domain as
	 * text, the meeting and the caller; null when the meeting does not exist
	 * or the caller cannot read it.
	 *
	 * @param string $meetingId UUID of the meeting
	 * @param string $userId    Nextcloud UID of the caller
	 *
	 * @return array{lifecycle: string, domain: string, meeting: array<string,mixed>, userId: string}|null
	 *
	 * @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages
	 */
	private function stageContext(string $meetingId, string $userId): ?array {
		try {
			$entity = $this->objectService->find(id: $meetingId);
		} catch (DoesNotExistException) {
			return null;
		}

		$meetingData = $entity?->getObject();
		if ($meetingData === null) {
			return null;
		}

		$lifecycle = (string)($meetingData['lifecycle'] ?? 'draft');
		return ['lifecycle' => $lifecycle, 'domain' => (string)($meetingData['domain'] ?? 'operations'), 'meeting' => $meetingData, 'userId' => $userId];
	}//end stageContext()

	/**
	 * Apply a lifecycle transition to a meeting object.
	 *
	 * Validates that `$action` is a known transition, enforces domain-specific
	 * workflow rules (WorkflowService), chair-only authorization (OWASP A01:2021),
	 * and quorum requirements (MeetingTransitionGuard) before patching the object via
	 * OpenRegister's ObjectService.
	 *
	 * @param string $meetingId UUID of the meeting to transition
	 * @param string $action Transition action: schedule|open|pause|resume|adjourn|close
	 * @param string|null $currentUserId Nextcloud UID of the requesting user (used for chair-only gates)
	 *
	 * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
	 * @spec openspec/specs/meeting-management/spec.md
	 *
	 * @return array{success: bool, meeting: array|null, message: string}
	 */
	public function transition(string $meetingId, string $action, ?string $currentUserId = null): array {
		if (isset(self::TRANSITIONS[$action]) === false) {
			return $this->refusal(message: 'Unknown action. Valid actions: ' . implode(', ', array_keys(self::TRANSITIONS)) . '.');
		}

		try {
			return $this->applyTransition(meetingId: $meetingId, request: ['action' => $action, 'userId' => $currentUserId]);
		} catch (DoesNotExistException) {
			return $this->refusal(message: "Meeting '$meetingId' not found.");
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: meeting lifecycle transition failed', ['id' => $meetingId, 'action' => $action, 'exception' => $e->getMessage()]);
			return $this->refusal(message: 'Transition failed. See server log for details.');
		}

	}//end transition()

	/**
	 * Load the meeting, run the transition gates, and patch it.
	 *
	 * @param string                                  $meetingId UUID of the meeting to transition
	 * @param array{action: string, userId: ?string} $request   The action (already known to be valid) and the requesting user
	 *
	 * @return array{success: bool, meeting: array|null, message: string}
	 *
	 * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
	 */
	private function applyTransition(string $meetingId, array $request): array {
		// Object-level read ACL: OpenRegister's ObjectService::find() resolves the
		// current Nextcloud session user and returns null when the caller lacks read
		// access to the requested object (same behaviour as a missing object).
		// This prevents callers without read access from probing meeting UUIDs.
		$entity = $this->objectService->find(id: $meetingId);
		if ($entity === null) {
			return $this->refusal(message: "Meeting '$meetingId' not found.");
		}

		$meetingData = $entity->getObject();
		$context     = [
			'lifecycle' => ($meetingData['lifecycle'] ?? 'draft'),
			'domain' => ($meetingData['domain'] ?? 'operations'),
			'meeting' => $meetingData,
			'userId' => $request['userId'],
		];

		return ($this->transitionRefusal(action: $request['action'], context: $context)
			?? $this->saveTransition(meetingId: $meetingId, change: ['action' => $request['action'], 'meeting' => $meetingData]));

	}//end applyTransition()

	/**
	 * Save the meeting in its new state with the efficiency stamps, log it and publish it.
	 *
	 * @param string                                              $meetingId UUID of the meeting
	 * @param array{action: string, meeting: array<string,mixed>} $change    The action and the current meeting object
	 *
	 * @return array{success: bool, meeting: array|null, message: string}
	 *
	 * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
	 */
	private function saveTransition(string $meetingId, array $change): array {
		$toState = self::TRANSITIONS[$change['action']]['to'];

		// Object-level write ACL: ObjectService::saveObject() checks that the
		// current Nextcloud session user has write access to this specific object
		// before applying the patch. If the caller lacks write access an exception
		// is thrown and caught by the \Throwable handler in transition(), returning
		// a generic error response without leaking object details.
		$updated = $this->objectService->saveObject(
			object: array_merge($change['meeting'], ['lifecycle' => $toState], $this->buildEfficiencyPatch(meetingId: $meetingId, change: $change)),
			register: 'decidiq',
			schema: 'meeting',
			uuid: $meetingId,
		);

		$this->logger->info('Decidiq: meeting lifecycle transitioned', ['id' => $meetingId, 'action' => $change['action'], 'to' => $toState]);
		$this->publishTransitionActivity(meetingId: $meetingId, change: $change);

		return ['success' => true, 'meeting' => $updated->jsonSerialize(), 'message' => "Meeting transitioned to '{$toState}'."];

	}//end saveTransition()

	/**
	 * Run every gate a transition must pass, returning the first refusal.
	 *
	 * Ordered cheapest-first: state machine, then domain rules, then the
	 * chair-only gate, then quorum.
	 *
	 * @param string $action  The transition action
	 * @param array<string,mixed> $context lifecycle, domain, meeting and userId of the requesting user
	 *
	 * @return array{success: bool, meeting: array|null, message: string}|null The refusal, or null when permitted.
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
	 */
	private function transitionRefusal(string $action, array $context): ?array {
		$from = self::TRANSITIONS[$action]['from'];
		if (in_array(needle: $context['lifecycle'], haystack: $from, strict: true) === false) {
			return $this->refusal(
				message: "Cannot '$action' a meeting in '{$context['lifecycle']}' state. "
					. 'Allowed from: ' . implode(separator: ', ', array: $from) . '.'
			);
		}

		$message = ($this->ruleGateRefusal(action: $action, context: $context) ?? $this->quorumRefusal(action: $action, context: $context));
		if ($message !== null) {
			return $this->refusal(message: $message);
		}

		return null;
	}//end transitionRefusal()

	/**
	 * The refusal of the domain rules (OWASP A01:2021), such as "no pause in
	 * corporate domain", or of the chair-only gate; null when both permit the step.
	 *
	 * @param string $action  The transition action
	 * @param array<string,mixed> $context lifecycle, domain, meeting and userId of the requesting user
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
	 */
	private function ruleGateRefusal(string $action, array $context): ?string {
		$toState = self::TRANSITIONS[$action]['to'];
		if ($this->workflowService->isTransitionAllowed(domain: $context['domain'], fromState: $context['lifecycle'], toState: $toState) === false) {
			return "Transition '$action' is not permitted in '{$context['domain']}' domain.";
		}

		return $this->chairRefusal(toState: $toState, context: $context);
	}//end ruleGateRefusal()

	/**
	 * The chair-only gate's refusal; null when it permits the step.
	 *
	 * @param string $toState The target lifecycle state
	 * @param array<string,mixed> $context lifecycle, domain, meeting and userId of the requesting user
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
	 */
	private function chairRefusal(string $toState, array $context): ?string {
		if ($this->chairGateDenies(
			domain: $context['domain'],
			fromState: $context['lifecycle'],
			toState: $toState,
			meetingData: $context['meeting'],
			currentUserId: $context['userId']
		) === true
		) {
			return 'Only the meeting chair may perform this transition.';
		}

		return null;
	}//end chairRefusal()

	/**
	 * The quorum refusal before opening a meeting (OWASP A01:2021); reads
	 * quorumMet from the declarative Meeting field (chain spec 2 of 3).
	 *
	 * @param string $action  The transition action
	 * @param array<string,mixed> $context lifecycle, domain, meeting and userId of the requesting user
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
	 */
	private function quorumRefusal(string $action, array $context): ?string {
		if ($action === 'open'
			&& $this->workflowService->isQuorumRequired(domain: $context['domain']) === true
			&& $this->transitionGuard->isOpenAllowed(meeting: $context['meeting']) === false
		) {
			return 'Quorum is not met. Cannot open meeting.';
		}

		return null;
	}//end quorumRefusal()

	/**
	 * Whether the chair-only gate applies and the caller does not satisfy it.
	 *
	 * The workflow service's requiresChairAuthorization() is process
	 * configuration: it returns true when "$from:$to" is in the workflow
	 * template's chairOnlyTransitions. The
	 * actor decision is then made by consuming the OpenRegister-projected chair
	 * scope for the owning body (consume-or-rbac-authorization) — replacing the
	 * former NC-UID-vs-chair comparison. Fail-closed: an unresolved body or an
	 * empty chair scope denies.
	 *
	 * @param string $domain The meeting's domain
	 * @param string $fromState The current lifecycle state
	 * @param string $toState The target lifecycle state
	 * @param array<string,mixed> $meetingData Current meeting object
	 * @param string|null $currentUserId Nextcloud UID of the requesting user
	 *
	 * @return bool True when the transition must be refused.
	 *
	 * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
	 */
	private function chairGateDenies(
		string $domain,
		string $fromState,
		string $toState,
		array $meetingData,
		?string $currentUserId,
	): bool {
		$chairOnly = $this->workflowService->requiresChairAuthorization(
			domain: $domain,
			from: $fromState,
			to: $toState
		);
		if ($chairOnly === false) {
			return false;
		}

		$bodyId = $this->resolveBodyId(meetingData: $meetingData);

		return $currentUserId === null
			|| $bodyId === null
			|| $this->scopeGuard->isInBodyScope($currentUserId, $bodyId, GovernanceScopeGuard::SCOPE_CHAIR) === false;

	}//end chairGateDenies()

	/**
	 * Publish the lifecycle transition to the Nextcloud activity feed (fail-soft).
	 *
	 * @param string                                              $meetingId UUID of the transitioned meeting
	 * @param array{action: string, meeting: array<string,mixed>} $change    The action and the meeting object before it
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	private function publishTransitionActivity(string $meetingId, array $change): void {
		try {
			$this->container->get(\OCA\Decidiq\Service\ActivityPublisherService::class)->publishGovernanceEvent(
				activity: [
					'subject' => \OCA\Decidiq\Activity\DecidiqProvider::SUBJECT_MEETING_TRANSITION,
					'title' => (string)($change['meeting']['title'] ?? $meetingId),
					'status' => self::TRANSITIONS[$change['action']]['to'],
					'objectType' => 'meeting',
					'objectUuid' => $meetingId,
					'segment' => 'meetings',
				]
			);
		} catch (Throwable $activityError) {
			$this->logger->debug('Decidiq: activity publish skipped', ['error' => $activityError->getMessage()]);
		}

	}//end publishTransitionActivity()

	/**
	 * The standard refusal shape returned by every guarded exit of transition().
	 *
	 * @param string $message The human-readable reason
	 *
	 * @return array{success: bool, meeting: array|null, message: string}
	 *
	 * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
	 */
	private function refusal(string $message): array {
		return [
			'success' => false,
			'meeting' => null,
			'message' => $message,
		];

	}//end refusal()

	/**
	 * Extract the owning GovernanceBody UUID from a serialised meeting object
	 * (relations map or a direct governanceBody property). Used only to select
	 * the chair scope to consult for chair-only transitions.
	 *
	 * @param array<string, mixed> $meetingData Current meeting object
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-003-chair-only-lifecycle-transitions-are-enforced-by-openregister-property-rbac
	 */
	private function resolveBodyId(array $meetingData): ?string {
		foreach ($this->collectBodyIdCandidates(meetingData: $meetingData) as $value) {
			if (is_array($value) === true) {
				$value = ($value['id'] ?? ($value[0] ?? null));
			}

			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return null;
	}//end resolveBodyId()

	/**
	 * Collect the raw governance-body references a meeting may carry.
	 *
	 * Relations entries are preferred over the flat property, and both the
	 * camelCase and PascalCase key spellings are honoured — the order returned
	 * is the order the candidates are tried in.
	 *
	 * @param array<string, mixed> $meetingData Current meeting object
	 *
	 * @return array<int, mixed> The candidate references, most preferred first
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	private function collectBodyIdCandidates(array $meetingData): array {
		$candidates = [];
		$relations = ($meetingData['relations'] ?? []);

		foreach (['governanceBody', 'GovernanceBody'] as $key) {
			if (is_array($relations) === true && isset($relations[$key]) === true) {
				$candidates[] = $relations[$key];
			}
		}

		foreach (['governanceBody', 'GovernanceBody'] as $key) {
			if (isset($meetingData[$key]) === true) {
				$candidates[] = $meetingData[$key];
			}
		}

		return $candidates;
	}//end collectBodyIdCandidates()

	/**
	 * Build the additive meeting-efficiency patch for a lifecycle transition.
	 *
	 * On the first 'open' (no openedAt yet) stamps `openedAt` so the cost /
	 * duration window starts at the real meeting start and is not reset by a
	 * later pause/resume or adjourn/resume. On 'close' stamps `closedAt` and,
	 * fail-soft, the final `meetingCost` resolved server-side from stored data
	 * (a cost error never blocks closing a meeting).
	 *
	 * @param string                                              $meetingId Meeting UUID
	 * @param array{action: string, meeting: array<string,mixed>} $change    The action and the current meeting object
	 *
	 * @return array<string, mixed> The fields to merge into the saved object (may be empty)
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	private function buildEfficiencyPatch(string $meetingId, array $change): array {
		if ($change['action'] === 'open' && empty($change['meeting']['openedAt']) === true) {
			return ['openedAt' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM)];
		}

		if ($change['action'] === 'close') {
			return $this->closePatch(meetingId: $meetingId, meetingData: $change['meeting']);
		}

		return [];
	}//end buildEfficiencyPatch()

	/**
	 * The close stamps: closedAt and, fail-soft, the final meetingCost.
	 *
	 * @param string               $meetingId   Meeting UUID
	 * @param array<string, mixed> $meetingData Current meeting object
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/meeting-efficiency/spec.md
	 */
	private function closePatch(string $meetingId, array $meetingData): array {
		$closedAt = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
		$patch    = ['closedAt' => $closedAt];
		try {
			// Pass the closedAt forward so the elapsed window is closed.
			$cost = $this->meetingCostService->calculateForMeeting(meetingId: $meetingId, meeting: array_merge($meetingData, ['closedAt' => $closedAt]));
			if ($cost !== null) {
				$patch['meetingCost'] = $cost;
			}
		} catch (\Throwable $e) {
			// Fail-soft: cost errors must never block closing a meeting.
			$this->logger->debug('Decidiq: meetingCost computation skipped on close', ['meetingId' => $meetingId, 'error' => $e->getMessage()]);
		}

		return $patch;
	}//end closePatch()
}//end class
