<?php

/**
 * MeetingDossierBackfill: every meeting with an archival dossier is pointed at
 * it, and the count says how many changed.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Records;

use OCA\Decidiq\Service\Records\MeetingDossierBackfill;
use OCA\Decidiq\Service\Records\MeetingDossierLink;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for MeetingDossierBackfill.
 */
class MeetingDossierBackfillTest extends TestCase {

	/**
	 * Objects by schema and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * An entity as OpenRegister returns it.
	 *
	 * @param string               $id   The uuid
	 * @param array<string, mixed> $data The object
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $id, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The backfill over the in-memory store.
	 *
	 * @return MeetingDossierBackfill
	 */
	private function backfill(): MeetingDossierBackfill {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null): ?ObjectEntity {
				$data = ($this->store[(string)$options?->schema][(string)$id] ?? null);
				return $data === null ? null : self::entity(id: (string)$id, data: $data);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$rows = [];
				foreach (($this->store[(string)$config['filters']['schema']] ?? []) as $id => $data) {
					$rows[] = self::entity(id: (string)$id, data: $data);
				}

				return ['results' => array_slice($rows, (int)$config['offset'], (int)$config['limit'])];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntity {
				$this->store[(string)$options->target->schema][(string)$options->uuid] = $object;
				return self::entity(id: (string)$options->uuid, data: $object);
			}
		);

		return new MeetingDossierBackfill(
			objectService: $objects,
			meetingLink: new MeetingDossierLink(objectService: $objects, logger: new NullLogger()),
			logger: new NullLogger()
		);
	}//end backfill()

	/**
	 * Imported dossiers name their meetings; afterwards the meetings name them,
	 * and a second run changes nothing.
	 *
	 * @return void
	 */
	public function testImportedDossiersAreNamedByTheirMeetings(): void {
		$this->store['meeting'] = [
			'm-1' => ['title' => 'Raadsvergadering 15 januari 2025', 'lifecycle' => 'closed'],
			'm-2' => ['title' => 'Raadsvergadering 10 april 2025', 'lifecycle' => 'closed'],
			'm-3' => ['title' => 'Auditcommissie', 'lifecycle' => 'closed'],
		];
		$this->store['archival-dossier'] = [
			'd-1' => ['title' => 'Dossier 1', 'meeting' => 'm-1'],
			'd-2' => ['title' => 'Dossier 2', 'meeting' => 'm-2'],
		];
		$backfill = $this->backfill();

		self::assertSame(expected: 2, actual: $backfill->fill());
		self::assertSame(expected: 'd-1', actual: $this->store['meeting']['m-1']['archivalDossier']);
		self::assertSame(expected: 'd-2', actual: $this->store['meeting']['m-2']['archivalDossier']);
		self::assertArrayNotHasKey(key: 'archivalDossier', array: $this->store['meeting']['m-3']);
		self::assertSame(expected: 0, actual: $backfill->fill());
	}//end testImportedDossiersAreNamedByTheirMeetings()

	/**
	 * Without dossiers nothing is written.
	 *
	 * @return void
	 */
	public function testNoDossiersLinkNothing(): void {
		$this->store['meeting'] = ['m-1' => ['title' => 'Raad']];

		self::assertSame(expected: 0, actual: $this->backfill()->fill());
		self::assertArrayNotHasKey(key: 'archivalDossier', array: $this->store['meeting']['m-1']);
	}//end testNoDossiersLinkNothing()
}//end class
