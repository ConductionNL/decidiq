<?php

/**
 * Unit tests for ApprovalBasisChangeListener (REQ-DWP-003), with OpenRegister's
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Listener\ApprovalBasisChangeListener;
use OCA\Decidiq\Listener\Registrar\ObjectListenerRegistrar;
use OCA\Decidiq\Service\ApprovalStalenessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ApprovalBasisChangeListener.
 */
final class ApprovalBasisChangeListenerTest extends TestCase {
	/**
	 * The calls the service received: [subject, before, after].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A listener whose schema resolver answers the given slug.
	 *
	 * @param string    $slug  The schema slug of every entity.
	 * @param bool      $fail  Whether the service throws.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return ApprovalBasisChangeListener
	 */
	private function listener(string $slug = 'decision', bool $fail = false, ?LoggerInterface $logger = null): ApprovalBasisChangeListener {
		$service = $this->createMock(originalClassName: ApprovalStalenessService::class);
		$service->method('subjectChanged')->willReturnCallback(
			function (string $subject, array $before, array $after) use ($fail): array {
				if ($fail === true) {
					throw new RuntimeException('store down');
				}

				$this->calls[] = [$subject, $before, $after];
				return [];
			}
		);

		$resolver = $this->createMock(originalClassName: ListenerSchemaResolverInterface::class);
		$resolver->method('schemaSlug')->willReturn($slug);

		return new ApprovalBasisChangeListener(
			staleness: $service,
			schemaResolver: $resolver,
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
		$entity->setUuid('dec-1');
		$entity->setRegister('7');
		$entity->setSchema('60');
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * An update hands the subject with both versions to the service.
	 *
	 * @return void
	 */
	public function testAnUpdateHandsBothVersionsToTheService(): void {
		$event = new ObjectUpdatedEvent($this->entity(payload: ['body' => 'Version two']), $this->entity(payload: ['body' => 'Version one']));
		$this->listener()->handle($event);

		self::assertCount(expectedCount: 1, haystack: $this->calls);
		self::assertSame(expected: 'dec-1', actual: $this->calls[0][0]);
		self::assertSame(expected: 'Version one', actual: $this->calls[0][1]['body']);
		self::assertSame(expected: 'Version two', actual: $this->calls[0][2]['body']);
	}//end testAnUpdateHandsBothVersionsToTheService()

	/**
	 * The route's own rows, an update without its old version and a create are not subjects.
	 *
	 * @return void
	 */
	public function testTheRoutesOwnRowsAndIncompleteEventsAreSkipped(): void {
		foreach (ApprovalBasisChangeListener::ROUTE_SCHEMAS as $slug) {
			$this->listener(slug: $slug)->handle(new ObjectUpdatedEvent($this->entity(payload: ['a' => 2]), $this->entity(payload: ['a' => 1])));
		}

		$this->listener()->handle(new ObjectUpdatedEvent($this->entity(payload: ['a' => 2]), null));
		$this->listener()->handle(new ObjectCreatedEvent($this->entity(payload: ['a' => 2])));

		self::assertSame(expected: [], actual: $this->calls);
	}//end testTheRoutesOwnRowsAndIncompleteEventsAreSkipped()

	/**
	 * A failing withdrawal is logged and never breaks the save.
	 *
	 * @return void
	 */
	public function testAFailingWithdrawalIsLoggedAndSwallowed(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Decidiq: stale approvals not withdrawn');

		$this->listener(fail: true, logger: $logger)->handle(
			new ObjectUpdatedEvent($this->entity(payload: ['body' => 'b']), $this->entity(payload: ['body' => 'a']))
		);
	}//end testAFailingWithdrawalIsLoggedAndSwallowed()

	/**
	 * The listener is subscribed to every object update.
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

		self::assertContains(needle: [ObjectUpdatedEvent::class, ApprovalBasisChangeListener::class], haystack: $subscribed);
		self::assertNotContains(needle: [ObjectCreatedEvent::class, ApprovalBasisChangeListener::class], haystack: $subscribed);
	}//end testTheListenerIsSubscribedToObjectUpdates()
}//end class
