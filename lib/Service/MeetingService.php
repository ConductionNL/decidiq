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
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidesk\Lifecycle\MeetingTransitionGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Unknown action. Valid actions: '.implode(', ', array_keys(self::TRANSITIONS)).'.',
            ];
        }

        $transition = self::TRANSITIONS[$action];

        try {
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
                return [
                    'success' => false,
                    'meeting' => null,
                    'message' => "Meeting '$meetingId' not found.",
                ];
            }

            $meetingData = $entity->getObject();

            $denial = $this->denyTransition(
                action: $action,
                transition: $transition,
                meetingData: $meetingData,
                currentUserId: $currentUserId
            );
            if ($denial !== null) {
                return $denial;
            }

            $updated = $this->persistTransition(
                meetingId: $meetingId,
                action: $action,
                meetingData: $meetingData,
                newState: $transition['to']
            );

            $this->publishTransitionActivity(
                meetingId: $meetingId,
                meetingData: $meetingData,
                newState: $transition['to']
            );

            return [
                'success' => true,
                'meeting' => $updated,
                'message' => "Meeting transitioned to '{$transition['to']}'.",
            ];
        } catch (DoesNotExistException) {
            return [
                'success' => false,
                'meeting' => null,
                'message' => "Meeting '$meetingId' not found.",
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: meeting lifecycle transition failed',
                ['id' => $meetingId, 'action' => $action, 'exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Transition failed. See server log for details.',
            ];
        }//end try

    }//end transition()

    /**
     * Stamp the efficiency patch and persist the new lifecycle state.
     *
     * Meeting-efficiency timing/cost stamping (additive, server-side):
     * - first 'open' stamps openedAt (idempotent across pause/resume/
     *   adjourn-resume so the cost window starts at the real start);
     * - 'close' stamps closedAt and the fail-soft final meetingCost.
     *
     * Object-level write ACL: ObjectService::saveObject() checks that the
     * current Nextcloud session user has write access to this specific object
     * before applying the patch. If the caller lacks write access an exception
     * is thrown and propagates to the \Throwable handler in
     * {@see transition()}, returning a generic error response without leaking
     * object details.
     *
     * @param string               $meetingId   UUID of the meeting
     * @param string               $action      Transition action being applied
     * @param array<string, mixed> $meetingData Current serialised meeting object
     * @param string               $newState    The lifecycle state to transition to
     *
     * @return array<string, mixed> The saved meeting, serialised
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
     */
    private function persistTransition(
        string $meetingId,
        string $action,
        array $meetingData,
        string $newState
    ): array {
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $efficiencyPatch = $this->buildEfficiencyPatch(
            action: $action,
            meetingId: $meetingId,
            meetingData: $meetingData
        );

        $updated = $objectService->saveObject(
            object: array_merge($meetingData, ['lifecycle' => $newState], $efficiencyPatch),
            register: 'decidesk',
            schema: 'meeting',
            uuid: $meetingId,
        );

        $this->logger->info(
            'Decidesk: meeting lifecycle transitioned',
            ['id' => $meetingId, 'action' => $action, 'to' => $newState]
        );

        return $updated->jsonSerialize();

    }//end persistTransition()

    /**
     * Run every pre-transition gate and return the first denial, if any.
     *
     * Fail-closed by construction: each gate returns the failure result it
     * would have returned inline in {@see transition()}, and a null return
     * means every gate passed.
     *
     * @param string               $action        Transition action being applied
     * @param array<string, mixed> $transition    The TRANSITIONS entry for $action
     * @param array<string, mixed> $meetingData   Current serialised meeting object
     * @param string|null          $currentUserId Nextcloud UID of the requesting user
     *
     * @return array{success: bool, meeting: array|null, message: string}|null Denial result, or null when allowed
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
     */
    private function denyTransition(string $action, array $transition, array $meetingData, ?string $currentUserId): ?array
    {
        $currentLifecycle = $meetingData['lifecycle'] ?? 'draft';
        $domain           = $meetingData['domain'] ?? 'operations';

        $denial = $this->denyForState(
            action: $action,
            transition: $transition,
            currentLifecycle: $currentLifecycle,
            domain: $domain
        );
        if ($denial !== null) {
            return $denial;
        }

        $denial = $this->denyForChairAuthorization(
            transition: $transition,
            currentLifecycle: $currentLifecycle,
            domain: $domain,
            meetingData: $meetingData,
            currentUserId: $currentUserId
        );
        if ($denial !== null) {
            return $denial;
        }

        return $this->denyForQuorum(action: $action, domain: $domain, meetingData: $meetingData);

    }//end denyTransition()

    /**
     * State-machine and domain-workflow gates.
     *
     * @param string               $action           Transition action being applied
     * @param array<string, mixed> $transition       The TRANSITIONS entry for $action
     * @param string               $currentLifecycle The meeting's current lifecycle state
     * @param string               $domain           The meeting's governance domain
     *
     * @return array{success: bool, meeting: array|null, message: string}|null Denial result, or null when allowed
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-2.2
     */
    private function denyForState(string $action, array $transition, string $currentLifecycle, string $domain): ?array
    {
        if (in_array(needle: $currentLifecycle, haystack: $transition['from'], strict: true) === false) {
            return [
                'success' => false,
                'meeting' => null,
                'message' => "Cannot '$action' a meeting in '$currentLifecycle' state. "
                    ."Allowed from: ".implode(separator: ', ', array: $transition['from']).".",
            ];
        }

        // Domain-level transition validation (OWASP A01:2021).
        // Enforces domain-specific rules such as "no pause in corporate domain".
        if ($this->workflowService->isTransitionAllowed(domain: $domain, fromState: $currentLifecycle, toState: $transition['to']) === false) {
            return [
                'success' => false,
                'meeting' => null,
                'message' => "Transition '$action' is not permitted in '$domain' domain.",
            ];
        }

        return null;

    }//end denyForState()

    /**
     * Chair-only transition enforcement (OWASP A01:2021 — broken access control).
     *
     * The requiresChairAuthorization() call is process configuration: it returns
     * true when "$from:$to" is in the workflow template's chairOnlyTransitions. The
     * actor decision is then made by consuming the OpenRegister-projected chair
     * scope for the owning body (consume-or-rbac-authorization) — replacing the
     * former NC-UID-vs-chair comparison. Fail-closed: an unresolved body or an
     * empty chair scope denies.
     *
     * @param array<string, mixed> $transition       The TRANSITIONS entry for the action
     * @param string               $currentLifecycle The meeting's current lifecycle state
     * @param string               $domain           The meeting's governance domain
     * @param array<string, mixed> $meetingData      Current serialised meeting object
     * @param string|null          $currentUserId    Nextcloud UID of the requesting user
     *
     * @return array{success: bool, meeting: array|null, message: string}|null Denial result, or null when allowed
     *
     * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-003-chair-only-lifecycle-transitions-are-enforced-by-openregister-property-rbac
     */
    private function denyForChairAuthorization(
        array $transition,
        string $currentLifecycle,
        string $domain,
        array $meetingData,
        ?string $currentUserId
    ): ?array {
        if ($this->workflowService->requiresChairAuthorization(domain: $domain, from: $currentLifecycle, to: $transition['to']) === false) {
            return null;
        }

        $bodyId = $this->resolveBodyId(meetingData: $meetingData);
        if ($currentUserId === null
            || $bodyId === null
            || $this->scopeGuard->isInBodyScope($currentUserId, $bodyId, GovernanceScopeGuard::SCOPE_CHAIR) === false
        ) {
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Only the meeting chair may perform this transition.',
            ];
        }

        return null;

    }//end denyForChairAuthorization()

    /**
     * Quorum enforcement before opening a meeting (OWASP A01:2021).
     *
     * Reads quorumMet from the declarative Meeting field (chain spec 2 of 3).
     *
     * @param string               $action      Transition action being applied
     * @param string               $domain      The meeting's governance domain
     * @param array<string, mixed> $meetingData Current serialised meeting object
     *
     * @return array{success: bool, meeting: array|null, message: string}|null Denial result, or null when allowed
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-3.3
     */
    private function denyForQuorum(string $action, string $domain, array $meetingData): ?array
    {
        if ($action !== 'open' || $this->workflowService->isQuorumRequired(domain: $domain) === false) {
            return null;
        }

        if ($this->transitionGuard->isOpenAllowed(meeting: $meetingData) === false) {
            return [
                'success' => false,
                'meeting' => null,
                'message' => 'Quorum is not met. Cannot open meeting.',
            ];
        }

        return null;

    }//end denyForQuorum()

    /**
     * Publish the meeting-transition Activity event (fail-soft).
     *
     * @param string               $meetingId   UUID of the transitioned meeting
     * @param array<string, mixed> $meetingData Serialised meeting object (pre-patch)
     * @param string               $newState    The lifecycle state transitioned to
     *
     * @return void
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    private function publishTransitionActivity(string $meetingId, array $meetingData, string $newState): void
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
        } catch (\Throwable $activityError) {
            $this->logger->debug('Decidesk: activity publish skipped', ['error' => $activityError->getMessage()]);
        }

    }//end publishTransitionActivity()

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
