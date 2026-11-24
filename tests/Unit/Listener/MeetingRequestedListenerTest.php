<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Listener
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Event\MeetingRequestedEvent;
use OCA\Decidiq\Kernel\Contracts\MeetingCommandServiceInterface;
use OCA\Decidiq\Listener\MeetingRequestedListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the meeting command listener.
 *
 * @spec openspec/specs/meeting-requested-event/spec.md
 */
class MeetingRequestedListenerTest extends TestCase {

	/**
	 * A dossiq session command.
	 *
	 * @return MeetingRequestedEvent The event.
	 */
	private function command(): MeetingRequestedEvent {
		return new MeetingRequestedEvent(
			subject: ['register' => 'dossiq', 'schema' => 'hearingSession', 'id' => 'session-1'],
			meeting: ['title' => 'Hoorzitting', 'scheduledDate' => '2026-12-10T10:00:00+01:00', 'location' => 'Raadzaal'],
			actorId: 'behandelaar'
		);

	}//end command()

	/**
	 * The listener hands the subject and fields to the engine and answers with the id.
	 *
	 * @return void
	 */
	public function testTheCommandIsAnsweredWithTheMeetingId(): void {
		$service = $this->createMock(originalClassName: MeetingCommandServiceInterface::class);
		$service->expects($this->once())->method('upsert')->with(
			['register' => 'dossiq', 'schema' => 'hearingSession', 'id' => 'session-1'],
			['title' => 'Hoorzitting', 'scheduledDate' => '2026-12-10T10:00:00+01:00', 'location' => 'Raadzaal'],
		)->willReturn(['id' => 'meeting-9', 'created' => true]);

		$event = $this->command();
		$listener = new MeetingRequestedListener(commandService: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$listener->handle(event: $event);

		$this->assertTrue(condition: $event->isHandled());
		$this->assertTrue(condition: $event->isCreated());
		$this->assertSame(expected: 'meeting-9', actual: $event->getMeetingId());

	}//end testTheCommandIsAnsweredWithTheMeetingId()

	/**
	 * A refusal leaves the event unhandled and throws nothing.
	 *
	 * @return void
	 */
	public function testARefusalLeavesTheEventUnhandledAndThrowsNothing(): void {
		$service = $this->createMock(originalClassName: MeetingCommandServiceInterface::class);
		$service->method('upsert')->willThrowException(new RuntimeException('A meeting command needs a title'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$event = $this->command();
		(new MeetingRequestedListener(commandService: $service, logger: $logger))->handle(event: $event);

		$this->assertFalse(condition: $event->isHandled());
		$this->assertSame(expected: '', actual: $event->getMeetingId());

	}//end testARefusalLeavesTheEventUnhandledAndThrowsNothing()

	/**
	 * Another event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$service = $this->createMock(originalClassName: MeetingCommandServiceInterface::class);
		$service->expects($this->never())->method('upsert');

		$listener = new MeetingRequestedListener(commandService: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$listener->handle(event: new Event());

	}//end testAnotherEventIsIgnored()

}//end class
