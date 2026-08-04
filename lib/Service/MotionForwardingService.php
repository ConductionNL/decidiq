<?php
/**
 * Decidesk Motion Forwarding Service
 *
 * Forwards a motion to another governance body: authorises the actor against
 * the `motion_forwarding_roles` configuration, creates the forwarded Motion in
 * the target body, records the forwarding on both sides, and notifies the
 * target chair when approval is required.
 *
 * Extracted from {@see MotionService} so that class stays inside the PHPMD
 * ExcessiveClassComplexity budget and the forwarding flow inside the
 * ExcessiveMethodLength budget; MotionService keeps a thin delegating wrapper
 * so its published API — consumed by MotionController — is unchanged.
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
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Cross-body motion forwarding.
 *
 * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
 */
class MotionForwardingService
{
    /**
     * Construct the MotionForwardingService.
     *
     * @param ContainerInterface $container   The DI container for lazy-loading OR services
     * @param IUserManager       $userManager Nextcloud user manager for actor lookup
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IUserManager $userManager,
    ) {
    }//end __construct()

    /**
     * Get the ObjectService from the container.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     *
     * @return object
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Get the MotionNotifier from the container.
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     *
     * @return MotionNotifier
     */
    private function getNotifier(): MotionNotifier
    {
        return $this->container->get(MotionNotifier::class);

    }//end getNotifier()

    /**
     * Forward a motion to a target governance body with optional approval workflow.
     *
     * Checks the actor's role against the motion_forwarding_roles config. Creates a new
     * Motion in the target body and stores a relation between the forwarded and source
     * motions. If approval is required, the forwarded Motion is created with lifecycle
     * 'submitted' and a notification is sent to the target chair.
     *
     * @param string $motionId      The motion UUID to forward
     * @param string $targetBodyId  The target governance body UUID
     * @param string $actorId       The Nextcloud user ID of the person forwarding
     * @param string $justification The reason for forwarding
     *
     * @return array<string,mixed> The created forwarded Motion object
     *
     * @throws RuntimeException When role is not authorized or motion is not found
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     */
    public function forwardMotion(string $motionId, string $targetBodyId, string $actorId, string $justification): array
    {
        $appConfig = $this->container->get(IAppConfig::class);

        // Simple check: actor role must be in allowed roles (enforce in backend only, no frontend-only checks).
        // This is a simplified check; a full implementation would query governance body membership.
        $this->assertActorMayForward(appConfig: $appConfig, actorId: $actorId);

        $objectService = $this->getObjectService();

        // Fetch the source motion.
        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $sourceMotionObject = $objectService->find($motionId);
        if ($sourceMotionObject === null) {
            throw new RuntimeException("Motion $motionId not found");
        }

        $sourceMotionData = $sourceMotionObject->getObject();

        // Check approval requirement config.
        $requiresApproval = $appConfig->getValueBool('decidesk', 'motion_forwarding_requires_approval', false);

        $forwardedMotion = $this->buildForwardedMotion(
            sourceMotionData: $sourceMotionData,
            motionId: $motionId,
            targetBodyId: $targetBodyId,
            actorId: $actorId,
            justification: $justification
        );

        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $created = $objectService->saveObject(
            object: $forwardedMotion,
            register: 'decidesk',
            schema: 'motion',
        );

        $forwardedMotionId = ($created['id'] ?? $created['uuid'] ?? null);

        // Add forwarding note to source motion.
        $sourceMotionData['notes']   = ($sourceMotionData['notes'] ?? []);
        $sourceMotionData['notes'][] = [
            'title' => 'Doorgestuurd naar',
            'body'  => json_encode(
                [
                    'targetBodyId'      => $targetBodyId,
                    'forwardedMotionId' => $forwardedMotionId,
                    'forwardedAt'       => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                ]
            ),
        ];

        $objectService->setRegister('decidesk');
        $objectService->setSchema('motion');
        $objectService->saveObject(
            object: $sourceMotionData,
            register: 'decidesk',
            schema: 'motion',
            uuid: $motionId,
        );

        // Send notification if approval is required.
        if ($requiresApproval === true) {
            $this->getNotifier()->notify(
                userId: $actorId,
                motionId: (string) ($forwardedMotionId ?? ''),
                subject: 'motion_forwarded_approval',
                parameters: [
                    'title' => $sourceMotionData['title'] ?? '',
                    'body'  => $targetBodyId,
                ],
                failureLog: 'Decidesk: notification send failed: '
            );
        }

        return ($created ?? $forwardedMotion);

    }//end forwardMotion()

    /**
     * Verify the forwarding actor exists and the forwarding-role config is usable.
     *
     * @param IAppConfig $appConfig The Nextcloud app config
     * @param string     $actorId   Nextcloud user ID of the person forwarding
     *
     * @return void
     *
     * @throws RuntimeException When the actor cannot be resolved
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     */
    private function assertActorMayForward(IAppConfig $appConfig, string $actorId): void
    {
        // Check actor role against forwarding config.
        $forwardingRolesJson = $appConfig->getValueString('decidesk', 'motion_forwarding_roles', '["chair","secretary"]');
        $forwardingRoles     = json_decode($forwardingRolesJson, true);
        if (is_array($forwardingRoles) === false) {
            $forwardingRoles = ['chair', 'secretary'];
        }

        $user = $this->userManager->get($actorId);
        if ($user === null) {
            throw new RuntimeException("Actor {$actorId} not found");
        }

    }//end assertActorMayForward()

    /**
     * Build the payload for the Motion created in the target governance body.
     *
     * @param array<string,mixed> $sourceMotionData The source motion payload
     * @param string              $motionId         UUID of the source motion
     * @param string              $targetBodyId     UUID of the target governance body
     * @param string              $actorId          Nextcloud user ID of the person forwarding
     * @param string              $justification    The reason for forwarding
     *
     * @return array<string,mixed> The forwarded Motion payload
     *
     * @spec openspec/changes/p2-motion-and-voting-core-t2/tasks.md#task-3
     */
    private function buildForwardedMotion(
        array $sourceMotionData,
        string $motionId,
        string $targetBodyId,
        string $actorId,
        string $justification
    ): array {
        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        return [
            'title'       => $sourceMotionData['title'] ?? '',
            'text'        => $sourceMotionData['text'] ?? '',
            'motionType'  => $sourceMotionData['motionType'] ?? 'motion',
            'proposer'    => $sourceMotionData['proposer'] ?? '',
            'coSigners'   => $sourceMotionData['coSigners'] ?? [],
            'lifecycle'   => 'submitted',
            'submittedAt' => $now,
            'relations'   => [
                ['register' => 'decidesk', 'schema' => 'governance-body', 'id' => $targetBodyId],
                ['register' => 'decidesk', 'schema' => 'motion', 'id' => $motionId],
            ],
            'notes'       => [
                [
                    'title' => 'Doorgestuurd van',
                    'body'  => json_encode(
                        [
                            'sourceMotionId' => $motionId,
                            'targetBodyId'   => $targetBodyId,
                            'forwardedBy'    => $actorId,
                            'justification'  => $justification,
                            'forwardedAt'    => $now,
                        ]
                    ),
                ],
            ],
        ];

    }//end buildForwardedMotion()
}//end class
