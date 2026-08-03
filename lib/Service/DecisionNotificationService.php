<?php

/**
 * Decidesk Decision Notification Service
 *
 * Service for sending notifications when decisions are published.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5
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
 * Stateless service that sends notifications when decisions are published.
 *
 * Resolves recipients from Memberships with configurable roles (default:
 * chair, secretary, member) and sends Nextcloud notifications via NotificationService.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5
 */
class DecisionNotificationService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send notifications when a Decision is published.
     *
     * Fetches the Decision and linked GovernanceBody, resolves recipients with
     * configured roles (from IAppConfig), and sends Nextcloud notifications.
     *
     * @param string $decisionId The Decision ID
     *
     * @return int The count of notifications sent
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.1
     * @spec openspec/specs/user-settings/spec.md
     */
    public function notifyOnPublish(string $decisionId): int
    {
        try {
            $objectService       = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $appConfig           = $this->container->get('IAppConfig');
            $notificationService = $this->container->get('OpenRegisterNotificationService');

            // Fetch Decision.
            $decisionEntity = $objectService->find(id: $decisionId, register: 'decidesk', schema: 'decision');
            $decision       = null;
            if ($decisionEntity !== null) {
                $decision = $decisionEntity->jsonSerialize();
            }

            if ($decision === null) {
                $this->logger->warning("Decision not found: $decisionId");
                return 0;
            }

            // Get configured roles.
            $roles = $appConfig->getValueArray(
                'decidesk',
                'decision_notify_roles',
                ['chair', 'secretary', 'member']
            );

            // Get GovernanceBody from Decision relations.
            $bodyId = $this->resolveBodyId(decision: $decision);
            if (empty($bodyId) === true) {
                $this->logger->info("No GovernanceBody linked to Decision $decisionId");
                return 0;
            }

            // Resolve recipients (display names) and map to Nextcloud UIDs.
            $displayNames = $this->resolveRecipients(decisionId: $decisionId, roles: $roles);
            $userManager  = $this->container->get(\OCP\IUserManager::class);

            $sentCount = 0;
            foreach ($displayNames as $displayName) {
                $ncUid = $this->resolveNextcloudUid(userManager: $userManager, displayName: $displayName);
                if ($ncUid === null) {
                    continue;
                }

                $sentCount += $this->deliver(
                    notificationService: $notificationService,
                    ncUid: $ncUid,
                    decision: $decision,
                    decisionId: $decisionId
                );
            }//end foreach

            return $sentCount;
        } catch (\Exception $e) {
            $this->logger->error("DecisionNotificationService::notifyOnPublish failed: ".$e->getMessage());
            return 0;
        }//end try
    }//end notifyOnPublish()

    /**
     * Read the linked GovernanceBody reference off a Decision.
     *
     * The relation is stored either as a bare id or as a list; the first entry
     * of a list wins. Returns null when no body is linked.
     *
     * @param mixed $decision The serialized Decision payload
     *
     * @return mixed The GovernanceBody reference, or null when none is linked
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.1
     */
    private function resolveBodyId(mixed $decision): mixed
    {
        if (empty($decision['relations']['GovernanceBody']) === true) {
            return null;
        }

        $bodyRels = $decision['relations']['GovernanceBody'];
        if (is_array($bodyRels) === true) {
            return ($bodyRels[0] ?? null);
        }

        return $bodyRels;

    }//end resolveBodyId()

    /**
     * Map a recipient display name to a Nextcloud UID.
     *
     * @param object $userManager Nextcloud IUserManager instance
     * @param mixed  $displayName The recipient's display name
     *
     * @return string|null The Nextcloud UID, or null when it cannot be resolved
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.1
     */
    private function resolveNextcloudUid(object $userManager, mixed $displayName): ?string
    {
        $users = $userManager->search(pattern: $displayName, limit: 1);
        $ncUid = null;
        if (empty($users) === false) {
            $ncUid = array_values($users)[0]->getUID();
        }

        if (empty($ncUid) === true) {
            $this->logger->warning(
                'DecisionNotificationService: cannot resolve Nextcloud UID',
                ['displayName' => $displayName]
            );
            return null;
        }

        return $ncUid;

    }//end resolveNextcloudUid()

    /**
     * Deliver the publication notification to one recipient.
     *
     * Preference-aware dispatch (user-settings spec): honours the recipient's
     * decisionPublished toggle, fans out to their active absence delegate, and
     * selects in-app and/or email per their deliveryMethod. Falls back to the
     * previous unconditional in-app send when the preference service is
     * unavailable (e.g. partial container in unit tests).
     *
     * @param object $notificationService OpenRegister notification service
     * @param string $ncUid               Recipient's Nextcloud UID
     * @param mixed  $decision            The serialized Decision payload
     * @param string $decisionId          The Decision ID
     *
     * @return int The number of notifications sent for this recipient
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.1
     * @spec openspec/specs/user-settings/spec.md
     */
    private function deliver(object $notificationService, string $ncUid, mixed $decision, string $decisionId): int
    {
        $title    = "Besluit gepubliceerd: ".($decision['title'] ?? 'Untitled');
        $message  = "Outcome: ".($decision['outcome'] ?? 'pending');
        $deepLink = "/decisions/$decisionId";

        $prefService = $this->resolvePreferenceService();
        if ($prefService !== null) {
            return $prefService->dispatch(
                personId: $ncUid,
                eventType: 'decisionPublished',
                title: $title,
                message: $message,
                deepLink: $deepLink
            );
        }

        try {
            $notificationService->sendNotification(
                userId: $ncUid,
                title: $title,
                message: $message,
                deepLink: $deepLink
            );
            return 1;
        } catch (\Exception $e) {
            $this->logger->warning("Failed to send notification: ".$e->getMessage());
            return 0;
        }//end try

    }//end deliver()

    /**
     * Resolve the notification-preference service, or null when the container
     * cannot provide it (partial container in unit tests).
     *
     * @return NotificationPreferenceService|null
     *
     * @spec openspec/specs/user-settings/spec.md
     */
    private function resolvePreferenceService(): ?NotificationPreferenceService
    {
        try {
            $candidate = $this->container->get(NotificationPreferenceService::class);
            if ($candidate instanceof NotificationPreferenceService === true) {
                return $candidate;
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'DecisionNotificationService: preference service unavailable',
                ['error' => $e->getMessage()]
            );
        }//end try

        return null;

    }//end resolvePreferenceService()

    /**
     * Resolve recipient user display names from Memberships.
     *
     * Queries Memberships filtered by role and returns user display names.
     *
     * @param string $decisionId The Decision ID
     * @param array  $roles      Roles to include (e.g., ['chair', 'secretary'])
     *
     * @return array<string> Array of user display names
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5.1
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $decisionId reserved for future per-decision recipient scoping.
     */
    public function resolveRecipients(string $decisionId, array $roles=[]): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            if (empty($roles) === true) {
                $roles = ['chair', 'secretary', 'member'];
            }

            // Query Memberships with role filters.
            $params = [
                'role'   => $roles,
                '_limit' => 999,
            ];

            $objectService->setRegister('decidesk');
            $objectService->setSchema('participant');
            $membershipEntities = $objectService->findAll(['filters' => $params]);

            $recipients = [];
            foreach ($membershipEntities as $membershipEntity) {
                $membership  = $membershipEntity->jsonSerialize();
                $displayName = $membership['displayName'] ?? null;
                if (empty($displayName) === false) {
                    $recipients[] = $displayName;
                }
            }

            return array_unique($recipients);
        } catch (\Exception $e) {
            $this->logger->error("DecisionNotificationService::resolveRecipients failed: ".$e->getMessage());
            return [];
        }//end try
    }//end resolveRecipients()
}//end class
