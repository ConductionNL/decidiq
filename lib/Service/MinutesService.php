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
    }

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
     */
    public function notifyApproversOnSubmit(string $minutesId, string $actorId): int
    {
        try {
            $objectService = $this->container->get('OpenRegisterObjectService');
            $notificationService = $this->container->get('OpenRegisterNotificationService');

            // Fetch Minutes
            $minutes = $objectService->findObject(
                register: 'decidesk',
                schema: 'Minutes',
                id: $minutesId
            );

            if ($minutes === null) {
                $this->logger->warning("Minutes not found: $minutesId");
                return 0;
            }

            // Get linked Meeting
            $meetingId = null;
            if (!empty($minutes['relations']['Meeting'])) {
                $meetingRels = $minutes['relations']['Meeting'];
                $meetingId = is_array($meetingRels) ? $meetingRels[0] : $meetingRels;
            }

            // Get GovernanceBody from Meeting
            $bodyId = null;
            if ($meetingId) {
                $meeting = $objectService->findObject(
                    register: 'decidesk',
                    schema: 'Meeting',
                    id: $meetingId
                );

                if ($meeting && !empty($meeting['relations']['GovernanceBody'])) {
                    $bodyRels = $meeting['relations']['GovernanceBody'];
                    $bodyId = is_array($bodyRels) ? $bodyRels[0] : $bodyRels;
                }
            }

            if (empty($bodyId)) {
                $this->logger->info("No GovernanceBody linked to Minutes $minutesId");
                return 0;
            }

            // Query Memberships with chair/secretary roles
            $params = [
                'role' => ['chair', 'secretary'],
                '_limit' => 999,
            ];

            $memberships = $objectService->findObjects(
                register: 'decidesk',
                schema: 'Membership',
                params: $params
            );

            $sentCount = 0;
            foreach ($memberships as $membership) {
                $displayName = $membership['displayName'] ?? null;
                if (empty($displayName)) {
                    continue;
                }

                try {
                    $notificationService->sendNotification(
                        userId: $displayName,
                        title: "Notulen ter goedkeuring: " . ($minutes['title'] ?? 'Untitled'),
                        message: "De notulen zijn ter goedkeuring ingediend.",
                        deepLink: "/minutes/$minutesId"
                    );
                    $sentCount++;
                } catch (\Exception $e) {
                    $this->logger->warning("Failed to send approval notification: " . $e->getMessage());
                }
            }

            return $sentCount;
        } catch (\Exception $e) {
            $this->logger->error("MinutesService::notifyApproversOnSubmit failed: " . $e->getMessage());
            return 0;
        }
    }
}
