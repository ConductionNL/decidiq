<?php

/**
 * The ORI import: mapping table, idempotency, dry run, dangling references, rollback.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Ori;

use OCA\Decidiq\Service\Ori\OriExportMapper;
use OCA\Decidiq\Service\Ori\OriImportReport;
use OCA\Decidiq\Service\Ori\OriImportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriImportService::class)]
#[UsesClass(OriExportMapper::class)]
#[UsesClass(OriImportReport::class)]
final class OriImportServiceTest extends TestCase {

	private InMemoryObjects $store;

	private OriImportService $import;

	protected function setUp(): void {
		$this->store = new InMemoryObjects();
		$this->import = new OriImportService($this->store->service($this), new OriExportMapper());
		$this->seedSource();
	}

	/**
	 * A small Voorbeeldstad council in the source register.
	 *
	 * @return void
	 */
	private function seedSource(): void {
		$source = [
			'fractie' => [
				['id' => 'f1', 'slug' => 'groen', 'name' => 'Groen Links', 'zetels' => 7, 'classification' => 'coalitiepartij'],
				['id' => 'f2', 'slug' => 'rood', 'name' => 'Rode Partij', 'zetels' => '3', 'classification' => 'oppositiepartij'],
			],
			'raadslid' => [
				['id' => 'r1', 'slug' => 'jansen', 'name' => 'A. Jansen', 'role' => 'councilMember', 'fractie' => 'groen', 'actief' => true],
				['id' => 'r2', 'slug' => 'pietersen', 'name' => 'B. Pietersen', 'role' => 'alderman', 'fractie' => 'rood', 'actief' => false],
				['id' => 'r3', 'slug' => 'burg', 'name' => 'C. de Burg', 'role' => 'mayor'],
				['id' => 'r4', 'slug' => 'griffier', 'name' => 'D. Griffier', 'role' => 'clerk'],
			],
			'vergadering' => [
				['id' => 'v1', 'slug' => 'raad-1', 'name' => 'Raadsvergadering januari', 'type' => 'raadsvergadering', 'status' => 'confirmed', 'startDate' => '2026-01-10T19:30:00+01:00', 'location' => 'Raadzaal', 'organisation' => 'Gemeenteraad Voorbeeldstad'],
				['id' => 'v2', 'slug' => 'cie-1', 'name' => 'Commissie Ruimte', 'type' => 'commissievergadering', 'status' => 'planned', 'startDate' => '2026-01-05T19:30:00+01:00', 'organisation' => 'Gemeenteraad Voorbeeldstad', 'committee' => 'Commissie Ruimte'],
				['id' => 'v3', 'slug' => 'info-1', 'name' => 'Informatieavond', 'type' => 'informatiebijeenkomst', 'status' => 'afgelast', 'startDate' => '2026-01-07T19:30:00+01:00'],
			],
			'raadsdocument' => [
				['id' => 'd1', 'slug' => 'brief-1', 'title' => 'Raadsinformatiebrief', 'type' => 'brief', 'classification' => 'informatie', 'url' => 'https://example.org/brief.pdf', 'fileName' => 'brief.pdf', 'fileSize' => 2048, 'contentType' => 'application/pdf'],
			],
			'agendapunt' => [
				['id' => 'a3', 'slug' => 'ap-3', 'subject' => 'Motie fietspad', 'sortOrder' => 3, 'vergadering' => 'raad-1', 'parentAgendaItem' => 'ap-2'],
				['id' => 'a1', 'slug' => 'ap-1', 'subject' => 'Opening', 'sortOrder' => 1, 'vergadering' => 'raad-1'],
				['id' => 'a2', 'slug' => 'ap-2', 'subject' => 'Ingekomen stukken', 'sortOrder' => 2, 'vergadering' => 'raad-1', 'attachments' => ['brief-1']],
			],
			'stemming' => [
				[
					'id' => 's1', 'slug' => 'st-1', 'subject' => 'Motie fietspad', 'type' => 'proposal', 'result' => 'aangenomen', 'agendapunt' => 'ap-3',
					'votesFor' => 7, 'votesAgainst' => 3, 'onthoudingen' => 0,
					'politicalGroupResults' => [['fractie' => 'groen', 'stem' => 'voor', 'zetels' => 7], ['fractie' => 'rood', 'stem' => 'tegen', 'zetels' => 3]],
				],
			],
		];
		foreach ($source as $schema => $records) {
			foreach ($records as $record) {
				$this->store->seed('ori', $schema, $record);
			}
		}
	}

	public function testPoliticalGroupsBecomeBodiesUnderTheCouncil(): void {
		$this->import->import(cutoverDate: '2026-11-14');

		$council = $this->store->tagged('governance-body', 'ori:council:Gemeenteraad Voorbeeldstad');
		$this->assertSame('legislative', $council['bodyType']);
		$this->assertSame('municipality', $council['domain']);

		$groen = $this->store->tagged('governance-body', 'ori:f1');
		$this->assertSame(
			['name' => 'Groen Links', 'bodyType' => 'political-group', 'domain' => 'municipality', 'parentBody' => $council['id'], 'seatCount' => 7, 'coalitionRole' => 'coalition'],
			array_diff_key($groen, ['id' => 1, 'externalReference' => 1])
		);
		$this->assertSame('opposition', $this->store->tagged('governance-body', 'ori:f2')['coalitionRole']);
		$this->assertSame(3, $this->store->tagged('governance-body', 'ori:f2')['seatCount']);
	}

	public function testMembersBecomePersonsWithCouncilAndGroupMemberships(): void {
		$this->import->import(cutoverDate: '2026-11-14');
		$council = $this->store->tagged('governance-body', 'ori:council:Gemeenteraad Voorbeeldstad')['id'];
		$executive = $this->store->tagged('governance-body', 'ori:executive:Gemeenteraad Voorbeeldstad executive board');
		$this->assertSame('executive-board', $executive['bodyType']);

		$jansen = $this->store->tagged('person', 'ori:r1');
		$this->assertSame('A. Jansen', $jansen['name']);
		$seat = $this->store->tagged('membership', 'ori:r1');
		$this->assertSame(['member', $jansen['id'], $council, 'Groen Links'], [$seat['role'], $seat['person'], $seat['governanceBody'], $seat['party']]);
		$this->assertArrayNotHasKey('endDate', $seat);
		$group = $this->store->tagged('membership', 'ori:r1#group');
		$this->assertSame(['member', $this->store->tagged('governance-body', 'ori:f1')['id']], [$group['role'], $group['governanceBody']]);

		// An alderman is a member of the executive board; an inactive member's memberships end at the cutover.
		$alderman = $this->store->tagged('membership', 'ori:r2');
		$this->assertSame(['member', $executive['id'], '2026-11-14'], [$alderman['role'], $alderman['governanceBody'], $alderman['endDate']]);
		$this->assertSame('2026-11-14', $this->store->tagged('membership', 'ori:r2#group')['endDate']);

		$this->assertSame('chair', $this->store->tagged('membership', 'ori:r3')['role']);
		$this->assertSame('secretary', $this->store->tagged('membership', 'ori:r4')['role']);
		$this->assertCount(6, $this->store->all('decidiq', 'membership'));
	}

	public function testMeetingsMapTypeStatusAndBody(): void {
		$this->import->import();
		$council = $this->store->tagged('governance-body', 'ori:council:Gemeenteraad Voorbeeldstad')['id'];

		$raad = $this->store->tagged('meeting', 'ori:v1');
		$this->assertSame(
			['title' => 'Raadsvergadering januari', 'meetingType' => 'regular', 'lifecycle' => 'scheduled', 'scheduledDate' => '2026-01-10T19:30:00+01:00', 'governanceBody' => $council, 'meetingMode' => 'in-person', 'isPublic' => true, 'location' => 'Raadzaal'],
			array_diff_key($raad, ['id' => 1, 'externalReference' => 1])
		);

		$committee = $this->store->tagged('governance-body', 'ori:committee:Commissie Ruimte');
		$this->assertSame(['advisory-body', $council], [$committee['bodyType'], $committee['parentBody']]);
		$cie = $this->store->tagged('meeting', 'ori:v2');
		$this->assertSame(['committee', 'draft', $committee['id']], [$cie['meetingType'], $cie['lifecycle'], $cie['governanceBody']]);

		$info = $this->store->tagged('meeting', 'ori:v3');
		$this->assertSame(['informational-session', 'cancelled'], [$info['meetingType'], $info['lifecycle']]);
	}

	public function testDocumentsAgendaItemsAndVotes(): void {
		$this->import->import();

		$document = $this->store->tagged('digital-document', 'ori:d1');
		$this->assertSame(['letter', 'informatie', 'https://example.org/brief.pdf', 'brief.pdf', '2048', 'application/pdf'], [$document['documentType'], $document['classification'], $document['url'], $document['fileName'], $document['contentSize'], $document['encodingFormat']]);

		$meeting = $this->store->tagged('meeting', 'ori:v1')['id'];
		$opening = $this->store->tagged('agenda-item', 'ori:a1');
		$stukken = $this->store->tagged('agenda-item', 'ori:a2');
		$motie = $this->store->tagged('agenda-item', 'ori:a3');
		$this->assertSame(['informational', 1, $meeting], [$opening['itemType'], $opening['orderNumber'], $opening['meeting']]);
		$this->assertSame(['discussion', [$document['id']]], [$stukken['itemType'], $stukken['documents']]);
		$this->assertSame(['decision', $stukken['id']], [$motie['itemType'], $motie['parentItem']]);

		$decision = $this->store->tagged('decision', 'ori:s1');
		$this->assertSame(
			['Motie fietspad', 'resolution', 'adopted', 'decided', 'agenda-item', $motie['id'], 'Motie fietspad'],
			[$decision['title'], $decision['decisionType'], $decision['outcome'], $decision['lifecycle'], $decision['subjectSchema'], $decision['subjectId'], $decision['subjectLabel']]
		);
		$round = $this->store->tagged('voting-round', 'ori:s1');
		$this->assertSame(['for-against-abstain', false, 'adopted', 7, 3, 0, $decision['id']], [$round['votingMethod'], $round['isSecret'], $round['result'], $round['votesFor'], $round['votesAgainst'], $round['votesAbstain'], $round['subjectDecision']]);
		$this->assertSame(
			[
				['party' => $this->store->tagged('governance-body', 'ori:f1')['id'], 'value' => 'for', 'seatCount' => 7],
				['party' => $this->store->tagged('governance-body', 'ori:f2')['id'], 'value' => 'against', 'seatCount' => 3],
			],
			$round['partyResults']
		);
		// Party aggregates are kept, not split into invented per-member votes.
		$this->assertSame([], $this->store->all('decidiq', 'vote'));
	}

	public function testASecondRunUpdatesInsteadOfDuplicating(): void {
		$first = $this->import->import();
		$count = $this->store->count('decidiq');
		$second = $this->import->import();

		$this->assertSame($count, $this->store->count('decidiq'));
		$created = array_sum(array_column($first->targetCounts(), 'created'));
		$this->assertSame($created, $count);
		$this->assertSame(0, array_sum(array_column($second->targetCounts(), 'created')));
		$this->assertSame($created, array_sum(array_column($second->targetCounts(), 'updated')));
		$this->assertSame([], $second->problems());
	}

	public function testADryRunWritesNothingAndReportsCounts(): void {
		$report = $this->import->preview();

		$this->assertSame([], $this->store->writes);
		$this->assertSame(0, $this->store->count('decidiq'));
		$this->assertSame(['fractie' => 2, 'raadslid' => 4, 'vergadering' => 3, 'raadsdocument' => 1, 'agendapunt' => 3, 'stemming' => 1], $report->sourceCounts());
		$this->assertSame(3, $report->targetCounts()['meeting']['created']);
		$this->assertContains(['action' => 'created', 'schema' => 'meeting', 'reference' => 'ori:v1', 'label' => 'Raadsvergadering januari'], $report->preview());
		$this->assertTrue($report->dryRun);
	}

	public function testADanglingReferenceIsReportedNotImported(): void {
		$this->store->seed('ori', 'agendapunt', ['id' => 'a9', 'slug' => 'ap-9', 'subject' => 'Verdwaald', 'vergadering' => 'nowhere', 'attachments' => []]);
		$this->store->seed('ori', 'raadslid', ['id' => 'r9', 'slug' => 'x', 'name' => 'E. Zonder', 'role' => 'councilMember', 'fractie' => 'onbekend']);

		$report = $this->import->import();

		$this->assertSame([], array_filter($this->store->all('decidiq', 'agenda-item'), static fn (array $o): bool => $o['externalReference'] === 'ori:a9'));
		$this->assertContains(['schema' => 'agendapunt', 'source' => 'a9', 'problem' => 'meeting "nowhere" does not exist'], $report->problems());
		$this->assertContains(['schema' => 'raadslid', 'source' => 'r9', 'problem' => 'political group onbekend does not exist'], $report->problems());
	}

	public function testAnUnknownValueIsReportedNotStoredUntranslated(): void {
		$this->store->seed('ori', 'vergadering', ['id' => 'v9', 'slug' => 'v9', 'name' => 'Raar', 'type' => 'borrel', 'status' => 'confirmed', 'startDate' => '2026-02-01']);

		$report = $this->import->import();

		$this->assertContains(['schema' => 'vergadering', 'source' => 'v9', 'problem' => 'unknown type "borrel"'], $report->problems());
		$this->assertCount(3, $this->store->all('decidiq', 'meeting'));
	}

	public function testRollbackDeletesOnlyImportedObjectsAndNeverTheSource(): void {
		$this->store->seed('decidiq', 'meeting', ['id' => 'native', 'title' => 'Own meeting']);
		$this->store->seed('decidiq', 'decision', ['id' => 'native-d', 'title' => 'Own decision', 'externalReference' => 'zaak-12']);
		$source = $this->store->count('ori');
		$this->import->import();

		$preview = $this->import->previewRollback();
		$this->assertGreaterThan(2, $this->store->count('decidiq'));
		$this->assertNotSame([], $preview->preview());

		$this->import->rollback();

		$this->assertSame(['native'], array_column($this->store->all('decidiq', 'meeting'), 'id'));
		$this->assertSame(['native-d'], array_column($this->store->all('decidiq', 'decision'), 'id'));
		$this->assertSame(2, $this->store->count('decidiq'));
		$this->assertSame($source, $this->store->count('ori'));
		$this->assertSame([], array_filter($this->store->writes, static fn (array $w): bool => $w[1] === 'ori'));
	}
}
