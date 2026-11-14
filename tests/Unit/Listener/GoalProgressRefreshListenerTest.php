<?php

/**
 * GoalProgressRefreshListener unit tests.
 *
 * A contribution that changes re-saves the goals it points at, before and
 * after, so OpenRegister materialises their rates; a save that changes
 * nothing a rate counts saves no goal.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/goals-linked-to-decisions/specs/organisation-goals/spec.md#requirement-req-013-progress-stays-current-when-a-contribution-changes
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\GoalProgressRefreshListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class GoalProgressRefreshListenerTest extends TestCase {

	private const SCHEMA_IDS = ['11' => 'governance-commitment', '12' => 'action-item', '13' => 'goal', '14' => 'planned-agenda-item', '15' => 'decision'];

	/** @var array<int, array<string, mixed>> */
	private array $saved = [];

	/** @var array<int, string> */
	private array $read = [];

	/**
	 * The listener over a fake ObjectService holding goals A and B.
	 *
	 * @param bool $failRead Whether find() throws
	 *
	 * @return GoalProgressRefreshListener
	 */
	private function listener(bool $failRead = false): GoalProgressRefreshListener {
		$goals   = [
			'goal-a' => ['title' => 'Verlichte en veilige fietsroutes in 2027', 'status' => 'active', 'commitmentSettlementRate' => 50],
			'goal-b' => ['title' => 'Schoolroutes veilig ingericht', 'status' => 'active'],
		];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (string $id, mixed $register = null, mixed $schema = null) use ($goals, $failRead): ?ObjectEntity {
				$this->read[] = $id;
				if ($failRead === true) {
					throw new RuntimeException('database gone');
				}

				if (isset($goals[$id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject(array_merge($goals[$id], ['@self' => ['id' => $id, 'owner' => 'someone']]));
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, mixed $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return new ObjectEntity();
			}
		);

		$mapper = new class (self::SCHEMA_IDS) {
			/**
			 * Constructor.
			 *
			 * @param array<string, string> $slugs Schema id to slug.
			 */
			public function __construct(private array $slugs) {
			}

			/**
			 * Resolve a schema by id.
			 *
			 * @param string|int $id Schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				if (isset($this->slugs[(string)$id]) === false) {
					throw new RuntimeException('Schema ' . $id . ' does not exist');
				}

				$schema = new Schema();
				$schema->setSlug($this->slugs[(string)$id]);
				return $schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return new GoalProgressRefreshListener(
			objectService: $objects,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class)),
			logger: new NullLogger()
		);
	}//end listener()

	/**
	 * An object of one schema.
	 *
	 * @param string               $slug The schema slug.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $slug, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setRegister('7');
		$entity->setSchema((string)array_search($slug, self::SCHEMA_IDS, true));
		$entity->setObject($data);
		return $entity;
	}//end object()

	/**
	 * The goal ids re-saved, in order.
	 *
	 * @return array<int, string|null>
	 */
	private function savedGoals(): array {
		return array_column($this->saved, 'uuid');
	}//end savedGoals()

	public function testDisposingACommitmentResavesItsGoalAsStored(): void {
		$this->listener()->handle(new ObjectUpdatedEvent(
			$this->object(slug: 'governance-commitment', data: ['goal' => 'goal-a', 'lifecycle' => 'disposed']),
			$this->object(slug: 'governance-commitment', data: ['goal' => 'goal-a', 'lifecycle' => 'open'])
		));

		self::assertSame(['goal-a'], $this->savedGoals());
		self::assertSame(
			['object' => ['id' => 'goal-a', 'title' => 'Verlichte en veilige fietsroutes in 2027', 'status' => 'active', 'commitmentSettlementRate' => 50], 'register' => 'decidiq', 'schema' => 'goal', 'uuid' => 'goal-a', '_rbac' => false, '_multitenancy' => false],
			$this->saved[0]
		);
	}//end testDisposingACommitmentResavesItsGoalAsStored()

	public function testMovingAnActionItemRefreshesBothGoals(): void {
		$this->listener()->handle(new ObjectUpdatedEvent(
			$this->object(slug: 'action-item', data: ['goal' => 'goal-b', 'taskStatus' => 'open']),
			$this->object(slug: 'action-item', data: ['goal' => 'goal-a', 'taskStatus' => 'open'])
		));

		self::assertSame(['goal-a', 'goal-b'], $this->savedGoals());
	}//end testMovingAnActionItemRefreshesBothGoals()

	public function testASaveThatChangesNothingCountedSavesNoGoal(): void {
		$this->listener()->handle(new ObjectUpdatedEvent(
			$this->object(slug: 'governance-commitment', data: ['goal' => 'goal-a', 'lifecycle' => 'open', 'title' => 'Nieuwe titel']),
			$this->object(slug: 'governance-commitment', data: ['goal' => 'goal-a', 'lifecycle' => 'open', 'title' => 'Oude titel'])
		));
		// The goal's own re-save comes back as a goal update with the same
		// parent and status: it refreshes nothing, so there is no loop.
		$this->listener()->handle(new ObjectUpdatedEvent(
			$this->object(slug: 'goal', data: ['parentGoal' => 'goal-b', 'status' => 'active', 'commitmentSettlementRate' => 75]),
			$this->object(slug: 'goal', data: ['parentGoal' => 'goal-b', 'status' => 'active', 'commitmentSettlementRate' => 50])
		));

		self::assertSame([], $this->saved);
		self::assertSame([], $this->read);
	}//end testASaveThatChangesNothingCountedSavesNoGoal()

	public function testCreatesDeletesAndChildGoalsRefreshTheirGoal(): void {
		$listener = $this->listener();
		$listener->handle(new ObjectCreatedEvent($this->object(slug: 'action-item', data: ['goal' => 'goal-a', 'taskStatus' => 'open'])));
		$listener->handle(new ObjectDeletedEvent($this->object(slug: 'governance-commitment', data: ['goal' => 'goal-b', 'lifecycle' => 'open'])));
		$listener->handle(new ObjectUpdatedEvent(
			$this->object(slug: 'goal', data: ['parentGoal' => 'goal-a', 'status' => 'achieved']),
			$this->object(slug: 'goal', data: ['parentGoal' => 'goal-a', 'status' => 'active'])
		));

		self::assertSame(['goal-a', 'goal-b', 'goal-a'], $this->savedGoals());
	}//end testCreatesDeletesAndChildGoalsRefreshTheirGoal()

	public function testWhatNoRateReadsIsLeftAlone(): void {
		$listener = $this->listener();
		$listener->handle(new ObjectCreatedEvent($this->object(slug: 'decision', data: ['goal' => 'goal-a'])));
		$listener->handle(new ObjectCreatedEvent($this->object(slug: 'planned-agenda-item', data: ['goal' => 'goal-a'])));
		$listener->handle(new ObjectCreatedEvent($this->object(slug: 'action-item', data: ['taskStatus' => 'open'])));
		$listener->handle(new ObjectCreatedEvent($this->object(slug: 'action-item', data: ['goal' => '   '])));
		$listener->handle(new ObjectCreatedEvent(null));
		$listener->handle(new Event());

		self::assertSame([], $this->saved);
	}//end testWhatNoRateReadsIsLeftAlone()

	public function testAMissingOrUnreadableGoalSavesNothingAndDoesNotThrow(): void {
		$this->listener()->handle(new ObjectCreatedEvent($this->object(slug: 'action-item', data: ['goal' => 'goal-gone'])));
		self::assertSame(['goal-gone'], $this->read);

		$this->listener(failRead: true)->handle(new ObjectCreatedEvent($this->object(slug: 'action-item', data: ['goal' => 'goal-a'])));
		self::assertSame([], $this->saved);
	}//end testAMissingOrUnreadableGoalSavesNothingAndDoesNotThrow()

	public function testAffectedGoals(): void {
		$listener = $this->listener();

		self::assertSame(['goal-a'], $listener->affectedGoals(new: ['goal' => 'goal-a'], old: null, link: 'goal', counted: 'lifecycle'));
		self::assertSame(['goal-a'], $listener->affectedGoals(new: null, old: ['goal' => 'goal-a'], link: 'goal', counted: 'lifecycle'));
		self::assertSame(['goal-a'], $listener->affectedGoals(new: ['goal' => 'goal-a', 'lifecycle' => 'disposed'], old: ['goal' => 'goal-a', 'lifecycle' => 'open'], link: 'goal', counted: 'lifecycle'));
		self::assertSame(['goal-a'], $listener->affectedGoals(new: ['goal' => null], old: ['goal' => 'goal-a'], link: 'goal', counted: 'lifecycle'));
		self::assertSame([], $listener->affectedGoals(new: ['goal' => 'goal-a', 'lifecycle' => 'open'], old: ['goal' => 'goal-a', 'lifecycle' => 'open'], link: 'goal', counted: 'lifecycle'));
		self::assertSame([], $listener->affectedGoals(new: ['lifecycle' => 'disposed'], old: ['lifecycle' => 'open'], link: 'goal', counted: 'lifecycle'));
	}//end testAffectedGoals()

	public function testTheListenerIsSubscribedForTheCountedSchemas(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);
		(new ObjectListenerRegistrar(logger: new NullLogger()))->register(dispatcher: $dispatcher);

		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $event) {
			self::assertContains([$event, GoalProgressRefreshListener::class], $subscribed);
		}

		self::assertSame(['governance-commitment', 'action-item', 'goal'], array_keys(GoalProgressRefreshListener::CONTRIBUTORS));
	}//end testTheListenerIsSubscribedForTheCountedSchemas()
}//end class
