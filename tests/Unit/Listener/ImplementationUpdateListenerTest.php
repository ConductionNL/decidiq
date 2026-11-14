<?php

/**
 * Tests for ImplementationUpdateListener (followup-implementation-progress).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-002-the-decision-shows-its-current-implementation-status
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\ImplementationUpdateListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Listener\Registrar\SaveGuardSubscriptions;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Only adopted decisions take updates, and the newest one sets the status.
 *
 * @spec openspec/changes/followup-implementation-progress/specs/decision-management/spec.md#requirement-req-fup-002-the-decision-shows-its-current-implementation-status
 */
final class ImplementationUpdateListenerTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $saved = [];

	/** @var list<array<int|string, mixed>> */
	private array $findCalls = [];

	/**
	 * The listener with a fake ObjectService returning one decision.
	 *
	 * @param array<string, mixed>|null $decision The decision find() returns
	 * @param bool                      $failRead Whether find() throws
	 * @param string|null               $uid      The caller, null when none
	 *
	 * @return ImplementationUpdateListener
	 */
	private function listener(?array $decision, bool $failRead = false, ?string $uid = 'griffier'): ImplementationUpdateListener {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (...$args) use ($decision, $failRead): ?ObjectEntity {
				$this->findCalls[] = $args;
				if ($failRead === true) {
					throw new RuntimeException('database gone');
				}

				if ($decision === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('dec-1');
				$entity->setObject($decision);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, mixed $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return new ObjectEntity();
			}
		);

		$mapper = new class {
			/**
			 * Resolve a schema by id.
			 *
			 * @param string|int $id Schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$slugs = ['130' => 'implementation-update', '93' => 'meeting'];
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

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new ImplementationUpdateListener(
			objectService: $objects,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class)),
			userSession: $session,
			logger: new NullLogger()
		);
	}//end listener()

	/**
	 * An update entity.
	 *
	 * @param array<string, mixed> $data     The update data
	 * @param string               $schemaId The schema id
	 *
	 * @return ObjectEntity
	 */
	private function update(array $data, string $schemaId = '130'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('upd-1');
		$entity->setRegister('7');
		$entity->setSchema($schemaId);
		$entity->setObject($data);
		return $entity;
	}//end update()

	/**
	 * An adopted motion.
	 *
	 * @param array<string, mixed> $extra Extra fields
	 *
	 * @return array<string, mixed>
	 */
	private function adopted(array $extra = []): array {
		return array_merge(['title' => 'Motie speeltuin', 'decisionType' => 'motion', 'lifecycle' => 'decided', 'outcome' => 'adopted'], $extra);
	}//end adopted()

	public function testAnUpdateOnARejectedMotionIsRefused(): void {
		$event = new ObjectCreatingEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-03-01', 'status' => 'on-track', 'text' => 'x']));
		$this->listener(decision: ['lifecycle' => 'decided', 'outcome' => 'rejected'])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['message' => 'Progress can only be reported on an adopted decision.'], $event->getErrors());
		self::assertSame([], $event->getModifiedData());
		self::assertContains('decision', $this->findCalls[0]);
		self::assertContains('dec-1', $this->findCalls[0]);
	}//end testAnUpdateOnARejectedMotionIsRefused()

	public function testAnUpdateOnAnUndecidedOrMissingDecisionIsRefused(): void {
		foreach ([['lifecycle' => 'draft', 'outcome' => 'adopted'], null] as $decision) {
			$event = new ObjectCreatingEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-03-01', 'status' => 'on-track']));
			$this->listener(decision: $decision)->handle($event);
			self::assertTrue($event->isPropagationStopped());
		}

		$event = new ObjectCreatingEvent($this->update(data: ['reportedOn' => '2026-03-01', 'status' => 'on-track']));
		$this->listener(decision: $this->adopted())->handle($event);
		self::assertTrue($event->isPropagationStopped());
	}//end testAnUpdateOnAnUndecidedOrMissingDecisionIsRefused()

	public function testAnUnreadableDecisionRefusesTheUpdate(): void {
		$event = new ObjectCreatingEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-03-01', 'status' => 'on-track']));
		$this->listener(decision: $this->adopted(), failRead: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('could not be read', (string)$event->getErrors()['message']);
	}//end testAnUnreadableDecisionRefusesTheUpdate()

	public function testAnUpdateOnAnAdoptedDecisionIsAcceptedAndRecordsTheReporter(): void {
		foreach (['decided', 'enacted'] as $lifecycle) {
			$event = new ObjectCreatingEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-03-01', 'status' => 'on-track', 'reportedBy' => 'someone-else']));
			$this->listener(decision: $this->adopted(extra: ['lifecycle' => $lifecycle]))->handle($event);

			self::assertFalse($event->isPropagationStopped(), $lifecycle);
			self::assertSame([], $event->getErrors());
			self::assertSame(['reportedBy' => 'griffier'], $event->getModifiedData());
		}

		$event = new ObjectCreatingEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-03-01', 'status' => 'on-track']));
		$this->listener(decision: $this->adopted(), uid: null)->handle($event);
		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
	}//end testAnUpdateOnAnAdoptedDecisionIsAcceptedAndRecordsTheReporter()

	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->update(data: ['decision' => 'dec-1'], schemaId: '93'));
		$this->listener(decision: null)->handle($event);
		self::assertFalse($event->isPropagationStopped());

		$this->listener(decision: $this->adopted())->handle(new ObjectCreatedEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-06-15', 'status' => 'delayed'], schemaId: '93')));
		self::assertSame([], $this->saved);
		self::assertSame([], $this->findCalls);
	}//end testAnotherSchemaIsLeftAlone()

	public function testTheNewestUpdateSetsTheDecisionStatus(): void {
		$this->listener(decision: $this->adopted(extra: ['implementationStatus' => 'on-track', 'implementationStatusDate' => '2026-03-01']))
			->handle(new ObjectCreatedEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-06-15', 'status' => 'delayed'])));

		self::assertCount(1, $this->saved);
		$object = $this->saved[0]['object'];
		self::assertSame('decidiq', $this->saved[0]['register']);
		self::assertSame('decision', $this->saved[0]['schema']);
		self::assertSame('dec-1', $this->saved[0]['uuid']);
		self::assertSame('delayed', $object['implementationStatus']);
		self::assertSame('2026-06-15', $object['implementationStatusDate']);
		self::assertSame('Motie speeltuin', $object['title']);
		self::assertSame('adopted', $object['outcome']);
		self::assertFalse($this->saved[0]['_rbac']);
		self::assertFalse($this->saved[0]['_multitenancy']);
	}//end testTheNewestUpdateSetsTheDecisionStatus()

	public function testTheFirstUpdateAndASameDayUpdateSetTheStatus(): void {
		$this->listener(decision: $this->adopted())
			->handle(new ObjectCreatedEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-03-01T10:00:00Z', 'status' => 'on-track'])));
		$this->listener(decision: $this->adopted(extra: ['implementationStatus' => 'on-track', 'implementationStatusDate' => '2026-03-01']))
			->handle(new ObjectCreatedEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-03-01', 'status' => 'completed'])));

		self::assertCount(2, $this->saved);
		self::assertSame('2026-03-01', $this->saved[0]['object']['implementationStatusDate']);
		self::assertSame('completed', $this->saved[1]['object']['implementationStatus']);
	}//end testTheFirstUpdateAndASameDayUpdateSetTheStatus()

	public function testABackDatedUpdateDoesNotChangeTheStatus(): void {
		$this->listener(decision: $this->adopted(extra: ['implementationStatus' => 'delayed', 'implementationStatusDate' => '2026-06-15']))
			->handle(new ObjectCreatedEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-03-01', 'status' => 'on-track'])));

		self::assertSame([], $this->saved);
	}//end testABackDatedUpdateDoesNotChangeTheStatus()

	public function testAnIncompleteUpdateOrAFailingReadChangesNothing(): void {
		$this->listener(decision: $this->adopted())->handle(new ObjectCreatedEvent($this->update(data: ['decision' => 'dec-1', 'status' => 'delayed'])));
		$this->listener(decision: $this->adopted())->handle(new ObjectCreatedEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-06-15'])));
		$this->listener(decision: null)->handle(new ObjectCreatedEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-06-15', 'status' => 'delayed'])));
		$this->listener(decision: $this->adopted(), failRead: true)
			->handle(new ObjectCreatedEvent($this->update(data: ['decision' => 'dec-1', 'reportedOn' => '2026-06-15', 'status' => 'delayed'])));

		self::assertSame([], $this->saved);
	}//end testAnIncompleteUpdateOrAFailingReadChangesNothing()

	public function testTheRegisterCarriesTheUpdateSchemaAndTheDecisionFields(): void {
		self::assertSame([], MergedRegisterSchema::violations(
			slug: 'implementation-update',
			payload: ['decision' => 'dec-1', 'reportedOn' => '2026-06-15', 'status' => 'delayed', 'percentComplete' => 40, 'text' => 'Uitvoering vertraagd']
		));
		self::assertNotSame([], MergedRegisterSchema::violations(slug: 'implementation-update', payload: ['decision' => 'dec-1', 'status' => 'delayed']));
		self::assertNotSame([], MergedRegisterSchema::violations(
			slug: 'implementation-update',
			payload: ['decision' => 'dec-1', 'reportedOn' => '2026-06-15', 'status' => 'stalled', 'text' => 'x']
		));

		$decision = MergedRegisterSchema::forSlug(slug: 'decision');
		self::assertArrayHasKey('implementationStatus', $decision['properties']);
		self::assertArrayHasKey('implementationStatusDate', $decision['properties']);
		$rule = ($decision['x-openregister-notifications']['implementationSilent'] ?? []);
		self::assertSame('scheduled', $rule['trigger']['type'] ?? null);
	}//end testTheRegisterCarriesTheUpdateSchemaAndTheDecisionFields()

	public function testBothHandlersAreSubscribedToUpdateCreates(): void {
		$guards = array_values(array_filter(SaveGuardSubscriptions::ALL, static fn (array $g): bool => $g['listener'] === ImplementationUpdateListener::class));
		self::assertSame([['event' => ObjectCreatingEvent::class, 'listener' => ImplementationUpdateListener::class, 'schemas' => ['implementation-update']]], $guards);

		$subscribed = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);
		(new ObjectListenerRegistrar(logger: new NullLogger()))->register(dispatcher: $dispatcher);

		self::assertContains([ObjectCreatedEvent::class, ImplementationUpdateListener::class], $subscribed);
		self::assertContains([ObjectCreatingEvent::class, ImplementationUpdateListener::class], $subscribed);
	}//end testBothHandlersAreSubscribedToUpdateCreates()
}//end class
