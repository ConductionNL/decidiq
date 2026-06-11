<?php
/**
 * Decidesk Notification Preference Service
 *
 * Stateless service handling per-person notification preferences. Stores
 * preferences as OpenRegister objects (one per Person) so other services
 * can query them server-side before dispatching alerts.
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
 * @spec openspec/changes/p4-collaboration/tasks.md#task-7
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for reading, updating, and consulting NotificationPreference objects.
 *
 * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
 */
class NotificationPreferenceService
{

    /**
     * Default preferences when no record exists for a person.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'meetingCreated'    => true,
        'votingOpened'      => true,
        'decisionPublished' => true,
        'taskAssigned'      => true,
        'commentMention'    => true,
        'deliveryMethod'    => 'in-app',
    ];

    /**
     * Construct the NotificationPreferenceService.
     *
     * @param ContainerInterface $container DI container (lazy-loads OR services)
     * @param LoggerInterface    $logger    Logger interface
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end getObjectService()

    /**
     * Find a NotificationPreference object for a person.
     *
     * @param string $personId Person UUID or user ID
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
     */
    public function findPreference(string $personId): ?array
    {
        try {
            $objectService = $this->getObjectService();
            $objectService->setRegister('decidesk');
            $objectService->setSchema('notification-preference');

            $results = $objectService->findAll(
                limit: 1,
                offset: 0,
                filters: ['person' => $personId],
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk: findPreference failed',
                ['personId' => $personId, 'error' => $e->getMessage()]
            );
            return null;
        }

        foreach ($results as $entity) {
            if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
                return $entity->getObject();
            }

            if (is_array($entity) === true) {
                return $entity;
            }
        }

        return null;

    }//end findPreference()

    /**
     * Create or update a NotificationPreference object for a person.
     *
     * @param string               $personId    Person UUID or user ID
     * @param array<string, mixed> $preferences Preference fields
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
     */
    public function updatePreference(string $personId, array $preferences): array
    {
        $existing         = $this->findPreference(personId: $personId);
        $merged           = array_merge(self::DEFAULTS, ($existing ?? []), $preferences);
        $merged['person'] = $personId;

        $objectService = $this->getObjectService();
        $saved         = $objectService->saveObject(
            object: $merged,
            register: 'decidesk',
            schema: 'notification-preference',
        );

        $this->logger->info('Decidesk: NotificationPreference updated', ['personId' => $personId]);

        if (is_array($saved) === true) {
            return $saved;
        }

        if (is_object($saved) === true && method_exists($saved, 'getObject') === true) {
            return (array) $saved->getObject();
        }

        return (array) $saved;

    }//end updatePreference()

    /**
     * Create a NotificationPreference object for a person (alias).
     *
     * @param string               $personId    Person UUID or user ID
     * @param array<string, mixed> $preferences Preference fields
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
     */
    public function createPreference(string $personId, array $preferences): array
    {
        return $this->updatePreference(personId: $personId, preferences: $preferences);

    }//end createPreference()

    /**
     * Determine if a given event type should produce a notification for the person.
     *
     * @param string $personId  Person UUID or user ID
     * @param string $eventType One of: meetingCreated, votingOpened, decisionPublished,
     *                          taskAssigned, commentMention
     *
     * @return bool
     *
     * @spec openspec/changes/p4-collaboration/tasks.md#task-7.1
     */
    public function shouldNotify(string $personId, string $eventType): bool
    {
        $pref   = $this->findPreference(personId: $personId);
        $merged = array_merge(self::DEFAULTS, ($pref ?? []));

        return (bool) ($merged[$eventType] ?? false);

    }//end shouldNotify()
}//end class
