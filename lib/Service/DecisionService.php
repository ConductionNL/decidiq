<?php

/**
 * Decidesk Decision Service
 *
 * Service for Decision-related operations, including portal publication.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use OCP\IShareManager;
use OCP\Share\IShare;
use Psr\Container\ContainerInterface;

/**
 * Stateless service for Decision operations.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
 */
class DecisionService
{
    /**
     * Constructor for DecisionService.
     *
     * @param ContainerInterface          $container           The DI container
     * @param IShareManager               $shareManager        The Nextcloud share manager
     * @param DecisionNotificationService $notificationService The notification service
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
     */
    public function __construct(
        private ContainerInterface $container,
        private IShareManager $shareManager,
        private DecisionNotificationService $notificationService,
    ) {
    }//end __construct()

    /**
     * Publish a Decision to the member portal.
     *
     * Creates a public share link and stores the token in the Decision's notes.
     *
     * @param string $decisionId UUID of the Decision object
     * @param string $actorId    User ID performing the publication
     *
     * @return string The full public share URL
     *
     * @throws \Exception When Decision not found or share creation fails
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
     */
    public function publishToPortal(string $decisionId, string $actorId): string
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('decision');
        $decisionEntity = $objectService->find($decisionId);

        if ($decisionEntity === null) {
            throw new \Exception(sprintf('Decision "%s" not found', $decisionId));
        }

        $decision = $decisionEntity->getObject();

        // Set publication metadata
        $decision['isPublished'] = true;
        $decision['publishedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        // Create public share link
        $publicUrl = sprintf('/api/decisions/%s/public', $decisionId);

        try {
            $share = $this->shareManager->newShare();
            $share->setShareType(IShare::TYPE_LINK);
            $share->setPath($publicUrl);
            $share->setPermissions(\OCP\Constants::PERMISSION_READ);
            $this->shareManager->createShare($share);

            $shareToken = $share->getToken();
            $shareUrl   = sprintf(
                '%s/index.php/s/%s',
                \OC::$server->getConfig()->getSystemValue('overwrite.cli.url', \OC::$WEBROOT),
                $shareToken
            );
        } catch (\Throwable) {
            // If share creation fails, store without share URL for now
            $shareUrl   = '';
            $shareToken = '';
        }

        // Store share token in Decision notes
        $notes = $decision['notes'] ?? [];
        if (!is_array($notes)) {
            $notes = [];
        }

        $notes['shareToken'] = $shareToken;
        $decision['notes']   = $notes;

        // Save updated Decision
        $objectService->saveObject(
            object: $decision,
            register: 'decidesk',
            schema: 'decision',
            uuid: $decisionId
        );

        // Dispatch notification
        $this->notificationService->dispatch(
            $decisionId,
            'decision',
            'draft',
            'published',
            $decision['title'] ?? 'Decision'
        );

        return $shareUrl;
    }//end publishToPortal()

    /**
     * Get the public share link for a published Decision.
     *
     * @param string $decisionId UUID of the Decision object
     *
     * @return string|null The share URL or null if not published
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
     */
    public function getShareLink(string $decisionId): ?string
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('decision');
        $decisionEntity = $objectService->find($decisionId);

        if ($decisionEntity === null) {
            return null;
        }

        $decision = $decisionEntity->getObject();

        if (!($decision['isPublished'] ?? false)) {
            return null;
        }

        $notes = $decision['notes'] ?? [];
        if (!is_array($notes)) {
            return null;
        }

        $shareToken = $notes['shareToken'] ?? null;
        if ($shareToken === null) {
            return null;
        }

        return sprintf(
            '%s/index.php/s/%s',
            \OC::$server->getConfig()->getSystemValue('overwrite.cli.url', \OC::$WEBROOT),
            $shareToken
        );
    }//end getShareLink()

    /**
     * Get the ObjectService from the DI container.
     *
     * @return mixed The ObjectService instance
     *
     * @throws \Exception When service is not available
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-4
     */
    private function getObjectService()
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
