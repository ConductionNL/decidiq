<?php

/**
 * Decidiq RoomSystemMessageListener
 *
 * Applies a message from the chamber's room system as soon as integriq stores it.
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
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Service\RoomSystemMessageService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * On a created `room-system-message`, hands the row to RoomSystemMessageService.
 *
 * The message itself changes nothing: only this listener, through the service,
 * sets its status and applies it. A failure is logged and never breaks the
 * save that stored the message.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-004-a-speaker-marking-becomes-a-speech-on-the-members-engagement-record
 */
class RoomSystemMessageListener implements IEventListener {

	public const SCHEMA_MESSAGE = RoomSystemMessageService::SCHEMA;

	/**
	 * Constructor.
	 *
	 * @param RoomSystemMessageService        $messages       Applies the message.
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells whether the object is a room system message.
	 * @param LoggerInterface                 $logger         The logger.
	 */
	public function __construct(
		private readonly RoomSystemMessageService $messages,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply a created room system message.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-004-a-speaker-marking-becomes-a-speech-on-the-members-engagement-record
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		try {
			$entity = $event->getObject();
			$row = (new EntityPayloadReader())->payloadOf(entity: $entity);
			if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_MESSAGE, row: $row) === false) {
				return;
			}

			$this->messages->apply(message: (['id' => (string)$entity->getUuid()] + $row));
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: room system message could not be applied', ['exception' => $e->getMessage()]);
		}
	}//end handle()
}//end class
