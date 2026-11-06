<?php

/**
 * Unit tests for TechnicalQuestionListener.
 *
 * A technical question is an agenda item whose type declares an assignee
 * field. Assigning it tells the official; answering it tells the member who
 * asked. Nothing else about an agenda item save sends anything.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Listener
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\AppInfo\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Listener\TechnicalQuestionListener;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Service\NotificationPreferenceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Covers assignment and answer notices, the registrar wiring and the schema.
 */
final class TechnicalQuestionListenerTest extends TestCase {

	/**
	 * The notification service double (real class, real method).
	 *
	 * @var NotificationPreferenceService&MockObject
	 */
	private NotificationPreferenceService&MockObject $notifications;

	/**
	 * The listener under test.
	 *
	 * @var TechnicalQuestionListener
	 */
	private TechnicalQuestionListener $listener;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->notifications = $this->createMock(NotificationPreferenceService::class);

		$mapper = new class {
			/**
			 * Resolve a schema by id.
			 *
			 * @param string|int $id Schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$slugs = ['41' => 'agenda-item', '93' => 'meeting'];
				if (isset($slugs[(string)$id]) === false) {
					throw new RuntimeException('Schema ' . $id . ' does not exist');
				}

				$schema = new Schema();
				$schema->setSlug($slugs[(string)$id]);
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				return vsprintf($text, $parameters);
			}
		);

		$this->listener = new TechnicalQuestionListener(
			notifications: $this->notifications,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class)),
			l10n: $l10n,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * A production-shaped agenda item.
	 *
	 * @param array<string, mixed> $typeFields The type field values.
	 * @param string               $schemaId   Numeric schema id.
	 *
	 * @return ObjectEntity
	 */
	private function item(array $typeFields, string $schemaId = '41'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('item-1');
		$entity->setRegister('7');
		$entity->setSchema($schemaId);
		$entity->setOwner('a.member');
		$entity->setObject(['title' => 'Vraag over RIB 2026-14', 'meeting' => 'meet-1', 'typeFields' => $typeFields]);
		return $entity;
	}//end item()

	/**
	 * Assigning a question tells the official, with the deadline and a link.
	 *
	 * @return void
	 */
	public function testAssigningTellsTheOfficial(): void {
		$this->notifications->expects(self::once())->method('dispatch')->with(
			'j.official',
			'taskAssigned',
			self::stringContains('assigned to you'),
			self::logicalAnd(self::stringContains('Klopt de planning?'), self::stringContains('2026-10-09')),
			'/agenda-items/item-1'
		);

		$old = $this->item(['question' => 'Klopt de planning?']);
		$new = $this->item(['question' => 'Klopt de planning?', 'assignedTo' => 'j.official', 'answerDeadline' => '2026-10-09']);
		$this->listener->handle(new ObjectUpdatedEvent($new, $old));
	}//end testAssigningTellsTheOfficial()

	/**
	 * A question created already assigned tells the official too.
	 *
	 * @return void
	 */
	public function testAQuestionCreatedAssignedTellsTheOfficial(): void {
		$this->notifications->expects(self::once())->method('dispatch')->with('j.official', 'taskAssigned');

		$this->listener->handle(new ObjectCreatedEvent($this->item(['question' => 'Klopt de planning?', 'assignedTo' => 'j.official'])));
	}//end testAQuestionCreatedAssignedTellsTheOfficial()

	/**
	 * Answering tells the member who asked, and does not tell the official again.
	 *
	 * @return void
	 */
	public function testAnsweringTellsTheMemberWhoAsked(): void {
		$this->notifications->expects(self::once())->method('dispatch')->with(
			'a.member',
			'taskAssigned',
			self::stringContains('answered'),
			self::stringContains('Klopt de planning?'),
			'/agenda-items/item-1'
		);

		$old = $this->item(['question' => 'Klopt de planning?', 'assignedTo' => 'j.official']);
		$new = $this->item(['question' => 'Klopt de planning?', 'assignedTo' => 'j.official', 'answer' => 'Ja, de planning klopt.']);
		$this->listener->handle(new ObjectUpdatedEvent($new, $old));
	}//end testAnsweringTellsTheMemberWhoAsked()

	/**
	 * Editing the question text of an assigned, unanswered question sends nothing.
	 *
	 * @return void
	 */
	public function testOtherEditsSendNothing(): void {
		$this->notifications->expects(self::never())->method('dispatch');

		$old = $this->item(['question' => 'Klopt de planning?', 'assignedTo' => 'j.official']);
		$new = $this->item(['question' => 'Klopt de planning van fase 2?', 'assignedTo' => 'j.official']);
		$this->listener->handle(new ObjectUpdatedEvent($new, $old));
		$this->listener->handle(new ObjectCreatedEvent($this->item(['assignedTo' => 'j.official'], schemaId: '93')));
	}//end testOtherEditsSendNothing()

	/**
	 * The listener is subscribed to agenda item creates and updates.
	 *
	 * @return void
	 */
	public function testRegistrarSubscribesTheListener(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);

		(new ObjectListenerRegistrar(logger: new NullLogger()))->register($dispatcher);

		self::assertContains([ObjectCreatedEvent::class, TechnicalQuestionListener::class], $subscribed);
		self::assertContains([ObjectUpdatedEvent::class, TechnicalQuestionListener::class], $subscribed);
	}//end testRegistrarSubscribesTheListener()

	/**
	 * The merged agenda item type schema accepts a person field, and the
	 * seeded technical question type validates against it.
	 *
	 * @return void
	 */
	public function testTheSeededTypeValidatesAgainstTheMergedFieldSchema(): void {
		$settings = __DIR__ . '/../../../lib/Settings/';
		$type = [];
		$files = array_merge([$settings . 'decidesk_register.json'], (glob($settings . 'register.d/*.json') ?: []));
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $schema) {
				if (($schema['slug'] ?? '') === 'agenda-item-type') {
					$type = array_replace_recursive($type, $schema);
				}
			}
		}

		$fieldSchema = $type['properties']['fields'];
		self::assertContains('user', $fieldSchema['items']['properties']['fieldType']['enum']);

		$profile = json_decode((string)file_get_contents($settings . 'profiles/municipality.json'), true);
		$seeded = null;
		$stack = [$profile];
		while ($stack !== [] && $seeded === null) {
			$node = array_pop($stack);
			if (is_array($node) === false) {
				continue;
			}

			if (($node['slug'] ?? '') === 'type-technische-vraag') {
				$seeded = $node;
				break;
			}

			foreach ($node as $child) {
				$stack[] = $child;
			}
		}

		self::assertIsArray($seeded, 'The municipality profile seeds the technical question type');
		$keys = array_column($seeded['fields'], 'fieldType', 'key');
		self::assertSame('user', ($keys['assignedTo'] ?? null));
		self::assertSame('date', ($keys['answerDeadline'] ?? null));

		$validator = new Validator();
		$schema = json_decode((string)json_encode($fieldSchema));
		self::assertTrue($validator->validate(json_decode((string)json_encode($seeded['fields'])), $schema)->isValid());
		self::assertFalse(
			$validator->validate(json_decode('[{"key":"x","fieldType":"person"}]'), $schema)->isValid(),
			'An undeclared field type must be refused, or the validation proves nothing'
		);
	}//end testTheSeededTypeValidatesAgainstTheMergedFieldSchema()
}//end class
