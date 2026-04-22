<?php

/**
 * Decidesk Decision Auto Record Service
 *
 * Service for automatically creating Decision records when Motions are adopted.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for auto-creating Decision records from adopted Motions (idempotent).
 *
 * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
 */
class DecisionAutoRecordService
{
    /**
     * Construct the DecisionAutoRecordService.
     *
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the ObjectService from the container.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()

    /**
     * Get the NotificationService from the container.
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
     *
     * @return object|null
     */
    private function getNotificationService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\NotificationService');
        } catch (\Throwable) {
            return null;
        }
    }//end getNotificationService()

    /**
     * Auto-create a Decision record from an adopted Motion (idempotent).
     *
     * If a Decision linked to this Motion already exists, returns the existing UUID.
     * Otherwise, creates a new Decision with fields from the Motion and links them.
     *
     * @param string $motionId UUID of adopted Motion
     *
     * @return string|null UUID of created or existing Decision
     *
     * @throws \RuntimeException If Motion not found
     *
     * @spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-3
     */
    public function createFromAdoptedMotion(string $motionId): ?string
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('Motion');

        $motion = $objectService->find($motionId);
        if ($motion === null) {
            throw new \RuntimeException("Motion $motionId not found");
        }

        $motionArray = $motion->getObject();

        $objectService->setSchema('Decision');

        $existingDecisions = $objectService->findAll(
            params: ['relations' => ['label' => 'source-motion', 'targetId' => $motionId]],
        );

        if (empty($existingDecisions) === false) {
            $existingId = $existingDecisions[0]['@self']['id'] ?? null;
            $this->logger->info(
                "Decision already exists for motion $motionId — skipping auto-creation (idempotent). UUID: $existingId"
            );
            return $existingId;
        }

        $decisionText = $motionArray['decisionText'] ?? '';
        if (empty($decisionText) === true) {
            $decisionText = $motionArray['text'] ?? '';
        }

        $newDecision = [
            'title'        => $motionArray['title'] ?? '',
            'text'         => $decisionText,
            'decisionDate' => date(\DateTime::ATOM),
            'outcome'      => 'adopted',
            'lifecycle'    => 'draft',
            'legalBasis'   => $motionArray['legalBasis'] ?? '',
        ];

        $createdDecision = $objectService->saveObject(
            object: $newDecision,
            register: 'decidesk',
            schema: 'Decision',
        );

        $createdId = $createdDecision['@self']['id'] ?? null;

        if ($createdId !== null && method_exists($objectService, 'createRelation') === true) {
            $objectService->createRelation(
                sourceId: $createdId,
                targetId: $motionId,
                label: 'source-motion',
                sourceRegister: 'decidesk',
                sourceSchema: 'Decision',
                targetRegister: 'decidesk',
                targetSchema: 'Motion',
            );
        }

        $this->logger->info(
            "Decision auto-created from Motion $motionId. New Decision UUID: $createdId"
        );

        $notificationService = $this->getNotificationService();
        if ($notificationService !== null && method_exists($notificationService, 'notify') === true) {
            $title = $motionArray['title'] ?? 'Motion';
            $notificationService->notify(
                recipients: [],
                title: 'Decision Auto-Created',
                message: "Decision record created from Motion: $title — review and start approval workflow",
            );
        }

        return $createdId;
    }//end createFromAdoptedMotion()
}//end class
