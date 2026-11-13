<?php

/**
 * Tests for the roster half of the governance role projection listener.
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

use OCA\Decidiq\Listener\GovernanceRoleProjectionListener;
use OCA\Decidiq\Service\GovernanceRoleScopeProjector;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A saved participant or membership reconciles the scope groups from its row;
 * other events, objects and schemas reconcile nothing, and a failing
 * projector is logged instead of breaking the write.
 *
 * @covers \OCA\Decidiq\Listener\GovernanceRoleProjectionListener
 * @uses   \OCA\Decidiq\Listener\EntityPayloadReader
 * @uses   \OCA\Decidiq\Service\ListenerSchemaResolver
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md
 */
class GovernanceRoleProjectionListenerRosterTest extends TestCase {

	/**
	 * The scope projector double.
	 *
	 * @var GovernanceRoleScopeProjector&MockObject
	 */
	private GovernanceRoleScopeProjector&MockObject $projector;

	/**
	 * The listener's logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var GovernanceRoleProjectionListener
	 */
	private GovernanceRoleProjectionListener $listener;

	/**
	 * Build the listener over a schema mapper knowing three schemas.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->projector = $this->createMock(GovernanceRoleScopeProjector::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$mapper = new class {
			/**
			 * @param string|int $id Schema id
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$slugs = ['12' => 'participant', '13' => 'membership', '14' => 'meeting'];
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

		$this->listener = new GovernanceRoleProjectionListener(
			projector: $this->projector,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class)),
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * An object of the given schema id with the given payload.
	 *
	 * @param string $schemaId The schema id
	 * @param array<string, mixed> $payload The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schemaId, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$entity->setRegister('7');
		$entity->setSchema($schemaId);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * A saved participant or membership reconciles from its own row.
	 *
	 * @return void
	 */
	public function testASavedRosterRowIsReconciled(): void {
		$rows = [];
		$this->projector->method('reconcileFromMemberRow')->willReturnCallback(
			function (array $row) use (&$rows): void {
				$rows[] = $row;
			}
		);

		$this->listener->handle(new ObjectCreatedEvent($this->entity(schemaId: '12', payload: ['body' => 'b-1', 'user' => 'alice'])));
		$this->listener->handle(new ObjectDeletedEvent($this->entity(schemaId: '13', payload: ['body' => 'b-2'])));

		self::assertSame(
			[
				['id' => 'obj-1', 'body' => 'b-1', 'user' => 'alice'],
				['id' => 'obj-1', 'body' => 'b-2'],
			],
			$rows
		);
	}//end testASavedRosterRowIsReconciled()

	/**
	 * Other events, empty events and other schemas reconcile nothing.
	 *
	 * @return void
	 */
	public function testNothingElseIsReconciled(): void {
		$this->projector->expects(self::never())->method('reconcileFromMemberRow');
		$this->projector->expects(self::never())->method('removeBody');

		$this->listener->handle(new Event());
		$this->listener->handle(new ObjectCreatedEvent(null));
		$this->listener->handle(new ObjectCreatedEvent($this->entity(schemaId: '14', payload: ['title' => 'Raad'])));
		$this->listener->handle(new ObjectCreatedEvent($this->entity(schemaId: '99', payload: ['user' => 'alice'])));
	}//end testNothingElseIsReconciled()

	/**
	 * A failing projector is logged and the write goes on.
	 *
	 * @return void
	 */
	public function testAFailingProjectorIsLogged(): void {
		$this->projector->method('reconcileFromMemberRow')->willThrowException(new RuntimeException('groups unavailable'));
		$this->logger->expects(self::once())->method('warning')->with(
			'Decidiq: governance role projection listener failed',
			['exception' => 'groups unavailable']
		);

		$this->listener->handle(new ObjectCreatedEvent($this->entity(schemaId: '12', payload: ['user' => 'alice'])));
	}//end testAFailingProjectorIsLogged()
}//end class
