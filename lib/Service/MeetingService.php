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
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing meeting CRUD operations and lifecycle state transitions.
 *
 * Implements the state machine defined in design.md:
 *   draft → scheduled → opened ↔ paused → adjourned → (re-)opened → closed
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
     * Create a new meeting object in OpenRegister.
     *
     * @param array<string, mixed> $meetingData Meeting data including title, meetingType, scheduledDate, etc.
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.3
     *
     * @return array<string, mixed> The created meeting object
     */
    public function create(array $meetingData): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $object = $objectService->createFromArray(
                register: 'decidesk',
                schema: 'meeting',
                object: $meetingData,
            );

            $this->logger->info(
                'Decidesk: meeting created',
                ['id' => $object->getId()]
            );

            return $object->jsonSerialize();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: meeting creation failed',
                ['exception' => $e->getMessage()]
            );
            throw $e;
        }
    }//end create()

    /**
     * Read a meeting object from OpenRegister by ID.
     *
     * @param string $meetingId UUID of the meeting to read
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.3
     *
     * @return array<string, mixed>|null The meeting object or null if not found
     */
    public function read(string $meetingId): ?array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $entity = $objectService->find(id: $meetingId);

            if ($entity === null) {
                return null;
            }

            return $entity->jsonSerialize();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: meeting read failed',
                ['id' => $meetingId, 'exception' => $e->getMessage()]
            );
            return null;
        }
    }//end read()

    /**
     * Update an existing meeting object in OpenRegister.
     *
     * @param string              $meetingId   UUID of the meeting to update
     * @param array<string, mixed> $meetingData Updated meeting data
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.3
     *
     * @return array<string, mixed>|null The updated meeting object or null on failure
     */
    public function update(string $meetingId, array $meetingData): ?array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $updated = $objectService->updateFromArray(
                id: $meetingId,
                object: $meetingData,
                updateVersion: true,
                patch: true,
            );

            $this->logger->info(
                'Decidesk: meeting updated',
                ['id' => $meetingId]
            );

            return $updated->jsonSerialize();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: meeting update failed',
                ['id' => $meetingId, 'exception' => $e->getMessage()]
            );
            return null;
        }
    }//end update()

    /**
     * Delete a meeting object from OpenRegister.
     *
     * @param string $meetingId UUID of the meeting to delete
     *
     * @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-1.3
     *
     * @return bool True on success, false on failure
     */
    public function delete(string $meetingId): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $objectService->deleteFromId(id: $meetingId);

            $this->logger->info(
                'Decidesk: meeting deleted',
                ['id' => $meetingId]
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: meeting deletion failed',
                ['id' => $meetingId, 'exception' => $e->getMessage()]
            );
            return false;
        }
    }//end delete()

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
