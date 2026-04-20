<?php

/**
 * Decidesk Minutes Approval Service
 *
 * Manages Minutes approval workflow with dual sign-off and lifecycle transitions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;

/**
 * Stateless service for Minutes approval and lifecycle management.
 *
 * Handles dual sign-off (chair + secretary) and auto-advancement of lifecycle.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
 */
class MinutesApprovalService
{
    /**
     * Constructor for MinutesApprovalService.
     *
     * @param ContainerInterface          $container           The DI container
     * @param DecisionNotificationService $notificationService The notification service
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     */
    public function __construct(
        private ContainerInterface $container,
        private DecisionNotificationService $notificationService,
    ) {
    }//end __construct()

    /**
     * Add an approval for Minutes from chair or secretary.
     *
     * Auto-advances to 'approved' if both chair and secretary have approved.
     *
     * @param string $minutesId UUID of the Minutes object
     * @param string $userId    User ID adding approval
     * @param string $role      Role of approver ('chair' or 'secretary')
     *
     * @return void
     *
     * @throws \InvalidArgumentException When role is invalid or Minutes not in 'review' state
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     */
    public function addApproval(string $minutesId, string $userId, string $role): void
    {
        // Validate role
        if (!in_array($role, ['chair', 'secretary'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid role: %s', $role));
        }

        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('minutes');
        $minutesEntity = $objectService->find($minutesId);

        if ($minutesEntity === null) {
            throw new \InvalidArgumentException(sprintf('Minutes "%s" not found', $minutesId));
        }

        $minutes = $minutesEntity->getObject();

        // Validate lifecycle is 'review'
        if (($minutes['lifecycle'] ?? '') !== 'review') {
            throw new \InvalidArgumentException(
                'Minutes must be in review state to add approval'
            );
        }

        // Add approval to signedBy array
        $signedBy = $minutes['signedBy'] ?? [];
        if (!is_array($signedBy)) {
            $signedBy = [];
        }

        // Avoid duplicate approvals from same user
        $alreadyApproved = false;
        foreach ($signedBy as $sig) {
            if ($sig['userId'] === $userId && $sig['role'] === $role) {
                $alreadyApproved = true;
                break;
            }
        }

        if (!$alreadyApproved) {
            $signedBy[] = [
                'userId'   => $userId,
                'role'     => $role,
                'signedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];
        }

        $minutes['signedBy'] = $signedBy;
        $objectService->saveObject(
            object: $minutes,
            register: 'decidesk',
            schema: 'minutes',
            uuid: $minutesId
        );

        // Check if both chair and secretary have approved
        $hasChair     = false;
        $hasSecretary = false;

        foreach ($signedBy as $sig) {
            if ($sig['role'] === 'chair') {
                $hasChair = true;
            }

            if ($sig['role'] === 'secretary') {
                $hasSecretary = true;
            }
        }

        // Auto-advance to 'approved' if both have approved
        if ($hasChair && $hasSecretary) {
            $this->advance($minutesId, $userId, 'approved');
        }

        // Dispatch notification
        $this->notificationService->dispatch(
            $minutesId,
            'minutes',
            'review',
            $hasChair && $hasSecretary ? 'approved' : 'review',
            $minutes['title'] ?? 'Minutes'
        );
    }//end addApproval()

    /**
     * Advance Minutes to the next lifecycle state.
     *
     * @param string $minutesId   UUID of the Minutes object
     * @param string $userId      User ID performing the advance
     * @param string $targetState Target lifecycle state
     *
     * @return void
     *
     * @throws \InvalidArgumentException When transition is invalid
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     */
    public function advance(string $minutesId, string $userId, string $targetState): void
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('minutes');
        $minutesEntity = $objectService->find($minutesId);

        if ($minutesEntity === null) {
            throw new \InvalidArgumentException(sprintf('Minutes "%s" not found', $minutesId));
        }

        $minutes      = $minutesEntity->getObject();
        $currentState = $minutes['lifecycle'] ?? 'draft';

        // Validate transition
        $validTransitions = [
            'review'   => 'approved',
            'approved' => 'signed',
            'signed'   => 'published',
        ];

        if (($validTransitions[$currentState] ?? null) !== $targetState) {
            throw new \InvalidArgumentException(
                sprintf('Invalid transition from %s to %s', $currentState, $targetState)
            );
        }

        // Update lifecycle
        $oldState = $currentState;
        $minutes['lifecycle'] = $targetState;

        $objectService->saveObject(
            object: $minutes,
            register: 'decidesk',
            schema: 'minutes',
            uuid: $minutesId
        );

        // Dispatch notification
        $this->notificationService->dispatch(
            $minutesId,
            'minutes',
            $oldState,
            $targetState,
            $minutes['title'] ?? 'Minutes'
        );
    }//end advance()

    /**
     * Get approval status for a Minutes object.
     *
     * @param string $minutesId UUID of the Minutes object
     *
     * @return array<string, mixed> Approval status with chair/secretary flags
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     */
    public function getApprovalStatus(string $minutesId): array
    {
        $objectService = $this->getObjectService();
        $objectService->setRegister('decidesk');
        $objectService->setSchema('minutes');
        $minutesEntity = $objectService->find($minutesId);

        if ($minutesEntity === null) {
            return [
                'chairApproved'     => false,
                'chairUserId'       => null,
                'chairSignedAt'     => null,
                'secretaryApproved' => false,
                'secretaryUserId'   => null,
                'secretarySignedAt' => null,
                'approvals'         => [],
            ];
        }

        $minutes  = $minutesEntity->getObject();
        $signedBy = $minutes['signedBy'] ?? [];

        $chairApproved     = false;
        $chairUserId       = null;
        $chairSignedAt     = null;
        $secretaryApproved = false;
        $secretaryUserId   = null;
        $secretarySignedAt = null;

        foreach ($signedBy as $sig) {
            if ($sig['role'] === 'chair') {
                $chairApproved = true;
                $chairUserId   = $sig['userId'] ?? null;
                $chairSignedAt = $sig['signedAt'] ?? null;
            }

            if ($sig['role'] === 'secretary') {
                $secretaryApproved = true;
                $secretaryUserId   = $sig['userId'] ?? null;
                $secretarySignedAt = $sig['signedAt'] ?? null;
            }
        }

        return [
            'chairApproved'     => $chairApproved,
            'chairUserId'       => $chairUserId,
            'chairSignedAt'     => $chairSignedAt,
            'secretaryApproved' => $secretaryApproved,
            'secretaryUserId'   => $secretaryUserId,
            'secretarySignedAt' => $secretarySignedAt,
            'approvals'         => $signedBy,
        ];
    }//end getApprovalStatus()

    /**
     * Get the ObjectService from the DI container.
     *
     * @return mixed The ObjectService instance
     *
     * @throws \Exception When service is not available
     *
     * @spec openspec/changes/p2-minutes-and-decisions-core-t2/tasks.md#task-3
     */
    private function getObjectService()
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
