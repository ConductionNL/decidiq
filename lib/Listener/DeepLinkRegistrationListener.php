<?php
/**
 * Decidesk DeepLinkRegistrationListener
 *
 * Registers Decidesk's deep link URL patterns with OpenRegister's search provider.
 *
 * @category Listener
 * @package  OCA\Decidesk\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\Decidesk\Listener;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers Decidesk's deep link URL patterns with OpenRegister's search provider.
 *
 * When a user searches in Nextcloud's unified search, results for Decidesk schemas
 * will link directly to the relevant detail views in the app.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
 */
class DeepLinkRegistrationListener implements IEventListener
{
    /**
     * Schema slugs and their corresponding frontend route segments.
     *
     * @var array<string,string>
     */
    private const SCHEMA_ROUTES = [
        'governance-body'  => 'governance-bodies',
        'meeting'          => 'meetings',
        'participant'      => 'participants',
        'agenda-item'      => 'agenda-items',
        'motion'           => 'motions',
        'amendment'        => 'amendments',
        'voting-round'     => 'voting-rounds',
        'vote'             => 'votes',
        'decision'         => 'decisions',
        'action-item'      => 'action-items',
        'minutes'          => 'minutes',
        'digital-document' => 'digital-documents',
        'monetary-amount'  => 'monetary-amounts',
        'offer'            => 'offers',
        'order'            => 'orders',
        'product'          => 'products',
        'report'           => 'reports',
    ];

    /**
     * Handle the deep link registration event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
     */
    public function handle(Event $event): void
    {
        if ($event instanceof DeepLinkRegistrationEvent === false) {
            return;
        }

        foreach (self::SCHEMA_ROUTES as $schemaSlug => $routeSegment) {
            $event->register(
                appId: 'decidesk',
                registerSlug: 'decidesk',
                schemaSlug: $schemaSlug,
                urlTemplate: '/apps/decidesk/#/'.$routeSegment.'/{uuid}'
            );
        }

    }//end handle()
}//end class
