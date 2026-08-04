<?php

/**
 * Decidesk Minutes Service
 *
 * Service for Minutes-specific operations including approval notifications.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Exception;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stateless service for Minutes operations.
 *
 * Handles approval notifications and other minutes-specific workflows.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6
 */
class MinutesService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send approval notifications when Minutes are submitted for approval.
     *
     * Resolves the linked GovernanceBody, fetches chair and secretary Memberships,
     * and sends Nextcloud notifications to each.
     *
     * @param string $minutesId The Minutes ID
     * @param string $actorId   The actor ID (user submitting for approval)
     *
     * @return int The count of notifications sent
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.1
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $actorId reserved for future audit-log enrichment.
     */
    public function notifyApproversOnSubmit(string $minutesId, string $actorId): int
    {
        try {
            return $this->notifyApprovers(minutesId: $minutesId);
        } catch (Exception $e) {
            $this->logger->error("MinutesService::notifyApproversOnSubmit failed: ".$e->getMessage());
            return 0;
        }

    }//end notifyApproversOnSubmit()

    /**
     * Resolve the approvers and notify them (the body of notifyApproversOnSubmit).
     *
     * @param string $minutesId The Minutes ID
     *
     * @return int The count of notifications sent
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.1
     */
    private function notifyApprovers(string $minutesId): int
    {
        $objectService       = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $notificationService = $this->container->get('OpenRegisterNotificationService');

        $minutes = $this->loadObject(objectService: $objectService, objectId: $minutesId, schema: 'minutes');
        if ($minutes === null) {
            $this->logger->warning("Minutes not found: $minutesId");
            return 0;
        }

        $bodyId = $this->governanceBodyIdForMinutes(objectService: $objectService, minutes: $minutes);
        if ($bodyId === null) {
            $this->logger->info("No GovernanceBody linked to Minutes $minutesId");
            return 0;
        }

        // Query Memberships with chair/secretary roles.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('participant');
        $membershipEntities = $objectService->findAll(
            [
                'filters' => [
                    'role'   => ['chair', 'secretary'],
                    '_limit' => 999,
                ],
            ]
        );

        $userManager = $this->container->get(IUserManager::class);
        $sentCount   = 0;
        foreach ($membershipEntities as $membershipEntity) {
            $membership = $membershipEntity->jsonSerialize();
            $ncUid      = $this->resolveApproverUid(userManager: $userManager, membership: $membership);
            if ($ncUid === null) {
                $this->logger->warning(
                    'MinutesService: cannot resolve Nextcloud UID',
                    ['displayName' => ($membership['displayName'] ?? '?')]
                );
                continue;
            }

            $sentCount += $this->notifyApprover(
                notificationService: $notificationService,
                ncUid: $ncUid,
                minutes: $minutes,
                minutesId: $minutesId
            );
        }//end foreach

        return $sentCount;

    }//end notifyApprovers()

    /**
     * Resolve the GovernanceBody behind a Minutes object, via its Meeting.
     *
     * @param object              $objectService The OpenRegister ObjectService
     * @param array<string,mixed> $minutes       The Minutes object
     *
     * @return string|null The GovernanceBody ID, or null when it cannot be resolved.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.1
     */
    private function governanceBodyIdForMinutes(object $objectService, array $minutes): ?string
    {
        $meetingId = $this->relatedId(object: $minutes, relationKey: 'Meeting');
        if ($meetingId === null) {
            return null;
        }

        $meeting = $this->loadObject(objectService: $objectService, objectId: $meetingId, schema: 'meeting');
        if ($meeting === null) {
            return null;
        }

        return $this->relatedId(object: $meeting, relationKey: 'GovernanceBody');

    }//end governanceBodyIdForMinutes()

    /**
     * Resolve the Nextcloud UID of one approver.
     *
     * Prefers the stored nextcloudUserId and falls back to a display-name search.
     *
     * @param object              $userManager The Nextcloud user manager
     * @param array<string,mixed> $membership  The Participant object
     *
     * @return string|null The Nextcloud UID, or null when it cannot be resolved.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.1
     */
    private function resolveApproverUid(object $userManager, array $membership): ?string
    {
        $ncUid = ($membership['nextcloudUserId'] ?? null);
        if (empty($ncUid) === false) {
            return (string) $ncUid;
        }

        $displayName = ($membership['displayName'] ?? null);
        if (empty($displayName) === true) {
            return null;
        }

        $users = $userManager->search(pattern: $displayName, limit: 1);
        if (empty($users) === true) {
            return null;
        }

        return (string) array_values($users)[0]->getUID();

    }//end resolveApproverUid()

    /**
     * Send the approval-request notification to one approver (fail-soft).
     *
     * @param object              $notificationService The OpenRegister notification service
     * @param string              $ncUid               The recipient's Nextcloud UID
     * @param array<string,mixed> $minutes             The Minutes object
     * @param string              $minutesId           The Minutes ID
     *
     * @return int 1 when the notification was sent, 0 when it failed.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.1
     */
    private function notifyApprover(
        object $notificationService,
        string $ncUid,
        array $minutes,
        string $minutesId,
    ): int {
        try {
            $notificationService->sendNotification(
                userId: $ncUid,
                title: 'Notulen ter goedkeuring: '.($minutes['title'] ?? 'Untitled'),
                message: 'De notulen zijn ter goedkeuring ingediend.',
                deepLink: "/minutes/$minutesId"
            );

            return 1;
        } catch (Exception $e) {
            $this->logger->warning("Failed to send approval notification: ".$e->getMessage());

            return 0;
        }

    }//end notifyApprover()

    /**
     * Pick a single related object id out of a relations map.
     *
     * Handles both the scalar and the list shape OpenRegister returns.
     *
     * @param array<string,mixed> $object      The object carrying the relations
     * @param string              $relationKey The relation key, e.g. 'Meeting'
     *
     * @return string|null The related id, or null when absent.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.1
     */
    private function relatedId(array $object, string $relationKey): ?string
    {
        $related = ($object['relations'][$relationKey] ?? null);
        if (is_array($related) === true) {
            $related = ($related[0] ?? null);
        }

        if (empty($related) === true) {
            return null;
        }

        return (string) $related;

    }//end relatedId()

    /**
     * Load a decidesk object as an array.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param string $objectId      The object id
     * @param string $schema        The schema slug
     *
     * @return array<string,mixed>|null The object, or null when absent.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6.1
     */
    private function loadObject(object $objectService, string $objectId, string $schema): ?array
    {
        $entity = $objectService->find(id: $objectId, register: 'decidesk', schema: $schema);
        if ($entity === null) {
            return null;
        }

        return $entity->jsonSerialize();

    }//end loadObject()
}//end class
