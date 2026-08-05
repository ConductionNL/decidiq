<?php
/**
 * Decidesk Meeting Service
 *
 * Service for managing meeting lifecycle state transitions.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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
 * @spec openspec/changes/spec/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidesk\Lifecycle\MeetingTransitionGuard;
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
class MeetingService
{

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
        'open'     => ['from' => ['scheduled', 'adjourned'],                       'to' => 'opened'],
        'pause'    => ['from' => ['opened'],                                        'to' => 'paused'],
        'resume'   => ['from' => ['paused'],                                        'to' => 'opened'],
        'adjourn'  => ['from' => ['opened', 'paused'],                             'to' => 'adjourned'],
        'close'    => ['from' => ['scheduled', 'opened', 'paused', 'adjourned'],   'to' => 'closed'],
    ];

    /**
     * Constructor for MeetingService.
     *
     * @param ContainerInterface     $container          The DI container (used to retrieve ObjectService)
     * @param LoggerInterface        $logger             The logger
     * @param WorkflowService        $workflowService    Domain-specific transition rules and chair-only gates
     * @param MeetingTransitionGuard $transitionGuard    Reads quorumMet field for the open transition
     * @param MeetingCostService     $meetingCostService Computes the final meetingCost stamped on close (meeting-efficiency)
     * @param GovernanceScopeGuard   $scopeGuard         Consumes the OR-projected chair scope for chair-only transitions
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
     * @spec openspec/changes/spec/tasks.md#task-1
     * @spec openspec/specs/meeting-efficiency/spec.md
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly WorkflowService $workflowService,
        private readonly MeetingTransitionGuard $transitionGuard,
        private readonly MeetingCostService $meetingCostService,
        private readonly GovernanceScopeGuard $scopeGuard,
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
    public function getAvailableActions(string $currentLifecycle): array
    {
        $available = [];
        foreach (self::TRANSITIONS as $action => $transition) {
            if (in_array($currentLifecycle, $transition['from'], true) === true) {
                $available[] = $action;
            }
        }

        return $available;

    }//end getAvailableActions()

    /**
     * Apply a lifecycle transition to a meeting object.
     *
     * Validates that `$action` is a known transition, enforces domain-specific
     * workflow rules (WorkflowService), chair-only authorization (OWASP A01:2021),
     * and quorum requirements (MeetingTransitionGuard) before patching the object via
     * OpenRegister's ObjectService.
     *
     * @param string      $meetingId     UUID of the meeting to transition
     * @param string      $action        Transition action: schedule|open|pause|resume|adjourn|close
     * @param string|null $currentUserId Nextcloud UID of the requesting user (used for chair-only gates)
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
     * @spec openspec/changes/spec/tasks.md#task-1
     *
     * @return array{success: bool, meeting: array|null, message: string}
     */
    public function transition(string $meetingId, string $action, ?string $currentUserId=null): array
    {
        if (isset(self::TRANSITIONS[$action]) === false) {
            return $this->refusal(
                message: 'Unknown action. Valid actions: '.implode(', ', array_keys(self::TRANSITIONS)).'.'
            );
        }

        try {
            return $this->applyTransition(
                meetingId: $meetingId,
                action: $action,
                currentUserId: $currentUserId
            );
        } catch (DoesNotExistException) {
            return $this->refusal(message: "Meeting '$meetingId' not found.");
        } catch (Throwable $e) {
            $this->logger->error(
                'Decidesk: meeting lifecycle transition failed',
                ['id' => $meetingId, 'action' => $action, 'exception' => $e->getMessage()]
            );
            return $this->refusal(message: 'Transition failed. See server log for details.');
        }//end try

    }//end transition()

    /**
     * Load the meeting, run the transition gates, and patch it.
     *
     * @param string      $meetingId     UUID of the meeting to transition
     * @param string      $action        Transition action (already known to be valid)
     * @param string|null $currentUserId Nextcloud UID of the requesting user
     *
     * @return array{success: bool, meeting: array|null, message: string}
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
     */
    private function applyTransition(string $meetingId, string $action, ?string $currentUserId): array
    {
        $transition = self::TRANSITIONS[$action];

        /*
         * @var \OCA\OpenRegister\Service\ObjectService $objectService
         */

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        // Object-level read ACL: OpenRegister's ObjectService::find() resolves the
        // current Nextcloud session user and returns null when the caller lacks read
        // access to the requested object (same behaviour as a missing object).
        // This prevents callers without read access from probing meeting UUIDs.
        $entity = $objectService->find(id: $meetingId);
        if ($entity === null) {
            return $this->refusal(message: "Meeting '$meetingId' not found.");
        }

        $meetingData = $entity->getObject();

        $refusal = $this->transitionRefusal(
            transition: $transition,
            action: $action,
            meetingData: $meetingData,
            currentUserId: $currentUserId
        );
        if ($refusal !== null) {
            return $refusal;
        }

        // Meeting-efficiency timing/cost stamping (additive, server-side):
        // - first 'open' stamps openedAt (idempotent across pause/resume/
        // adjourn-resume so the cost window starts at the real start);
        // - 'close' stamps closedAt and the fail-soft final meetingCost.
        $efficiencyPatch = $this->buildEfficiencyPatch(
            action: $action,
            meetingId: $meetingId,
            meetingData: $meetingData
        );

        // Object-level write ACL: ObjectService::saveObject() checks that the
        // current Nextcloud session user has write access to this specific object
        // before applying the patch. If the caller lacks write access an exception
        // is thrown and caught by the \Throwable handler in transition(), returning
        // a generic error response without leaking object details.
        $updated = $objectService->saveObject(
            object: array_merge($meetingData, ['lifecycle' => $transition['to']], $efficiencyPatch),
            register: 'decidesk',
            schema: 'meeting',
            uuid: $meetingId,
        );

        $this->logger->info(
            'Decidesk: meeting lifecycle transitioned',
            ['id' => $meetingId, 'action' => $action, 'to' => $transition['to']]
        );

        $this->publishTransitionActivity(
            meetingData: $meetingData,
            meetingId: $meetingId,
            newState: $transition['to']
        );

        return [
            'success' => true,
            'meeting' => $updated->jsonSerialize(),
            'message' => "Meeting transitioned to '{$transition['to']}'.",
        ];

    }//end applyTransition()

    /**
     * Run every gate a transition must pass, returning the first refusal.
     *
     * Ordered cheapest-first: state machine, then domain rules, then the
     * chair-only gate, then quorum.
     *
     * @param array<string,mixed> $transition    The transition descriptor
     * @param string              $action        The transition action
     * @param array<string,mixed> $meetingData   Current meeting object
     * @param string|null         $currentUserId Nextcloud UID of the requesting user
     *
     * @return array{success: bool, meeting: array|null, message: string}|null The refusal, or null when permitted.
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
     */
    private function transitionRefusal(
        array $transition,
        string $action,
        array $meetingData,
        ?string $currentUserId,
    ): ?array {
        $currentLifecycle = ($meetingData['lifecycle'] ?? 'draft');
        $domain           = ($meetingData['domain'] ?? 'operations');

        if (in_array(needle: $currentLifecycle, haystack: $transition['from'], strict: true) === false) {
            return $this->refusal(
                message: "Cannot '$action' a meeting in '$currentLifecycle' state. "
                    ."Allowed from: ".implode(separator: ', ', array: $transition['from']).'.'
            );
        }

        // Domain-level transition validation (OWASP A01:2021).
        // Enforces domain-specific rules such as "no pause in corporate domain".
        $allowed = $this->workflowService->isTransitionAllowed(
            domain: $domain,
            fromState: $currentLifecycle,
            toState: $transition['to']
        );
        if ($allowed === false) {
            return $this->refusal(message: "Transition '$action' is not permitted in '$domain' domain.");
        }

        if ($this->chairGateDenies(
            domain: $domain,
            fromState: $currentLifecycle,
            toState: $transition['to'],
            meetingData: $meetingData,
            currentUserId: $currentUserId
        ) === true
        ) {
            return $this->refusal(message: 'Only the meeting chair may perform this transition.');
        }

        // Quorum enforcement before opening a meeting (OWASP A01:2021).
        // Reads quorumMet from the declarative Meeting field (chain spec 2 of 3).
        if ($action === 'open'
            && $this->workflowService->isQuorumRequired(domain: $domain) === true
            && $this->transitionGuard->isOpenAllowed(meeting: $meetingData) === false
        ) {
            return $this->refusal(message: 'Quorum is not met. Cannot open meeting.');
        }

        return null;

    }//end transitionRefusal()

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
     * @param string              $domain        The meeting's domain
     * @param string              $fromState     The current lifecycle state
     * @param string              $toState       The target lifecycle state
     * @param array<string,mixed> $meetingData   Current meeting object
     * @param string|null         $currentUserId Nextcloud UID of the requesting user
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
     * @param array<string,mixed> $meetingData Current meeting object
     * @param string              $meetingId   UUID of the transitioned meeting
     * @param string              $newState    The state it moved to
     *
     * @return void
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    private function publishTransitionActivity(array $meetingData, string $meetingId, string $newState): void
    {
        try {
            $this->container->get(\OCA\Decidesk\Service\ActivityPublisherService::class)->publishGovernanceEvent(
                subject: \OCA\Decidesk\Activity\DecideskProvider::SUBJECT_MEETING_TRANSITION,
                title: (string) ($meetingData['title'] ?? $meetingId),
                status: $newState,
                objectType: 'meeting',
                objectUuid: $meetingId,
                segment: 'meetings'
            );
        } catch (Throwable $activityError) {
            $this->logger->debug('Decidesk: activity publish skipped', ['error' => $activityError->getMessage()]);
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
    private function refusal(string $message): array
    {
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
    private function resolveBodyId(array $meetingData): ?string
    {
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
    private function collectBodyIdCandidates(array $meetingData): array
    {
        $candidates = [];
        $relations  = ($meetingData['relations'] ?? []);

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
     * @param string               $action      Transition action
     * @param string               $meetingId   Meeting UUID
     * @param array<string, mixed> $meetingData Current meeting object
     *
     * @return array<string, mixed> The fields to merge into the saved object (may be empty)
     *
     * @spec openspec/specs/meeting-efficiency/spec.md
     */
    private function buildEfficiencyPatch(string $action, string $meetingId, array $meetingData): array
    {
        $patch = [];

        if ($action === 'open' && empty($meetingData['openedAt']) === true) {
            $patch['openedAt'] = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
        }

        if ($action === 'close') {
            $closedAt          = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
            $patch['closedAt'] = $closedAt;

            try {
                // Pass the closedAt forward so the elapsed window is closed.
                $cost = $this->meetingCostService->calculateForMeeting(
                    meetingId: $meetingId,
                    meeting: array_merge($meetingData, ['closedAt' => $closedAt])
                );
                if ($cost !== null) {
                    $patch['meetingCost'] = $cost;
                }
            } catch (\Throwable $e) {
                // Fail-soft: cost errors must never block closing a meeting.
                $this->logger->debug(
                    'Decidesk: meetingCost computation skipped on close',
                    ['meetingId' => $meetingId, 'error' => $e->getMessage()]
                );
            }
        }//end if

        return $patch;

    }//end buildEfficiencyPatch()
}//end class
