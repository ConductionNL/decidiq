<?php

/**
 * GovernanceRoleProjectionListener: roster writes reconcile, a deleted body
 * takes its scope groups with it.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\GovernanceRoleProjectionListener;
use OCA\Decidiq\Service\GovernanceRoleScopeProjector;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Entities carry their schema as a numeric id, as OpenRegister stamps them.
 */
class GovernanceRoleProjectionListenerTest extends TestCase {

	/**
	 * @var GovernanceRoleScopeProjector&MockObject
	 */
	private GovernanceRoleScopeProjector&MockObject $projector;

	private GovernanceRoleProjectionListener $listener;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->projector = $this->createMock(GovernanceRoleScopeProjector::class);

		$mapper = new class {
			/**
			 * @param string|int $id Schema id.
			 *
			 * @return Schema
			 */
			public function find(string|int $id): Schema {
				$slugs = ['11' => 'governance-body', '12' => 'participant'];
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
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * @param string               $uuid     Object uuid.
	 * @param string               $schemaId Numeric schema id.
	 * @param array<string, mixed> $payload  Stored payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, string $schemaId, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('7');
		$entity->setSchema($schemaId);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * @return void
	 */
	public function testDeletingABodyRemovesItsScopeGroups(): void {
		$this->projector->expects(self::once())->method('removeBody')->with('body-1');
		$this->projector->expects(self::never())->method('reconcileFromMemberRow');

		$this->listener->handle(new ObjectDeletedEvent($this->entity(uuid: 'body-1', schemaId: '11', payload: ['name' => 'Raad'])));
	}//end testDeletingABodyRemovesItsScopeGroups()

	/**
	 * Control: updating a body leaves its groups alone.
	 *
	 * @return void
	 */
	public function testUpdatingABodyRemovesNothing(): void {
		$this->projector->expects(self::never())->method('removeBody');
		$body = $this->entity(uuid: 'body-1', schemaId: '11', payload: ['name' => 'Raad']);

		$this->listener->handle(new ObjectUpdatedEvent($body, $body));
	}//end testUpdatingABodyRemovesNothing()

	/**
	 * A participant write, its schema stamped as an id, reconciles its body.
	 *
	 * @return void
	 */
	public function testAParticipantWriteReconcilesItsBody(): void {
		$row = ['role' => 'chair', 'nextcloudUserId' => 'alice', 'governanceBody' => 'body-1'];
		$this->projector->expects(self::once())->method('reconcileFromMemberRow')->with(
			self::callback(static fn (array $sent): bool => ($sent['governanceBody'] ?? null) === 'body-1')
		);

		$this->listener->handle(new ObjectDeletedEvent($this->entity(uuid: 'part-1', schemaId: '12', payload: $row)));
	}//end testAParticipantWriteReconcilesItsBody()
}//end class
