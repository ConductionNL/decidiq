<?php

/**
 * Decidiq MeetingRequestedListener
 *
 * Maps an inbound cross-app MeetingRequestedEvent onto MeetingCommandService
 * and writes the resolved meeting id back onto the dispatched instance for the
 * synchronous producer.
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
 * @spec openspec/specs/meeting-requested-event/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Event\MeetingRequestedEvent;
use OCA\Decidiq\Kernel\Contracts\MeetingCommandServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles MeetingRequestedEvent by delegating to the command service.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/meeting-requested-event/spec.md
 */
class MeetingRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param MeetingCommandServiceInterface $commandService The idempotent upsert engine.
	 * @param LoggerInterface                $logger         Logger.
	 */
	public function __construct(
		private readonly MeetingCommandServiceInterface $commandService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a MeetingRequestedEvent.
	 *
	 * A failure is logged and leaves the event unhandled; nothing is rethrown,
	 * because an exception out of handle() aborts the whole dispatch and an
	 * unrelated listener on the same event would stop running with it.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-requested-event/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof MeetingRequestedEvent === false) {
			return;
		}

		try {
			$result = $this->commandService->upsert(subject: $event->getSubject(), meeting: $event->getMeeting());
		} catch (Throwable $e) {
			$this->logger->error(
				'Decidiq: MeetingRequestedEvent not handled',
				['subject' => $event->getSubject(), 'exception' => $e]
			);
			return;
		}

		$event->answer(meetingId: $result['id'], created: $result['created']);

	}//end handle()

}//end class
