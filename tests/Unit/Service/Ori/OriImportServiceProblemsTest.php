<?php

/**
 * The ORI import's refusals: every record it cannot translate is reported, not stored half-way.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Ori;

use OCA\Decidiq\Service\Ori\OriExportMapper;
use OCA\Decidiq\Tests\Unit\Support\SaveCallShape;
use OCA\Decidiq\Service\Ori\OriImportReport;
use OCA\Decidiq\Service\Ori\OriImportService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriImportService::class)]
#[UsesClass(OriExportMapper::class)]
#[UsesClass(OriImportReport::class)]
final class OriImportServiceProblemsTest extends TestCase {

	private InMemoryObjects $store;

	private OriImportService $import;

	protected function setUp(): void {
		$this->store = new InMemoryObjects();
		$this->import = new OriImportService($this->store->service($this), new OriExportMapper());
		$this->store->seed('ori', 'vergadering', ['id' => 'v1', 'slug' => 'raad-1', 'name' => 'Raad', 'type' => 'raadsvergadering', 'status' => 'confirmed', 'startDate' => '2026-01-10T19:30:00+01:00', 'organisation' => 'Gemeenteraad Voorbeeldstad']);
		$this->store->seed('ori', 'agendapunt', ['id' => 'a1', 'slug' => 'ap-1', 'subject' => 'Motie', 'sortOrder' => 1, 'vergadering' => 'raad-1']);
	}

	/**
	 * Seed records of one source schema.
	 *
	 * @param string                           $schema  The ORI schema.
	 * @param array<int, array<string, mixed>> $records The records.
	 *
	 * @return void
	 */
	private function seed(string $schema, array $records): void {
		foreach ($records as $record) {
			$this->store->seed('ori', $schema, $record);
		}
	}

	public function testABodyWithoutANameIsSkippedAndAnUnknownClassificationIsReported(): void {
		$this->seed('fractie', [
			['id' => 'f1', 'slug' => 'leeg', 'name' => '  ', 'zetels' => 2],
			['id' => 'f2', 'slug' => 'gedoogd', 'name' => 'Gedoogpartij', 'classification' => 'gedoogpartij'],
		]);

		$report = $this->import->import();

		$this->assertContains(['schema' => 'fractie', 'source' => 'f1', 'problem' => 'has no name'], $report->problems());
		$this->assertContains(['schema' => 'fractie', 'source' => 'f2', 'problem' => 'unknown classification "gedoogpartij"'], $report->problems());
		$groups = array_values(array_filter($this->store->all('decidiq', 'governance-body'), static fn (array $o): bool => ($o['bodyType'] ?? '') === 'political-group'));
		$this->assertCount(1, $groups);
		$this->assertSame('Gedoogpartij', $groups[0]['name']);
		$this->assertArrayNotHasKey('coalitionRole', $groups[0]);
		$this->assertArrayNotHasKey('seatCount', $groups[0]);
	}

	public function testAPersonWithoutANameIsSkippedAndAnUnknownRoleIsReported(): void {
		$this->seed('raadslid', [
			['id' => 'r1', 'slug' => 'leeg', 'name' => '', 'role' => 'councilMember'],
			['id' => 'r2', 'slug' => 'gast', 'name' => 'G. Gast', 'role' => 'wethouder'],
		]);

		$report = $this->import->import();

		$this->assertContains(['schema' => 'raadslid', 'source' => 'r1', 'problem' => 'has no name'], $report->problems());
		$this->assertContains(['schema' => 'raadslid', 'source' => 'r2', 'problem' => 'unknown role "wethouder"'], $report->problems());
		$this->assertSame(['G. Gast'], array_column($this->store->all('decidiq', 'person'), 'name'));
	}

	public function testAMeetingWithoutAStartDateOrWithAnUnknownStatusIsNotImported(): void {
		$this->seed('vergadering', [
			['id' => 'v2', 'slug' => 'zonder', 'name' => 'Zonder datum', 'type' => 'raadsvergadering', 'status' => 'confirmed'],
			['id' => 'v3', 'slug' => 'raar', 'name' => 'Rare status', 'type' => 'raadsvergadering', 'status' => 'misschien', 'startDate' => '2026-02-01'],
		]);

		$report = $this->import->import();

		$this->assertContains(['schema' => 'vergadering', 'source' => 'v2', 'problem' => 'has no start date'], $report->problems());
		$this->assertContains(['schema' => 'vergadering', 'source' => 'v3', 'problem' => 'unknown status "misschien"'], $report->problems());
		$this->assertSame(['ori:v1'], array_column($this->store->all('decidiq', 'meeting'), 'externalReference'));
	}

	public function testAMeetingOfAnotherOrganisationGetsItsOwnCouncil(): void {
		$this->seed('vergadering', [
			['id' => 'v4', 'slug' => 'buur', 'name' => 'Buurraad', 'type' => 'raadsvergadering', 'status' => 'confirmed', 'startDate' => '2026-03-01', 'organisation' => 'Gemeenteraad Buurstad'],
		]);

		$this->import->import();

		$own = $this->store->tagged('governance-body', 'ori:council:Gemeenteraad Voorbeeldstad');
		$other = $this->store->tagged('governance-body', 'ori:council:Gemeenteraad Buurstad');
		$this->assertSame('legislative', $other['bodyType']);
		$this->assertNotSame($own['id'], $other['id']);
		$this->assertSame($other['id'], $this->store->tagged('meeting', 'ori:v4')['governanceBody']);
		$this->assertSame($own['id'], $this->store->tagged('meeting', 'ori:v1')['governanceBody']);
	}

	public function testACommitteeReachedByTwoMeetingsIsWrittenOnce(): void {
		$this->seed('vergadering', [
			['id' => 'v5', 'slug' => 'cie-1', 'name' => 'Commissie 1', 'type' => 'commissievergadering', 'status' => 'planned', 'startDate' => '2026-03-01', 'committee' => 'Commissie Ruimte'],
			['id' => 'v6', 'slug' => 'cie-2', 'name' => 'Commissie 2', 'type' => 'commissievergadering', 'status' => 'planned', 'startDate' => '2026-04-01', 'committee' => 'Commissie Ruimte'],
		]);

		$this->import->import();

		$committee = $this->store->tagged('governance-body', 'ori:committee:Commissie Ruimte');
		$this->assertSame($committee['id'], $this->store->tagged('meeting', 'ori:v5')['governanceBody']);
		$this->assertSame($committee['id'], $this->store->tagged('meeting', 'ori:v6')['governanceBody']);
		$writes = array_filter($this->store->writes, static fn (array $w): bool => $w[2] === 'governance-body' && $w[3] === $committee['id']);
		$this->assertCount(1, $writes);
	}

	public function testADocumentOfAnUnknownTypeIsNotImported(): void {
		$this->seed('raadsdocument', [['id' => 'd1', 'slug' => 'doc', 'title' => 'Iets', 'type' => 'krabbel']]);

		$report = $this->import->import();

		$this->assertContains(['schema' => 'raadsdocument', 'source' => 'd1', 'problem' => 'unknown type "krabbel"'], $report->problems());
		$this->assertSame([], $this->store->all('decidiq', 'digital-document'));
	}

	public function testAnAgendaItemKeepsItsResolvableReferencesAndReportsTheRest(): void {
		$this->seed('agendapunt', [
			['id' => 'a2', 'slug' => 'ap-2', 'subject' => 'Stukken', 'sortOrder' => 2, 'vergadering' => 'raad-1', 'attachments' => ['weg'], 'parentAgendaItem' => 'ap-nergens'],
		]);

		$report = $this->import->import();

		$this->assertContains(['schema' => 'agendapunt', 'source' => 'a2', 'problem' => 'document weg does not exist'], $report->problems());
		$this->assertContains(['schema' => 'agendapunt', 'source' => 'a2', 'problem' => 'parent agenda item ap-nergens does not exist'], $report->problems());
		$item = $this->store->tagged('agenda-item', 'ori:a2');
		$this->assertArrayNotHasKey('documents', $item);
		$this->assertArrayNotHasKey('parentItem', $item);
	}

	public function testAVoteThatCannotBeTranslatedIsReportedAndNotImported(): void {
		$this->seed('stemming', [
			['id' => 's1', 'slug' => 'st-1', 'subject' => 'Los', 'type' => 'proposal', 'result' => 'aangenomen', 'agendapunt' => 'ap-weg'],
			['id' => 's0', 'slug' => 'st-0', 'subject' => 'Zonder agendapunt', 'type' => 'proposal', 'result' => 'aangenomen'],
			['id' => 's2', 'slug' => 'st-2', 'subject' => 'Raar type', 'type' => 'peiling', 'result' => 'aangenomen', 'agendapunt' => 'ap-1'],
			['id' => 's3', 'slug' => 'st-3', 'subject' => 'Rare uitslag', 'type' => 'proposal', 'result' => 'gelijk', 'agendapunt' => 'ap-1'],
		]);

		$report = $this->import->import();

		$this->assertContains(['schema' => 'stemming', 'source' => 's1', 'problem' => 'agenda item "ap-weg" does not exist'], $report->problems());
		$this->assertContains(['schema' => 'stemming', 'source' => 's0', 'problem' => 'agenda item null does not exist'], $report->problems());
		$this->assertContains(['schema' => 'stemming', 'source' => 's2', 'problem' => 'unknown type "peiling"'], $report->problems());
		$this->assertContains(['schema' => 'stemming', 'source' => 's3', 'problem' => 'unknown result "gelijk"'], $report->problems());
		$this->assertSame([], $this->store->all('decidiq', 'decision'));
		$this->assertSame([], $this->store->all('decidiq', 'voting-round'));
	}

	public function testAPartyResultKeepsAnUnresolvedPartyAndReportsAnUnknownVote(): void {
		$this->seed('stemming', [[
			'id' => 's1', 'slug' => 'st-1', 'subject' => 'Motie', 'type' => 'proposal', 'result' => 'aangenomen', 'agendapunt' => 'ap-1',
			'politicalGroupResults' => [['fractie' => 'Onbekende Lijst', 'stem' => 'misschien'], ['stem' => 'voor', 'zetels' => '2']],
		]]);

		$report = $this->import->import();

		$this->assertContains(['schema' => 'stemming', 'source' => 's1', 'problem' => 'unknown party vote "misschien"'], $report->problems());
		$this->assertSame(
			[['party' => 'Onbekende Lijst'], ['value' => 'for', 'seatCount' => 2]],
			$this->store->tagged('voting-round', 'ori:s1')['partyResults']
		);
	}

	public function testSourceRowsReturnedAsEntitiesAreReadThroughTheirUuidAndObject(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn('uuid-f1');
		$entity->method('getObject')->willReturn(['slug' => 'groen', 'name' => 'Groen Links', 'zetels' => 5]);
		$inner = $this->store->service($this);
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('findAll')->willReturnCallback(
			static function (array $config=[]) use ($entity, $inner): array {
				if (($config['filters']['schema'] ?? '') === 'fractie') {
					return [$entity];
				}

				return $inner->findAll(config: $config);
			}
		);
		$service->method('saveObject')->willReturnCallback(
			static fn (mixed ...$args) => $inner->saveObject(...$args));

		$report = (new OriImportService($service, new OriExportMapper()))->import();

		$this->assertSame(1, $report->sourceCounts()['fractie']);
		$group = $this->store->tagged('governance-body', 'ori:uuid-f1');
		$this->assertSame(['Groen Links', 5], [$group['name'], $group['seatCount']]);
	}
}
