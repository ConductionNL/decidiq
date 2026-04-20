<?php

/**
 * Decidesk Meeting Service
 *
 * Service for managing meeting lifecycle state transitions.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing meeting lifecycle state transitions.
 *
 * Implements the state machine defined in design.md:
 *   draft → scheduled → opened ↔ paused → adjourned → (re-)opened → closed
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
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
     * @param ContainerInterface $container The DI container (used to retrieve ObjectService)
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns the list of valid action names for a given lifecycle state.
     *
     * @param string $currentLifecycle The current lifecycle value of the meeting
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
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
     * Validates that `$action` is a known transition and that the meeting's
     * current lifecycle state permits the transition, then patches the object
     * via OpenRegister's ObjectService.
     *
     * @param string $meetingId UUID of the meeting to transition
     * @param string $action    Transition action: schedule|open|pause|resume|adjourn|close
     *
     * @spec openspec/changes/p2-meeting-management/tasks.md#task-1.1
     *
     * @return array{success: bool, meeting: array|null, message: string}
     */
    public function transition(string $meetingId, string $action): array
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

            $currentLifecycle = $entity->getObject()['lifecycle'] ?? 'draft';

            if (in_array($currentLifecycle, $transition['from'], true) === false) {
                return [
                    'success' => false,
                    'meeting' => null,
                    'message' => "Cannot '$action' a meeting in '$currentLifecycle' state. "
                        ."Allowed from: ".implode(', ', $transition['from']).".",
                ];
            }

            // Object-level write ACL: OpenRegister's ObjectService::updateFromArray()
            // checks that the current Nextcloud session user has write access to this
            // specific object before applying the patch. If the caller lacks write
            // access an exception is thrown and caught by the \Throwable handler below,
            // returning a generic error response without leaking object details.
            $updated = $objectService->updateFromArray(
                id: $meetingId,
                object: ['lifecycle' => $transition['to']],
                updateVersion: true,
                patch: true,
            );

            $this->logger->info(
                'Decidesk: meeting lifecycle transitioned',
                ['id' => $meetingId, 'action' => $action, 'to' => $transition['to']]
            );

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
}//end class
