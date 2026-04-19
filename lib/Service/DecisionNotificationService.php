<?php

/**
 * Decidesk Decision Notification Service
 *
 * Service for notifying users when decisions are published.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for dispatching notifications when decisions are published.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5
 */
class DecisionNotificationService
{
    /**
     * Default roles to notify on decision publication.
     *
     * @var string[]
     */
    private const DEFAULT_NOTIFY_ROLES = ['chair', 'secretary', 'member'];

    /**
     * Constructor for DecisionNotificationService.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Send notifications on decision publication.
     *
     * @param string $decisionId The UUID of the Decision
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5
     *
     * @return int Count of notifications sent
     */
    public function notifyOnPublish(string $decisionId): int
    {
        try {
            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Fetch the decision.
            $decision = $objectService->find(id: $decisionId);
            if ($decision === null) {
                $this->logger->warning(
                    'Decidesk: decision not found for notification',
                    ['decisionId' => $decisionId]
                );
                return 0;
            }

            $decisionObj      = $decision->getObject();
            $governanceBodyId = $decisionObj['governanceBody'] ?? null;

            if (!$governanceBodyId) {
                $this->logger->warning(
                    'Decidesk: decision not linked to governance body',
                    ['decisionId' => $decisionId]
                );
                return 0;
            }

            // Get configured roles (from IAppConfig, or use defaults).
            $roles = $this->getConfiguredRoles();

            // Resolve recipients.
            $recipients = $this->resolveRecipients($decisionId, $roles);

            // Log the notification dispatch.
            $this->logger->info(
                'Decidesk: decision publication notification sent',
                ['decisionId' => $decisionId, 'recipientCount' => count($recipients)]
            );

            return count($recipients);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to send decision notification',
                ['decisionId' => $decisionId, 'exception' => $e->getMessage()]
            );
            return 0;
        }//end try
    }//end notifyOnPublish()

    /**
     * Resolve recipients for decision notifications based on roles.
     *
     * @param string             $decisionId The UUID of the Decision
     * @param array<int, string> $roles      Roles to filter by (chair, secretary, member, etc.)
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5
     *
     * @return array<int, array> Array of user objects or display names
     */
    public function resolveRecipients(string $decisionId, array $roles): array
    {
        try {
            /*
             * @var \OCA\OpenRegister\Service\ObjectService $objectService
             */
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            // Fetch the decision to get governance body.
            $decision = $objectService->find(id: $decisionId);
            if ($decision === null) {
                return [];
            }

            $decisionObj      = $decision->getObject();
            $governanceBodyId = $decisionObj['governanceBody'] ?? null;

            if (!$governanceBodyId) {
                return [];
            }

            // Query Memberships with matching roles.
            $recipients = [];
            $seenUids   = [];

            foreach ($roles as $role) {
                $params = [
                    'governanceBody' => $governanceBodyId,
                    'role'           => $role,
                    'leftAt'         => null,
                // Active memberships only.
                    '_limit'         => 1000,
                ];

                try {
                    $membershipResponse = $objectService->findAll(
                        register: 'decidesk',
                        schema: 'Membership',
                        params: $params
                    );

                    $memberships = $membershipResponse['results'] ?? [];

                    foreach ($memberships as $membership) {
                        $userId = $membership['user'] ?? null;

                        if ($userId && !isset($seenUids[$userId])) {
                            $seenUids[$userId] = true;
                            $recipients[]      = [
                                'userId' => $userId,
                                'role'   => $role,
                            ];
                        }
                    }
                } catch (\Throwable) {
                    // Skip roles with query errors.
                }//end try
            }//end foreach

            return $recipients;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Decidesk: failed to resolve decision notification recipients',
                ['decisionId' => $decisionId, 'exception' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end resolveRecipients()

    /**
     * Get configured roles for decision notifications from app config.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-5
     *
     * @return array<int, string>
     */
    private function getConfiguredRoles(): array
    {
        try {
            /*
             * @var \OCP\IAppConfig $appConfig
             */
            $appConfig = $this->container->get('OCP\IAppConfig');

            $configured = $appConfig->getValueString(
                app: 'decidesk',
                key: 'decision_notify_roles',
                default: ''
            );

            if (empty($configured)) {
                return self::DEFAULT_NOTIFY_ROLES;
            }

            $roles = json_decode($configured, true);
            return is_array($roles) ? $roles : self::DEFAULT_NOTIFY_ROLES;
        } catch (\Throwable) {
            return self::DEFAULT_NOTIFY_ROLES;
        }//end try
    }//end getConfiguredRoles()
}//end class
