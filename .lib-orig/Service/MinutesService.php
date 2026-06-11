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
            $objectService       = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $notificationService = $this->container->get('OpenRegisterNotificationService');

            // Fetch Minutes.
            $minutesEntity = $objectService->find(id: $minutesId, register: 'decidesk', schema: 'minutes');
            $minutes       = null;
            if ($minutesEntity !== null) {
                $minutes = $minutesEntity->jsonSerialize();
            }

            if ($minutes === null) {
                $this->logger->warning("Minutes not found: $minutesId");
                return 0;
            }

            // Get linked Meeting.
            $meetingId = null;
            if (empty($minutes['relations']['Meeting']) === false) {
                $meetingRels = $minutes['relations']['Meeting'];
                $meetingId   = $meetingRels;
                if (is_array($meetingRels) === true) {
                    $meetingId = $meetingRels[0];
                }
            }

            // Get GovernanceBody from Meeting.
            $bodyId = null;
            if ($meetingId !== null) {
                $meetingEntity = $objectService->find(id: $meetingId, register: 'decidesk', schema: 'meeting');
                $meeting       = null;
                if ($meetingEntity !== null) {
                    $meeting = $meetingEntity->jsonSerialize();
                }

                if ($meeting !== null && empty($meeting['relations']['GovernanceBody']) === false) {
                    $bodyRels = $meeting['relations']['GovernanceBody'];
                    $bodyId   = $bodyRels;
                    if (is_array($bodyRels) === true) {
                        $bodyId = $bodyRels[0];
                    }
                }
            }

            if (empty($bodyId) === true) {
                $this->logger->info("No GovernanceBody linked to Minutes $minutesId");
                return 0;
            }

            // Query Memberships with chair/secretary roles.
            $params = [
                'role'   => ['chair', 'secretary'],
                '_limit' => 999,
            ];

            $objectService->setRegister('decidesk');
            $objectService->setSchema('participant');
            $membershipEntities = $objectService->findAll(['filters' => $params]);

            $userManager = $this->container->get(\OCP\IUserManager::class);
            $sentCount   = 0;
            foreach ($membershipEntities as $membershipEntity) {
                $membership = $membershipEntity->jsonSerialize();
                $ncUid      = $membership['nextcloudUserId'] ?? null;
                if (empty($ncUid) === true) {
                    $displayName = $membership['displayName'] ?? null;
                    if (empty($displayName) === false) {
                        $users = $userManager->search(pattern: $displayName, limit: 1);
                        if (empty($users) === false) {
                            $ncUid = array_values($users)[0]->getUID();
                        }
                    }
                }

                if (empty($ncUid) === true) {
                    $memberName = $membership['displayName'] ?? '?';
                    $this->logger->warning('MinutesService: cannot resolve Nextcloud UID', ['displayName' => $memberName]);
                    continue;
                }

                try {
                    $notificationService->sendNotification(
                        userId: $ncUid,
                        title: "Notulen ter goedkeuring: ".($minutes['title'] ?? 'Untitled'),
                        message: "De notulen zijn ter goedkeuring ingediend.",
                        deepLink: "/minutes/$minutesId"
                    );
                    $sentCount++;
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to send approval notification: ".$e->getMessage());
                }
            }//end foreach

            return $sentCount;
        } catch (\Exception $e) {
            $this->logger->error("MinutesService::notifyApproversOnSubmit failed: ".$e->getMessage());
            return 0;
        }//end try
    }//end notifyApproversOnSubmit()
}//end class
