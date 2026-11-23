<?php

/**
 * Unit tests for StepThresholdChangeListener (REQ-DWP-002), with OpenRegister's
 * real object update event.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Listener\StepThresholdChangeListener;
use OCA\Decidiq\Service\StepThresholdRecomputer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for StepThresholdChangeListener.
 */
final class StepThresholdChangeListenerTest extends TestCase {
	/**
	 * The calls the recomputer received: [before, after, actor].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A listener whose schema resolver answers the given slug.
	 *
	 * @param string               $slug   The schema slug of every entity.
	 * @param string|null          $uid    The signed-in user, or nobody.
	 * @param bool                 $fail   Whether the recomputer throws.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return StepThresholdChangeListener
	 */
	private function listener(
		string $slug = 'decision-stage',
		?string $uid = 'admin',
		bool $fail = false,
		?LoggerInterface $logger = null,
	): StepThresholdChangeListener {
		$recomputer = $this->createMock(originalClassName: StepThresholdRecomputer::class);
		$recomputer->method('stageChanged')->willReturnCallback(
			function (array $before, array $after, string $actor) use ($fail): ?array {
				if ($fail === true) {
					throw new RuntimeException('store down');
				}

				$this->calls[] = [$before, $after, $actor];
				return null;
			}
		);

		$resolver = $this->createMock(originalClassName: ListenerSchemaResolverInterface::class);
		$resolver->method('schemaSlug')->willReturn($slug);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new StepThresholdChangeListener(
			recomputer: $recomputer,
			schemaResolver: $resolver,
			userSession: $session,
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)),
		);
	}//end listener()

	/**
	 * An OpenRegister object entity.
	 *
	 * @param array<string, mixed> $payload The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('stage-1');
		$entity->setRegister('7');
		$entity->setSchema('61');
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * A stage update hands both versions, the stage id and the actor to the recomputer.
	 *
	 * @return void
	 */
	public function testAStageUpdateReachesTheRecomputer(): void {
		$this->listener()->handle(
			new ObjectUpdatedEvent($this->entity(payload: ['thresholdValue' => 0.6]), $this->entity(payload: ['thresholdValue' => 0.8]))
		);

		self::assertCount(expectedCount: 1, haystack: $this->calls);
		self::assertSame(expected: 0.8, actual: $this->calls[0][0]['thresholdValue']);
		self::assertSame(expected: 0.6, actual: $this->calls[0][1]['thresholdValue']);
		self::assertSame(expected: 'stage-1', actual: $this->calls[0][1]['id']);
		self::assertSame(expected: 'admin', actual: $this->calls[0][2]);
	}//end testAStageUpdateReachesTheRecomputer()

	/**
	 * Without a signed-in user the change is recorded as the system's.
	 *
	 * @return void
	 */
	public function testNobodySignedInIsTheSystem(): void {
		$this->listener(uid: null)->handle(
			new ObjectUpdatedEvent($this->entity(payload: ['thresholdValue' => 2]), $this->entity(payload: ['thresholdValue' => 3]))
		);

		self::assertSame(expected: StepThresholdChangeListener::SYSTEM_ACTOR, actual: $this->calls[0][2]);
	}//end testNobodySignedInIsTheSystem()

	/**
	 * Another schema, an update without its old version and a create are skipped.
	 *
	 * @return void
	 */
	public function testOtherSchemasAndIncompleteEventsAreSkipped(): void {
		$this->listener(slug: 'decision')->handle(new ObjectUpdatedEvent($this->entity(payload: ['a' => 2]), $this->entity(payload: ['a' => 1])));
		$this->listener()->handle(new ObjectUpdatedEvent($this->entity(payload: ['a' => 2]), null));
		$this->listener()->handle(new ObjectCreatedEvent($this->entity(payload: ['a' => 2])));

		self::assertSame(expected: [], actual: $this->calls);
	}//end testOtherSchemasAndIncompleteEventsAreSkipped()

	/**
	 * A failing recomputation is logged and never breaks the save.
	 *
	 * @return void
	 */
	public function testAFailingRecomputationIsLoggedAndSwallowed(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Decidiq: threshold change not applied to its step');

		$this->listener(fail: true, logger: $logger)->handle(
			new ObjectUpdatedEvent($this->entity(payload: ['thresholdValue' => 2]), $this->entity(payload: ['thresholdValue' => 3]))
		);
	}//end testAFailingRecomputationIsLoggedAndSwallowed()

	/**
	 * The listener is subscribed to object updates.
	 *
	 * @return void
	 */
	public function testTheListenerIsSubscribedToObjectUpdates(): void {
		$subscribed = [];
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener) use (&$subscribed): void {
				$subscribed[] = [$event, $listener];
			}
		);
		(new ObjectListenerRegistrar(logger: new NullLogger()))->register(dispatcher: $dispatcher);

		self::assertContains(needle: [ObjectUpdatedEvent::class, StepThresholdChangeListener::class], haystack: $subscribed);
		self::assertNotContains(needle: [ObjectCreatedEvent::class, StepThresholdChangeListener::class], haystack: $subscribed);
	}//end testTheListenerIsSubscribedToObjectUpdates()
}//end class
