<?php

/**
 * Decidesk DeepLinkRegistrationListener
 *
 * Registers Decidesk's deep link URL patterns with OpenRegister's search provider.
 *
 * @category Listener
 * @package  OCA\Decidesk\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-2
 */

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
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-2
 */
class DeepLinkRegistrationListener implements IEventListener
{
    /**
     * Schema-to-route mapping for all Decidesk entity types.
     *
     * Each entry maps a schema slug to its frontend URL template.
     *
     * @var array<string,string>
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-2
     */
    private const SCHEMA_ROUTES = [
        'governance-body'  => '/apps/decidesk/#/governance-bodies/{uuid}',
        'participant'      => '/apps/decidesk/#/participants/{uuid}',
        'meeting'          => '/apps/decidesk/#/meetings/{uuid}',
        'agenda-item'      => '/apps/decidesk/#/agenda-items/{uuid}',
        'motion'           => '/apps/decidesk/#/motions/{uuid}',
        'amendment'        => '/apps/decidesk/#/amendments/{uuid}',
        'voting-round'     => '/apps/decidesk/#/voting-rounds/{uuid}',
        'vote'             => '/apps/decidesk/#/votes/{uuid}',
        'decision'         => '/apps/decidesk/#/decisions/{uuid}',
        'action-item'      => '/apps/decidesk/#/action-items/{uuid}',
        'minutes'          => '/apps/decidesk/#/minutes/{uuid}',
        'digital-document' => '/apps/decidesk/#/digital-documents/{uuid}',
        'monetary-amount'  => '/apps/decidesk/#/monetary-amounts/{uuid}',
        'offer'            => '/apps/decidesk/#/offers/{uuid}',
        'order'            => '/apps/decidesk/#/orders/{uuid}',
        'product'          => '/apps/decidesk/#/products/{uuid}',
        'report'           => '/apps/decidesk/#/reports/{uuid}',
    ];

    /**
     * Handle the deep link registration event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     *
     * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-2
     */
    public function handle(Event $event): void
    {
        if ($event instanceof DeepLinkRegistrationEvent === false) {
            return;
        }

        foreach (self::SCHEMA_ROUTES as $schemaSlug => $urlTemplate) {
            $event->register(
                appId: 'decidesk',
                registerSlug: 'decidesk',
                schemaSlug: $schemaSlug,
                urlTemplate: $urlTemplate
            );
        }

    }//end handle()
}//end class
