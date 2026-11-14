<?php

/**
 * Unit tests for AgendaItemConfidentialityListener.
 *
 * Built on production-shaped OpenRegister object events: the entity carries a
 * numeric schema id that the real ListenerSchemaResolver turns into a slug.
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Listener;

use OCA\Decidiq\Listener\AgendaItemConfidentialityListener;
use OCA\Decidiq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A non-public item closes its papers; an imposed item restriction closes the item.
 *
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item
 */
class AgendaItemConfidentialityListenerTest extends TestCase {

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The listener under test.
	 *
	 * @var AgendaItemConfidentialityListener
	 */
	private AgendaItemConfidentialityListener $listener;

	/**
	 * Every saveObject() call, as [schema, uuid, object].
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * Set up the listener with the real schema resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				self::assertSame('decidiq', $register);
				self::assertFalse($_rbac, 'closing is a system invariant, not the saver\'s right');
				$this->saved[] = [(string)$schema, (string)$uuid, $object];
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
				$slugs = ['41' => 'agenda-item', '52' => 'digital-document', '83' => 'confidentiality-restriction', '93' => 'meeting'];
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

		$this->listener = new AgendaItemConfidentialityListener(
			objectService: $this->objectService,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class)),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A production-shaped entity.
	 *
	 * @param string               $uuid     Object id.
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
	 * Making item 7 non-public closes its public papers and leaves closed ones alone.
	 *
	 * @return void
	 */
	public function testANonPublicItemClosesItsPublicPapers(): void {
		$this->objectService->expects(self::once())
			->method('findAll')
			->with(
				['filters' => ['register' => 'decidiq', 'schema' => 'digital-document', 'agendaItem' => 'item-7'], 'limit' => 500],
				false,
				false
			)
			->willReturn([
				$this->entity(uuid: 'paper-1', schemaId: '52', payload: ['title' => 'Kostenraming verlichting', 'agendaItem' => 'item-7']),
				$this->entity(uuid: 'paper-2', schemaId: '52', payload: ['title' => 'Oud stuk', 'agendaItem' => 'item-7', 'public' => false]),
				$this->entity(uuid: 'paper-3', schemaId: '52', payload: ['title' => 'Bijlage', 'agendaItem' => 'item-7', 'public' => true]),
			]);

		$old = $this->entity(uuid: 'item-7', schemaId: '41', payload: ['title' => 'Verlichting fietspaden 2027', 'public' => true]);
		$new = $this->entity(uuid: 'item-7', schemaId: '41', payload: ['title' => 'Verlichting fietspaden 2027', 'public' => false]);
		$this->listener->handle(new ObjectUpdatedEvent($new, $old));

		self::assertSame([
			['digital-document', 'paper-1', ['id' => 'paper-1', 'title' => 'Kostenraming verlichting', 'agendaItem' => 'item-7', 'public' => false]],
			['digital-document', 'paper-3', ['id' => 'paper-3', 'title' => 'Bijlage', 'agendaItem' => 'item-7', 'public' => false]],
		], $this->saved);
	}//end testANonPublicItemClosesItsPublicPapers()

	/**
	 * A public item, or an item without the field, touches no paper.
	 *
	 * @return void
	 */
	public function testAPublicItemTouchesNoPaper(): void {
		$this->objectService->expects(self::never())->method('findAll');

		$this->listener->handle(new ObjectCreatedEvent($this->entity(uuid: 'item-6', schemaId: '41', payload: ['public' => true])));
		$this->listener->handle(new ObjectCreatedEvent($this->entity(uuid: 'item-8', schemaId: '41', payload: ['title' => 'x'])));
		self::assertSame([], $this->saved);
	}//end testAPublicItemTouchesNoPaper()

	/**
	 * A paper added to a non-public item is saved with public false.
	 *
	 * @return void
	 */
	public function testAPaperAddedToANonPublicItemIsSavedClosed(): void {
		$this->objectService->expects(self::once())
			->method('find')
			->with('item-7', [], false, 'decidiq', 'agenda-item', false, false)
			->willReturn($this->entity(uuid: 'item-7', schemaId: '41', payload: ['public' => false]));

		$event = new ObjectCreatingEvent($this->entity(uuid: 'paper-9', schemaId: '52', payload: ['title' => 'Advies Fietsersbond', 'agendaItem' => 'item-7']));
		$this->listener->handle($event);

		self::assertSame(['public' => false], $event->getModifiedData());
	}//end testAPaperAddedToANonPublicItemIsSavedClosed()

	/**
	 * A paper moved onto a non-public item on update is closed too, keeping other modified data.
	 *
	 * @return void
	 */
	public function testAPaperMovedOntoANonPublicItemIsClosed(): void {
		$this->objectService->method('find')->willReturn($this->entity(uuid: 'item-7', schemaId: '41', payload: ['public' => false]));

		$old = $this->entity(uuid: 'paper-9', schemaId: '52', payload: ['title' => 'Advies', 'agendaItem' => 'item-6']);
		$new = $this->entity(uuid: 'paper-9', schemaId: '52', payload: ['title' => 'Advies', 'agendaItem' => 'item-7']);
		$event = new ObjectUpdatingEvent($new, $old);
		$event->setModifiedData(['documentType' => 'advies']);
		$this->listener->handle($event);

		self::assertSame(['documentType' => 'advies', 'public' => false], $event->getModifiedData());
	}//end testAPaperMovedOntoANonPublicItemIsClosed()

	/**
	 * A paper of a public item, or of no item, stays as it is.
	 *
	 * @return void
	 */
	public function testAPaperOfAPublicItemOrNoItemStaysAsItIs(): void {
		$this->objectService->expects(self::once())->method('find')
			->willReturn($this->entity(uuid: 'item-6', schemaId: '41', payload: ['public' => true]));

		$public = new ObjectCreatingEvent($this->entity(uuid: 'paper-1', schemaId: '52', payload: ['agendaItem' => 'item-6']));
		$this->listener->handle($public);
		$loose = new ObjectCreatingEvent($this->entity(uuid: 'paper-2', schemaId: '52', payload: ['title' => 'Los stuk']));
		$this->listener->handle($loose);

		self::assertSame([], $public->getModifiedData());
		self::assertSame([], $loose->getModifiedData());
	}//end testAPaperOfAPublicItemOrNoItemStaysAsItIs()

	/**
	 * An imposed item restriction closes the item and its papers.
	 *
	 * @return void
	 */
	public function testAnImposedRestrictionClosesTheItemAndItsPapers(): void {
		$this->objectService->expects(self::once())
			->method('find')
			->with('item-9', [], false, 'decidiq', 'agenda-item', false, false)
			->willReturn($this->entity(uuid: 'item-9', schemaId: '41', payload: ['title' => 'Grondaankoop', 'public' => true]));
		$this->objectService->expects(self::once())->method('findAll')
			->willReturn([$this->entity(uuid: 'paper-4', schemaId: '52', payload: ['agendaItem' => 'item-9'])]);

		$restriction = $this->entity(uuid: 'gh-1', schemaId: '83', payload: [
			'scope' => 'item', 'targetAgendaItem' => 'item-9', 'lifecycle' => 'imposed', 'ground' => 'Gemeentewet art. 87',
		]);
		$this->listener->handle(new ObjectCreatedEvent($restriction));

		self::assertSame([
			['agenda-item', 'item-9', ['id' => 'item-9', 'title' => 'Grondaankoop', 'public' => false]],
			['digital-document', 'paper-4', ['id' => 'paper-4', 'agendaItem' => 'item-9', 'public' => false]],
		], $this->saved);
	}//end testAnImposedRestrictionClosesTheItemAndItsPapers()

	/**
	 * Lifting a restriction, a restriction on a document, and re-saving an imposed one change nothing.
	 *
	 * @return void
	 */
	public function testLiftingOrOtherScopesChangeNothing(): void {
		$this->objectService->expects(self::never())->method('find');
		$this->objectService->expects(self::never())->method('findAll');

		$imposed = ['scope' => 'item', 'targetAgendaItem' => 'item-9', 'lifecycle' => 'imposed'];
		$lifted = $this->entity(uuid: 'gh-1', schemaId: '83', payload: ['lifecycle' => 'dissolved'] + $imposed);
		$this->listener->handle(new ObjectUpdatedEvent($lifted, $this->entity(uuid: 'gh-1', schemaId: '83', payload: $imposed)));

		$again = $this->entity(uuid: 'gh-1', schemaId: '83', payload: $imposed + ['note' => 'typo fixed']);
		$this->listener->handle(new ObjectUpdatedEvent($again, $this->entity(uuid: 'gh-1', schemaId: '83', payload: $imposed)));

		$document = $this->entity(uuid: 'gh-2', schemaId: '83', payload: ['scope' => 'document', 'targetDocument' => 'paper-1', 'lifecycle' => 'imposed']);
		$this->listener->handle(new ObjectCreatedEvent($document));

		self::assertSame([], $this->saved);
	}//end testLiftingOrOtherScopesChangeNothing()

	/**
	 * A restriction moved to imposed on update closes an item that is already closed only through its papers.
	 *
	 * @return void
	 */
	public function testARestrictionImposedOnUpdateOnAClosedItemClosesOnlyThePapers(): void {
		$this->objectService->method('find')->willReturn($this->entity(uuid: 'item-9', schemaId: '41', payload: ['public' => false]));
		$this->objectService->method('findAll')->willReturn([]);

		$old = $this->entity(uuid: 'gh-1', schemaId: '83', payload: ['scope' => 'item', 'targetAgendaItem' => 'item-9', 'lifecycle' => 'ratified']);
		$new = $this->entity(uuid: 'gh-1', schemaId: '83', payload: ['scope' => 'item', 'targetAgendaItem' => 'item-9', 'lifecycle' => 'imposed']);
		$this->objectService->expects(self::once())->method('findAll');
		$this->listener->handle(new ObjectUpdatedEvent($new, $old));

		self::assertSame([], $this->saved, 'an item that is already closed is not saved again');
	}//end testARestrictionImposedOnUpdateOnAClosedItemClosesOnlyThePapers()

	/**
	 * A failing store is logged, never thrown into the save.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsLoggedNotThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('Decidiq: agenda item confidentiality not applied');
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('database gone'));
		$mapper = new class {
			/**
			 * Resolve a schema by id.
			 *
			 * @param string|int $id Schema id.
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
		$listener = new AgendaItemConfidentialityListener(
			objectService: $objects,
			schemaResolver: new ListenerSchemaResolver(container: $container, logger: $this->createMock(LoggerInterface::class)),
			logger: $logger,
		);

		$listener->handle(new ObjectCreatedEvent($this->entity(uuid: 'item-7', schemaId: '41', payload: ['public' => false])));
	}//end testAFailingStoreIsLoggedNotThrown()
}//end class
