<?php

/**
 * The ORI wire adapter: round trip through storage, and the only home of the Dutch vocabulary.
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

#[CoversClass(OriExportMapper::class)]
#[UsesClass(OriImportService::class)]
#[UsesClass(OriImportReport::class)]
final class OriExportMapperTest extends TestCase {

	private const MEETING = ['id' => 'v1', 'slug' => 'v1', 'name' => 'Raad', 'type' => 'informatiebijeenkomst', 'status' => 'afgelast', 'startDate' => '2026-03-01T20:00:00+01:00', 'endDate' => '2026-03-01T22:00:00+01:00', 'location' => 'Raadzaal'];

	private const DOCUMENT = ['id' => 'd1', 'slug' => 'd1', 'title' => 'Brief', 'type' => 'brief', 'classification' => 'informatie', 'url' => 'https://example.org/b.pdf', 'fileName' => 'b.pdf', 'fileSize' => 512, 'contentType' => 'application/pdf'];

	private const ITEM = ['id' => 'a1', 'slug' => 'a1', 'subject' => 'Motie', 'omschrijving' => 'Over het fietspad', 'sortOrder' => 4, 'vergadering' => 'v1', 'attachments' => ['d1']];

	private const VOTE = ['id' => 's1', 'slug' => 's1', 'subject' => 'Motie', 'type' => 'amendment', 'result' => 'verworpen', 'agendapunt' => 'a1', 'votesFor' => 4, 'votesAgainst' => 6, 'onthoudingen' => 1, 'politicalGroupResults' => [['fractie' => 'Vrije Lijst', 'stem' => 'onthouding', 'zetels' => 1]]];

	/**
	 * Import the records above and return the stored objects.
	 *
	 * @return InMemoryObjects
	 */
	private static function imported(TestCase $test): InMemoryObjects {
		$store = new InMemoryObjects();
		$store->seed('ori', 'vergadering', self::MEETING);
		$store->seed('ori', 'raadsdocument', self::DOCUMENT);
		$store->seed('ori', 'agendapunt', self::ITEM);
		$store->seed('ori', 'stemming', self::VOTE);
		(new OriImportService($store->service($test), new OriExportMapper()))->import();

		return $store;
	}

	public function testAMeetingRoundTripsToTheSameWireFields(): void {
		$stored = self::imported($this)->tagged('meeting', 'ori:v1');
		$this->assertSame(['informational-session', 'cancelled'], [$stored['meetingType'], $stored['lifecycle']]);

		$wire = (new OriExportMapper())->meeting($stored);

		$this->assertSame(array_diff_key(self::MEETING, ['id' => 1, 'slug' => 1]), array_diff_key($wire, ['id' => 1]));
		$this->assertSame($stored['id'], $wire['id']);
	}

	public function testADocumentRoundTripsToTheSameWireFields(): void {
		$stored = self::imported($this)->tagged('digital-document', 'ori:d1');

		$this->assertSame(array_diff_key(self::DOCUMENT, ['id' => 1, 'slug' => 1]), array_diff_key((new OriExportMapper())->document($stored), ['id' => 1]));
	}

	public function testAnAgendaItemRoundTripsWithItsReferencesAsUuids(): void {
		$store = self::imported($this);
		$stored = $store->tagged('agenda-item', 'ori:a1');

		$wire = (new OriExportMapper())->agendaItem($stored);

		$this->assertSame(['Motie', 'Over het fietspad', 4], [$wire['subject'], $wire['omschrijving'], $wire['sortOrder']]);
		$this->assertSame($store->tagged('meeting', 'ori:v1')['id'], $wire['vergadering']);
		$this->assertSame([$store->tagged('digital-document', 'ori:d1')['id']], $wire['attachments']);
	}

	public function testAVoteRoundTripsFromTheRoundAndItsDecision(): void {
		$store = self::imported($this);
		$round = $store->tagged('voting-round', 'ori:s1');
		$decision = $store->tagged('decision', 'ori:s1');

		$wire = (new OriExportMapper())->vote($round, $decision);

		$this->assertSame(
			['Motie', 'amendment', 'verworpen', 4, 6, 1, $store->tagged('agenda-item', 'ori:a1')['id']],
			[$wire['subject'], $wire['type'], $wire['result'], $wire['votesFor'], $wire['votesAgainst'], $wire['onthoudingen'], $wire['agendapunt']]
		);
		// A party the source names but does not hold as a group keeps its name.
		$this->assertSame([['fractie' => 'Vrije Lijst', 'stem' => 'onthouding', 'zetels' => 1]], $wire['politicalGroupResults']);
	}

	public function testAValueTheMappingDoesNotKnowReadsAsNull(): void {
		$mapper = new OriExportMapper();

		$this->assertNull($mapper->readMeeting(['type' => 'borrel', 'status' => 7])['meetingType']);
		$this->assertNull($mapper->readMeeting(['status' => 7])['lifecycle']);
		$this->assertNull($mapper->readPerson(['role' => 'wethouder'])['role']);
		$this->assertArrayNotHasKey('type', $mapper->meeting(['meetingType' => 'extraordinary']));
	}

	public function testNoOtherFileOfTheChangeCarriesTheOriVocabulary(): void {
		$dutch = ['fractie', 'raadslid', 'vergadering', 'raadsdocument', 'agendapunt', 'stemming', 'omschrijving', 'onthoudingen', 'zetels', 'actief', 'stem'];
		foreach ([OriExportMapper::MEETING_TYPES, OriExportMapper::MEETING_STATES, OriExportMapper::DOCUMENT_TYPES, OriExportMapper::RESULTS, OriExportMapper::COALITION_ROLES, OriExportMapper::PARTY_VOTES] as $map) {
			foreach ($map as $source => $target) {
				if ($source !== $target) {
					$dutch[] = $source;
				}
			}
		}

		$root = __DIR__ . '/../../../../';
		$files = array_merge(
			glob($root . 'lib/Service/Ori/*.php'),
			glob($root . 'lib/Controller/OriFeed*.php'),
			[$root . 'lib/Command/ImportOriCommand.php', $root . 'lib/Settings/register.d/999-ori-adoption.json']
		);
		foreach ($files as $file) {
			if (basename($file) === 'OriExportMapper.php') {
				continue;
			}

			$text = (string)file_get_contents($file);
			foreach ($dutch as $word) {
				$this->assertDoesNotMatchRegularExpression('/[\'"]' . preg_quote($word, '/') . '[\'"]/', $text, basename($file) . ' names ' . $word);
			}
		}
	}
}
