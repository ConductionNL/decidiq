<?php

/**
 * Test Suite for ActionItemMigrationService
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-010-per-user-legacy-to-vtodo-migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Kernel\Contracts\ActionItemWriterInterface;
use OCA\Decidiq\Service\ActionItemMigrationService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Mapping, markers, archive-after-success and delegation folding.
 *
 * @covers \OCA\Decidiq\Service\ActionItemMigrationService
 *
 * @spec openspec/changes/action-item-vtodo-migration/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-011-idempotent-archive-not-delete
 */
class ActionItemMigrationServiceTest extends TestCase {

	private ObjectServiceInterface&MockObject $objectService;
	private ActionItemWriterInterface&MockObject $writer;
	private ActionItemMigrationService $service;

	/**
	 * Legacy rows per schema, as findAll answers them.
	 *
	 * @var array<string, list<mixed>>
	 */
	private array $legacy = [];

	/**
	 * Set up the service over the legacy rows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('findAll')->willReturnCallback(
			fn (array $config): array => ($this->legacy[$config['filters']['schema']] ?? [])
		);
		$this->writer  = $this->createMock(ActionItemWriterInterface::class);
		$this->service = new ActionItemMigrationService(objectService: $this->objectService, writer: $this->writer, logger: $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * A legacy object entity.
	 *
	 * @param string               $uuid  Its uuid.
	 * @param string               $owner Its owner.
	 * @param array<string, mixed> $data  Its data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(string $uuid, string $owner, array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getOwner')->willReturn($owner);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A legacy task becomes the owner's VTODO with its fields mapped, then is marked and archived.
	 *
	 * @return void
	 */
	public function testALegacyTaskBecomesAVtodoThenIsMarkedAndArchived(): void {
		$task = ['title' => 'Plan van aanpak zonnepanelen opstellen', 'assignee' => 'raadslid', 'dueDate' => '2026-07-01', 'taskStatus' => 'in_progress', 'meeting' => 'm-1'];
		$this->legacy['task'] = [$this->entity(uuid: 't-1', owner: 'raadslid', data: $task)];
		$this->writer->method('findByField')->willReturn(null);
		$this->writer->expects(self::once())
			->method('create')
			->with(
				[
					'title' => 'Plan van aanpak zonnepanelen opstellen',
					'taskStatus' => 'in-progress',
					'_migratedFromLegacyUuid' => 't-1',
					'assignee' => 'raadslid',
					'dueDate' => '2026-07-01',
					'meeting' => 'm-1',
				]
			)
			->willReturn(['uid' => 'vtodo-1']);

		$calls = [];
		$this->objectService->expects(self::once())
			->method('saveObject')
			->with(self::callback(static fn (array $object): bool => $object['_migratedToVtodo'] === 'vtodo-1'), self::anything(), 'decidiq', 'task', 't-1')
			->willReturnCallback(function () use (&$calls): ObjectEntityInterface {
				$calls[] = 'mark';
				return $this->createMock(ObjectEntityInterface::class);
			});
		$this->objectService->expects(self::once())
			->method('deleteObject')
			->with('t-1', 'decidiq', 'task', false, false, false, null, false)
			->willReturnCallback(function () use (&$calls): bool {
				$calls[] = 'archive';
				return true;
			});

		$counts = $this->service->migrateUser(userId: 'raadslid');

		self::assertSame(['mark', 'archive'], $calls);
		self::assertSame(['migrated' => 1, 'delegations' => 0, 'skipped' => 0, 'failed' => 0], $counts);
	}//end testALegacyTaskBecomesAVtodoThenIsMarkedAndArchived()

	/**
	 * A failed VTODO write leaves the legacy object unmarked and unarchived.
	 *
	 * @return void
	 */
	public function testAFailedWriteLeavesTheLegacyObjectIntact(): void {
		$this->legacy['ActionItem'] = [$this->entity(uuid: 'a-1', owner: 'u', data: ['title' => 'Notulen vaststellen'])];
		$this->writer->method('findByField')->willReturn(null);
		$this->writer->method('create')->willReturn(null);
		$this->objectService->expects(self::never())->method('saveObject');
		$this->objectService->expects(self::never())->method('deleteObject');

		self::assertSame(1, $this->service->migrateUser(userId: 'u')['failed']);
	}//end testAFailedWriteLeavesTheLegacyObjectIntact()

	/**
	 * Marked objects are skipped; a VTODO that already carries the uuid is reused, not duplicated.
	 *
	 * @return void
	 */
	public function testARerunCreatesNoDuplicate(): void {
		$this->legacy['task'] = [
			$this->entity(uuid: 't-1', owner: 'u', data: ['title' => 'Done', '_migratedToVtodo' => 'vtodo-1']),
			$this->entity(uuid: 't-2', owner: 'u', data: ['title' => 'Interrupted']),
		];
		$this->writer->expects(self::once())->method('findByField')->with('_migratedFromLegacyUuid', 't-2')->willReturn(['uid' => 'vtodo-2']);
		$this->writer->expects(self::never())->method('create');
		$this->objectService->expects(self::once())
			->method('saveObject')
			->with(self::callback(static fn (array $object): bool => $object['_migratedToVtodo'] === 'vtodo-2'))
			->willReturn($this->createMock(ObjectEntityInterface::class));

		self::assertSame(1, $this->service->migrateUser(userId: 'u')['migrated']);
	}//end testARerunCreatesNoDuplicate()

	/**
	 * No legacy data, or a schema that no longer exists, is a clean no-op.
	 *
	 * @return void
	 */
	public function testNoLegacyDataIsANoOp(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willThrowException(new RuntimeException('Schema not found'));
		$objectService->expects(self::never())->method('saveObject');
		$this->writer->expects(self::never())->method('create');
		$service = new ActionItemMigrationService(objectService: $objectService, writer: $this->writer, logger: $this->createMock(LoggerInterface::class));

		self::assertSame([], $service->pendingOwners());
		self::assertSame(['migrated' => 0, 'delegations' => 0, 'skipped' => 0, 'failed' => 0], $service->migrateUser(userId: 'u'));
	}//end testNoLegacyDataIsANoOp()

	/**
	 * Only another owner's objects are left alone.
	 *
	 * @return void
	 */
	public function testAnotherOwnersObjectsAreLeftAlone(): void {
		$this->legacy['task'] = [$this->entity(uuid: 't-1', owner: 'other', data: ['title' => 'Niet van u'])];
		$this->writer->expects(self::never())->method('create');

		self::assertSame(0, $this->service->migrateUser(userId: 'u')['migrated']);
		self::assertSame(['other'], $this->service->pendingOwners());
	}//end testAnotherOwnersObjectsAreLeftAlone()

	/**
	 * A delegation sets the target VTODO's assignee with a note and creates no VTODO of its own.
	 *
	 * @return void
	 */
	public function testADelegationFoldsOntoTheAssignee(): void {
		$this->legacy['delegation'] = [$this->entity(uuid: 'd-1', owner: 'u', data: ['task' => 't-1', 'delegator' => 'u', 'substitute' => 'wethouder', 'status' => 'active'])];
		$this->writer->method('findByField')->with('_migratedFromLegacyUuid', 't-1')->willReturn(['uid' => 'vtodo-1']);
		$this->writer->expects(self::never())->method('create');
		$this->writer->expects(self::once())
			->method('update')
			->with('vtodo-1', ['assignee' => 'wethouder', 'delegationNote' => 'Delegated to wethouder (migrated from legacy delegation d-1)'])
			->willReturn(['uid' => 'vtodo-1']);
		$this->objectService->expects(self::once())->method('saveObject')->willReturn($this->createMock(ObjectEntityInterface::class));
		$this->objectService->expects(self::once())->method('deleteObject')->willReturn(true);

		self::assertSame(1, $this->service->migrateUser(userId: 'u')['delegations']);
	}//end testADelegationFoldsOntoTheAssignee()

	/**
	 * A delegation whose item is not projected yet stays for a later run.
	 *
	 * @return void
	 */
	public function testADelegationWithoutItsItemWaits(): void {
		$this->legacy['delegation'] = [$this->entity(uuid: 'd-1', owner: 'u', data: ['task' => 't-9', 'substitute' => 'p'])];
		$this->writer->method('findByField')->willReturn(null);
		$this->writer->expects(self::never())->method('update');
		$this->objectService->expects(self::never())->method('saveObject');

		self::assertSame(1, $this->service->migrateUser(userId: 'u')['skipped']);
	}//end testADelegationWithoutItsItemWaits()

	/**
	 * A reclaimed delegation hands the item back to the delegator.
	 *
	 * @return void
	 */
	public function testAReclaimedDelegationReturnsToTheDelegator(): void {
		self::assertSame('u', ActionItemMigrationService::effectiveAssignee(['delegator' => 'u', 'substitute' => 'p', 'status' => 'revoked']));
		self::assertSame('p', ActionItemMigrationService::effectiveAssignee(['delegator' => 'u', 'delegate' => 'p']));
	}//end testAReclaimedDelegationReturnsToTheDelegator()
}//end class
