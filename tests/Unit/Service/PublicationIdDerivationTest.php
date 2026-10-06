<?php

/**
 * Unit tests for how publication saves derive the saved object's id.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/public-publication/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\OpenCatalogiPublisher;
use OCA\Decidiq\Service\PublicationRepository;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The id comes from jsonSerialize() only, and a miss is logged (#400).
 *
 * `ObjectEntity::getUuid()` is served by `Entity::__call()`, so the old
 * `method_exists($entity, 'getUuid')` branches never ran in production. These
 * tests pin the serialized-id path and the loud miss that replace them.
 *
 * @spec openspec/specs/public-publication/spec.md
 */
class PublicationIdDerivationTest extends TestCase {

	/**
	 * Build an ObjectEntity double that serializes to the given row.
	 *
	 * @param array<string,mixed> $row The serialized row.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * Build a PublicationRepository whose saveObject() returns the given value.
	 *
	 * @param mixed $saved The saveObject() return value.
	 * @param LoggerInterface $logger The logger double.
	 *
	 * @return PublicationRepository
	 */
	private function repository(mixed $saved, LoggerInterface $logger): PublicationRepository {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObject')->willReturn($saved);
		return new PublicationRepository($logger, $objectService);
	}//end repository()

	/**
	 * Build an OpenCatalogiPublisher whose catalog saveObject() returns the given value.
	 *
	 * @param mixed $publication The saveObject() return value.
	 * @param LoggerInterface $logger The logger double.
	 *
	 * @return OpenCatalogiPublisher
	 */
	private function publisher(mixed $publication, LoggerInterface $logger): OpenCatalogiPublisher {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObject')->willReturn($publication);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(true);

		return new OpenCatalogiPublisher($container, $appManager, $logger);
	}//end publisher()

	/**
	 * A saved entity's id is read from its serialized row, without a warning.
	 *
	 * @return void
	 */
	public function testRepositoryReadsTheSerializedId(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$repository = $this->repository(saved: $this->entity(row: ['id' => 'uuid-1', 'title' => 'x']), logger: $logger);

		$this->assertSame('uuid-1', $repository->persistPayload(payload: ['title' => 'x']));
	}//end testRepositoryReadsTheSerializedId()

	/**
	 * The @self.id is used when the row has no top-level id.
	 *
	 * @return void
	 */
	public function testRepositoryFallsBackToSelfId(): void {
		$logger = $this->createMock(LoggerInterface::class);

		$repository = $this->repository(saved: $this->entity(row: ['@self' => ['id' => 'uuid-2']]), logger: $logger);

		$this->assertSame('uuid-2', $repository->persistRecord(record: ['title' => 'x']));
	}//end testRepositoryFallsBackToSelfId()

	/**
	 * A saved entity with no derivable id yields '' and logs a warning.
	 *
	 * @return void
	 */
	public function testRepositoryLogsWhenNoIdCanBeDerived(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('no derivable id'));

		$repository = $this->repository(saved: $this->entity(row: ['title' => 'x']), logger: $logger);

		$this->assertSame('', $repository->persistPayload(payload: ['title' => 'x']));
	}//end testRepositoryLogsWhenNoIdCanBeDerived()

	/**
	 * The catalog reference is read from the serialized publication.
	 *
	 * @return void
	 */
	public function testPublisherReadsTheSerializedReference(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$publisher = $this->publisher(publication: $this->entity(row: ['id' => 'pub-1']), logger: $logger);

		$this->assertSame('pub-1', $publisher->publish(catalogId: 'cat-1', payloadId: 'payload-1', payload: ['title' => 'x']));
	}//end testPublisherReadsTheSerializedReference()

	/**
	 * A publication with no derivable reference yields '' and logs a warning.
	 *
	 * @return void
	 */
	public function testPublisherLogsWhenNoReferenceCanBeDerived(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('no derivable reference'));

		$publisher = $this->publisher(publication: $this->entity(row: ['title' => 'x']), logger: $logger);

		$this->assertSame('', $publisher->publish(catalogId: 'cat-1', payloadId: 'payload-1', payload: ['title' => 'x']));
	}//end testPublisherLogsWhenNoReferenceCanBeDerived()
}//end class
