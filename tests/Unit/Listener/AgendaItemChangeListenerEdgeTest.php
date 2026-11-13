<?php

/**
 * Edge cases of the agenda item change listener.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\AgendaItemChangeListener;
use OCA\Decidiq\Service\AgendaService;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\SystemWhenNobodySignedIn;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Events that carry no agenda item announce nothing; an update without its
 * old state is treated as a change; a signed-in user's change is announced
 * as that user; and a failing announcement is logged.
 *
 * @covers \OCA\Decidiq\Listener\AgendaItemChangeListener
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 * @uses   \OCA\Decidiq\Service\ListenerSchemaResolver
 * @uses   \OCA\Decidiq\Service\SystemWhenNobodySignedIn
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.1
 */
class AgendaItemChangeListenerEdgeTest extends TestCase {

	/**
	 * The agenda service double.
	 *
	 * @var AgendaService&MockObject
	 */
	private AgendaService&MockObject $agendaService;

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The listener's logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->agendaService = $this->createMock(AgendaService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The listener, with or without a signed-in user.
	 *
	 * @param bool $signedIn Whether a user is signed in
	 *
	 * @return AgendaItemChangeListener
	 */
	private function listener(bool $signedIn = false): AgendaItemChangeListener {
		$mapper = new class {
			/**
			 * @param string|int $id Schema id
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				if ((string)$id !== '41') {
					throw new RuntimeException('Schema ' . $id . ' does not exist');
				}

				$schema = new Schema();
				$schema->setSlug('agenda-item');
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new AgendaItemChangeListener(
			agendaService: $this->agendaService,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class)),
			system: new SystemWhenNobodySignedIn($this->objectService, $session),
			logger: $this->logger,
		);
	}//end listener()

	/**
	 * An agenda item of the given meeting.
	 *
	 * @param string $meeting The meeting id
	 *
	 * @return ObjectEntity
	 */
	private function item(string $meeting): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('item-1');
		$entity->setRegister('7');
		$entity->setSchema('41');
		$entity->setObject(['title' => 'Opening', 'meeting' => $meeting]);
		return $entity;
	}//end item()

	/**
	 * Events without an agenda item announce nothing.
	 *
	 * @return void
	 */
	public function testEventsWithoutAnItemAnnounceNothing(): void {
		$this->agendaService->expects(self::never())->method('notifyAgendaChanged');
		$listener = $this->listener();

		$listener->handle(new Event());
		$listener->handle(new ObjectCreatedEvent(null));
		$listener->handle(new ObjectUpdatedEvent(null, $this->item(meeting: 'm-1')));
	}//end testEventsWithoutAnItemAnnounceNothing()

	/**
	 * An update without the old state is announced, as the signed-in user.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutItsOldStateIsAnnouncedAsTheSignedInUser(): void {
		$this->agendaService->expects(self::once())->method('notifyAgendaChanged')->with('m-1');
		$this->objectService->expects(self::never())->method('runAsSystem');

		$this->listener(signedIn: true)->handle(new ObjectUpdatedEvent($this->item(meeting: 'm-1'), null));
	}//end testAnUpdateWithoutItsOldStateIsAnnouncedAsTheSignedInUser()

	/**
	 * A failing announcement is logged and the write goes on.
	 *
	 * @return void
	 */
	public function testAFailingAnnouncementIsLogged(): void {
		$this->agendaService->method('notifyAgendaChanged')->willThrowException(new RuntimeException('mail down'));
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: agenda change notice failed',
			['exception' => 'mail down']
		);

		$this->listener(signedIn: true)->handle(new ObjectCreatedEvent($this->item(meeting: 'm-1')));
	}//end testAFailingAnnouncementIsLogged()
}//end class
