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

            $meetingData      = $entity->getObject();
            $currentLifecycle = $meetingData['lifecycle'] ?? 'draft';
            $domain           = $meetingData['domain'] ?? 'operations';

            // $meetingData['chair'] is the Participant UUID (not a NC UID).
            // Resolve the Participant object to get the linked Nextcloud user ID.
            $chairParticipantId = $meetingData['chair'] ?? null;
            $chairNcUserId      = null;
            if ($chairParticipantId !== null) {
                $chairParticipant = $objectService->find(
                    id: $chairParticipantId,
                    register: 'decidesk',
                    schema: 'participant'
                );
                if ($chairParticipant !== null) {
                    $chairData     = $chairParticipant->jsonSerialize();
                    $chairNcUserId = $chairData['nextcloudUserId'] ?? ($chairData['owner'] ?? null);
                } else {
                    $this->logger->warning(
                        'Decidesk MeetingService: chair participant not found',
                        ['meetingId' => $meetingId, 'chairParticipantId' => $chairParticipantId]
                    );
                }
            }

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

            // Chair-only transition enforcement (OWASP A01:2021 — broken access control).
            // requiresChairAuthorization() returns true when the transition is restricted
            // to the meeting chair (e.g. legislative:opened→adjourned).
            // Comparison is NC UID vs NC UID (resolved above from Participant record).
            if ($this->workflowService->requiresChairAuthorization(domain: $domain, from: $currentLifecycle, to: $transition['to']) === true) {
                if ($currentUserId === null || $currentUserId !== $chairNcUserId) {
                    return [
                        'success' => false,
                        'meeting' => null,
                        'message' => 'Only the meeting chair may perform this transition.',
                    ];
                }
            }

            // Quorum enforcement before opening a meeting (OWASP A01:2021).
            // Reads quorumMet from the declarative Meeting field (chain spec 2 of 3).
            if ($action === 'open' && $this->workflowService->isQuorumRequired(domain: $domain) === true) {
                if ($this->transitionGuard->isOpenAllowed(meeting: $meetingData) === false) {
                    return [
                        'success' => false,
                        'meeting' => null,
                        'message' => 'Quorum is not met. Cannot open meeting.',
                    ];
                }
            }

            // Meeting-efficiency timing/cost stamping (additive, server-side):
            //   - first 'open' stamps openedAt (idempotent across pause/resume/
            //     adjourn-resume so the cost window starts at the real start);
            //   - 'close' stamps closedAt and the fail-soft final meetingCost.
            $efficiencyPatch = $this->buildEfficiencyPatch(
                action: $action,
                meetingId: $meetingId,
                meetingData: $meetingData
            );

            // Object-level write ACL: ObjectService::saveObject() checks that the
            // current Nextcloud session user has write access to this specific object
            // before applying the patch. If the caller lacks write access an exception
            // is thrown and caught by the \Throwable handler below, returning a generic
            // error response without leaking object details.
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

            // Activity feed (fail-soft): meeting lifecycle transition.
            // @spec openspec/specs/nextcloud-integration/spec.md.
            try {
                $this->container->get(\OCA\Decidesk\Service\ActivityPublisherService::class)->publishGovernanceEvent(
                    subject: \OCA\Decidesk\Activity\DecideskProvider::SUBJECT_MEETING_TRANSITION,
                    title: (string) ($meetingData['title'] ?? $meetingId),
                    status: $transition['to'],
                    objectType: 'meeting',
                    objectUuid: $meetingId,
                    segment: 'meetings'
                );
            } catch (\Throwable $activityError) {
                $this->logger->debug('Decidesk: activity publish skipped', ['error' => $activityError->getMessage()]);
            }

            return [
                'success' => true,
                'meeting' => $updated->jsonSerialize(),
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
            $patch['openedAt'] = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
        }

        if ($action === 'close') {
            $closedAt           = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
            $patch['closedAt']  = $closedAt;

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
