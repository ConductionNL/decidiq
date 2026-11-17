<?php

/**
 * Decidiq RoomSystemFeedListener
 *
 * Hands a saved meeting, voting round or engagement record to RoomSystemFeed,
 * which sends the room system what changed on its displays.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Service\RoomSystemFeed;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * On a created or updated meeting, voting round or engagement record, asks
 * RoomSystemFeed whether the room system needs to hear of it. A failure is
 * logged and never breaks the save, so a meeting carries on as usual when the
 * room system is unreachable.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
 */
class RoomSystemFeedListener implements IEventListener {

	/**
	 * The schemas the feed follows, with the feed method each one goes to.
	 */
	public const HANDLERS = [
		'meeting'           => 'agendaChanged',
		'voting-round'      => 'roundClosed',
		'engagement-record' => 'speakersChanged',
	];

	/**
	 * Constructor.
	 *
	 * @param RoomSystemFeed                  $feed           Sends what changed to the room system.
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells which schema the object is of.
	 * @param LoggerInterface                 $logger         The logger.
	 */
	public function __construct(
		private readonly RoomSystemFeed $feed,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Pass a saved object to the feed.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent || $event instanceof ObjectUpdatedEvent) {
			try {
				$this->pass(event: $event);
			} catch (Throwable $e) {
				$this->logger->warning('Decidiq: the room system could not be updated', ['exception' => $e->getMessage()]);
			}
		}
	}//end handle()

	/**
	 * Hand the saved object and the one it replaced to the feed method of its schema.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The event.
	 *
	 * @return void
	 */
	private function pass(ObjectCreatedEvent|ObjectUpdatedEvent $event): void {
		$entity = $event->getObject();
		$row = (new EntityPayloadReader())->payloadOf(entity: $entity);
		$method = self::HANDLERS[$this->schemaResolver->schemaSlug(entity: $entity, row: $row)] ?? null;
		if ($method === null) {
			return;
		}

		$this->feed->$method((['id' => (string)$entity->getUuid()] + $row), self::oldRow(event: $event));
	}//end pass()

	/**
	 * The object before the save; empty for a created one.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The event.
	 *
	 * @return array<string, mixed>
	 */
	private static function oldRow(ObjectCreatedEvent|ObjectUpdatedEvent $event): array {
		$old = null;
		if ($event instanceof ObjectUpdatedEvent) {
			$old = $event->getOldObject();
		}

		if ($old === null) {
			return [];
		}

		return (new EntityPayloadReader())->payloadOf(entity: $old);
	}//end oldRow()
}//end class
