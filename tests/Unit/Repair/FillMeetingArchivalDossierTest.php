<?php

/**
 * Unit tests for the FillMeetingArchivalDossier repair step and the
 * MeetingDossierLink it writes through: linking, idempotency, paging and the
 * failure paths.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Repair;

use OCA\Decidiq\Repair\FillMeetingArchivalDossier;
use OCA\Decidiq\Service\Records\MeetingDossierLink;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Decidiq\Repair\FillMeetingArchivalDossier
 * @covers \OCA\Decidiq\Service\Records\MeetingDossierLink
 * @uses   \OCA\Decidiq\Service\SystemObjectStore
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-007-archive-completeness-and-compliance-dashboard
 */
class FillMeetingArchivalDossierTest extends TestCase {

	/**
	 * Objects by schema and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every write: [schema, object, uuid, rbac].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: ?string, 3: bool}>
	 */
	private array $saves = [];

	/**
	 * The offsets findAll was asked for.
	 *
	 * @var list<int>
	 */
	private array $offsets = [];

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
	 * The OpenRegister fake: find by uuid, findAll pages the dossiers, saveObject stores.
	 *
	 * @param bool $saveFails Whether every save throws.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(bool $saveFails = false): ObjectServiceInterface {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null): ?ObjectEntity {
				$data = ($this->store[(string)$options?->schema][(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				return self::entity(id: (string)$id, data: $data);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->offsets[] = (int)$config['offset'];
				$rows = [];
				foreach (($this->store[(string)$config['filters']['schema']] ?? []) as $id => $data) {
					$rows[] = self::entity(id: (string)$id, data: $data);
				}

				return ['results' => array_slice($rows, (int)$config['offset'], (int)$config['limit'])];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac = true) use ($saveFails): ObjectEntity {
				if ($saveFails === true) {
					throw new RuntimeException('locked');
				}

				$schema = (string)$options->target->schema;
				$this->saves[] = [$schema, $object, $options->uuid, $_rbac];
				$this->store[$schema][(string)$options->uuid] = $object;
				return self::entity(id: (string)$options->uuid, data: $object);
			}
		);
		return $objects;
	}//end objectService()

	/**
	 * The step over a fake store.
	 *
	 * @param ObjectServiceInterface $objects The fake
	 * @param LoggerInterface|null   $logger  The link's logger
	 *
	 * @return FillMeetingArchivalDossier
	 */
	private static function step(ObjectServiceInterface $objects, ?LoggerInterface $logger = null): FillMeetingArchivalDossier {
		return new FillMeetingArchivalDossier(
			objectService: $objects,
			meetingLink: new MeetingDossierLink(objectService: $objects, logger: ($logger ?? new NullLogger())),
			logger: new NullLogger()
		);
	}//end step()

	/**
	 * An output double expecting exactly this info line.
	 *
	 * @param string $line The expected line.
	 *
	 * @return IOutput
	 */
	private function outputSaying(string $line): IOutput {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($line);
		return $output;
	}//end outputSaying()

	/**
	 * Each run starts with an empty store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->saves = [];
		$this->offsets = [];
	}//end setUp()

	/**
	 * The step names itself for `occ maintenance:repair`.
	 *
	 * @return void
	 */
	public function testItNamesItself(): void {
		self::assertSame(expected: 'Point meetings at their archival dossier', actual: self::step(objects: $this->objectService())->getName());
	}//end testItNamesItself()

	/**
	 * Every meeting with a dossier gets the dossier's id, keeping its own
	 * fields; a meeting without a dossier and one already linked are left
	 * alone, and a second run writes nothing.
	 *
	 * @return void
	 */
	public function testMeetingsWithADossierAreLinkedOnce(): void {
		$this->store['meeting'] = [
			'm-1' => ['title' => 'Raad 1', 'lifecycle' => 'closed'],
			'm-2' => ['title' => 'Raad 2', 'lifecycle' => 'closed', 'archivalDossier' => 'd-2'],
			'm-3' => ['title' => 'Raad 3', 'lifecycle' => 'closed'],
		];
		$this->store['archival-dossier'] = [
			'd-1' => ['title' => 'Dossier 1', 'meeting' => 'm-1'],
			'd-2' => ['title' => 'Dossier 2', 'meeting' => 'm-2'],
		];
		$objects = $this->objectService();

		self::step(objects: $objects)->run(output: $this->outputSaying(line: 'FillMeetingArchivalDossier: 1 meeting(s) now name their archival dossier.'));

		self::assertCount(expectedCount: 1, haystack: $this->saves);
		[$schema, $meeting, $uuid, $rbac] = $this->saves[0];
		self::assertSame(expected: 'meeting', actual: $schema);
		self::assertSame(expected: 'm-1', actual: $uuid);
		self::assertFalse(condition: $rbac, message: 'written in system context');
		self::assertSame(expected: ['title' => 'Raad 1', 'lifecycle' => 'closed', 'archivalDossier' => 'd-1'], actual: $meeting);
		self::assertArrayNotHasKey(key: 'archivalDossier', array: $this->store['meeting']['m-3']);

		self::step(objects: $objects)->run(output: $this->outputSaying(line: 'FillMeetingArchivalDossier: 0 meeting(s) now name their archival dossier.'));
		self::assertCount(expectedCount: 1, haystack: $this->saves, message: 'a second run writes nothing');
	}//end testMeetingsWithADossierAreLinkedOnce()

	/**
	 * A full page asks for the next one; rows it cannot use still count toward the page.
	 *
	 * @return void
	 */
	public function testItPagesUntilAShortPage(): void {
		for ($i = 0; $i < 500; $i++) {
			$this->store['archival-dossier']['d-' . $i] = ['title' => 'Dossier', 'meeting' => ['not' => 'a uuid']];
		}

		$this->store['archival-dossier']['d-last'] = ['title' => 'Dossier', 'meeting' => 'm-last'];
		$this->store['meeting']['m-last'] = ['title' => 'Laatste'];

		self::step(objects: $this->objectService())
			->run(output: $this->outputSaying(line: 'FillMeetingArchivalDossier: 1 meeting(s) now name their archival dossier.'));

		self::assertSame(expected: [0, 500], actual: $this->offsets);
		self::assertSame(expected: 'd-last', actual: $this->store['meeting']['m-last']['archivalDossier']);
	}//end testItPagesUntilAShortPage()

	/**
	 * Dossiers that cannot be read link nothing, are logged, and the run still reports.
	 *
	 * @return void
	 */
	public function testUnreadableDossiersLinkNothing(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('register missing'));
		$objects->expects($this->never())->method('saveObject');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Decidiq: FillMeetingArchivalDossier found no dossiers', ['exception' => 'register missing']);

		$link = new MeetingDossierLink(objectService: $objects, logger: new NullLogger());
		(new FillMeetingArchivalDossier(objectService: $objects, meetingLink: $link, logger: $logger))
			->run(output: $this->outputSaying(line: 'FillMeetingArchivalDossier: 0 meeting(s) now name their archival dossier.'));
	}//end testUnreadableDossiersLinkNothing()

	/**
	 * A meeting that is gone links nothing; a save that fails is logged by the
	 * link and not counted, and never reaches the caller.
	 *
	 * @return void
	 */
	public function testAMissingMeetingOrAFailedSaveIsNotCounted(): void {
		$this->store['archival-dossier'] = [
			'd-gone' => ['title' => 'Weg', 'meeting' => 'm-gone'],
			'd-locked' => ['title' => 'Vast', 'meeting' => 'm-locked'],
		];
		$this->store['meeting'] = ['m-locked' => ['title' => 'Vergrendeld']];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Decidiq: a meeting could not be linked to its archival dossier',
			['meeting' => 'm-locked', 'dossier' => 'd-locked', 'error' => 'locked']
		);

		self::step(objects: $this->objectService(saveFails: true), logger: $logger)
			->run(output: $this->outputSaying(line: 'FillMeetingArchivalDossier: 0 meeting(s) now name their archival dossier.'));
	}//end testAMissingMeetingOrAFailedSaveIsNotCounted()

	/**
	 * The link refuses empty ids without reading anything.
	 *
	 * @return void
	 */
	public function testTheLinkNeedsBothIds(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->never())->method('find');
		$link = new MeetingDossierLink(objectService: $objects, logger: new NullLogger());

		self::assertFalse(condition: $link->link(meetingId: '', dossierId: 'd-1'));
		self::assertFalse(condition: $link->link(meetingId: 'm-1', dossierId: ''));
	}//end testTheLinkNeedsBothIds()
}//end class
