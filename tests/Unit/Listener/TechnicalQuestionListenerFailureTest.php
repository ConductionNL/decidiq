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

use OCA\Decidiq\Listener\TechnicalQuestionListener;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A failing notice never breaks the save, other events are ignored, and a
 * malformed typeFields reads as no fields.
 *
 * @covers \OCA\Decidiq\Listener\TechnicalQuestionListener
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 * @uses   \OCA\Decidiq\Service\ListenerSchemaResolver
 */
class TechnicalQuestionListenerFailureTest extends TestCase {

	/**
	 * The listener over a notifier and a logger.
	 *
	 * @param NotificationPreferenceService $notifications The notifier.
	 * @param LoggerInterface               $logger        The logger.
	 *
	 * @return TechnicalQuestionListener
	 */
	private function listener(NotificationPreferenceService $notifications, LoggerInterface $logger): TechnicalQuestionListener {
		$mapper = new class {
			/**
			 * The agenda-item schema.
			 *
			 * @param string|int $id The schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$schema = new Schema();
				$schema->setSlug('agenda-item');
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new TechnicalQuestionListener(
			notifications: $notifications,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: new NullLogger()),
			l10n: $l10n,
			logger: $logger
		);
	}//end listener()

	/**
	 * An agenda item with the given payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function item(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ai-1');
		$entity->setRegister('7');
		$entity->setSchema('41');
		$entity->setObject($payload);
		return $entity;
	}//end item()

	/**
	 * A failing dispatch is logged as a warning and swallowed.
	 *
	 * @return void
	 */
	public function testAFailingNoticeIsLoggedAndSwallowed(): void {
		$notifications = $this->createMock(NotificationPreferenceService::class);
		$notifications->expects($this->once())->method('dispatch')->with(
			'official-1',
			'taskAssigned',
			'A technical question was assigned to you',
			'Please answer by no deadline set: Hoeveel kost de brug?',
			'/agenda-items/ai-1'
		)->willThrowException(new RuntimeException('notifier down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Decidiq: technical question notice failed', ['exception' => 'notifier down']);

		$this->listener($notifications, $logger)->handle(
			new ObjectCreatedEvent($this->item(['title' => 'Vraag', 'typeFields' => ['assignedTo' => 'official-1', 'question' => 'Hoeveel kost de brug?']]))
		);
	}//end testAFailingNoticeIsLoggedAndSwallowed()

	/**
	 * Another event and a non-array typeFields send nothing.
	 *
	 * @return void
	 */
	public function testOtherEventsAndMalformedFieldsSendNothing(): void {
		$notifications = $this->createMock(NotificationPreferenceService::class);
		$notifications->expects($this->never())->method('dispatch');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$listener = $this->listener($notifications, $logger);

		$listener->handle(new Event());
		$listener->handle(new ObjectCreatedEvent($this->item(['title' => 'Vraag', 'typeFields' => 'assignedTo=official-1'])));
	}//end testOtherEventsAndMalformedFieldsSendNothing()
}//end class
