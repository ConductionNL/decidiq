<?php

/**
 * Golden test for how a minutes draft is cut into sections.
 *
 * Runs MinutesDraftService::buildSections over transcripts with and without
 * an agenda-item timeline, agenda items keyed by id, by uuid or by neither,
 * records linked to an item directly, through relations, through a relation
 * list or not at all, and summaries that name some recorded outcomes and not
 * others. It records every prompt handed to the runner and the sections
 * returned. The expected values live in
 * tests/fixtures/minutes-draft-sections-golden.json; run with
 * RECORD_MINUTES_SECTIONS_GOLDEN=1 to write them, then run again without it.
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
 * @spec openspec/specs/meeting-transcription/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MinutesDraftComposer;
use OCA\Decidiq\Service\MinutesDraftService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for cutting a minutes draft into sections.
 *
 * @covers \OCA\Decidiq\Service\MinutesDraftService
 * @covers \OCA\Decidiq\Service\MinutesDraftComposer
 * @uses   \OCA\Decidiq\Service\MinutesDraftRun
 * @uses   \OCA\Decidiq\Service\MinutesOutcomes
 * @uses   \OCA\Decidiq\Service\DraftProvenance
 */
class MinutesDraftSectionsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/minutes-draft-sections-golden.json';

	/**
	 * Every section build answers as recorded.
	 *
	 * @return void
	 */
	public function testEverySectionBuildMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$prompts = [];
			$runner  = static function (string $prompt) use (&$prompts, $case): string {
				$prompts[] = $prompt;
				return ($case['summaries'][count($prompts) - 1] ?? 'Samenvatting ' . count($prompts));
			};

			$sections = $this->service()->buildSections(
				segments: $case['segments'],
				agendaItems: $case['agendaItems'],
				votingRounds: $case['votingRounds'],
				decisions: $case['decisions'],
				providerId: 'provider-' . $name,
				generatedAt: '2026-03-0' . (count($actual) % 9 + 1) . 'T10:00:00+00:00',
				runner: $runner
			);
			$actual[$name] = ['sections' => $sections, 'prompts' => $prompts];
		}

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_MINUTES_SECTIONS_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $value) {
			self::assertSame(json_encode($golden[$name]), json_encode($value), $name);
		}
	}//end testEverySectionBuildMatchesTheRecording()

	/**
	 * The service, with the real composer behind its container.
	 *
	 * @return MinutesDraftService
	 */
	private function service(): MinutesDraftService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				MinutesDraftComposer::class => new MinutesDraftComposer(),
			}
		);

		return new MinutesDraftService(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$segments = [
			['speakerLabel' => 'Voorzitter', 'text' => 'Welkom.', 'agendaItem' => 'ai-1'],
			['speakerLabel' => '', 'text' => 'Over de begroting.', 'agendaItem' => 'ai-2'],
			['text' => 'Zonder label.', 'agendaItem' => 'ai-2'],
			'not a segment',
			['speakerLabel' => 'Lid', 'text' => 'Los punt.'],
			['speakerLabel' => 'Lid', 'text' => 'Bij de uuid.', 'agendaItem' => 'u-3'],
		];
		$decisions = [
			['id' => 'd-1', 'title' => 'Begroting 2027', 'agendaItem' => 'ai-2'],
			['uuid' => 'd-2', 'name' => 'Benoeming', 'relations' => ['agendaItem' => 'ai-1']],
			['title' => '', 'agendaItem' => 'ai-1'],
			'not a decision',
			['id' => 'd-4', 'title' => 'Losse motie'],
			['id' => 'd-5', 'title' => 'Via lijst', 'relations' => ['agenda-item' => ['u-3']]],
		];
		$votes = [
			['id' => 'v-1', 'title' => 'Stemming begroting', 'result' => 'aangenomen', 'agendaItem' => ['id' => 'ai-2']],
			['uuid' => 'v-2', 'title' => 'Stemming benoeming', 'outcome' => 'verworpen', 'relations' => ['agendaItem' => 'ai-1']],
			['title' => 'Zonder uitkomst', 'agendaItem' => 'ai-9'],
			7,
		];
		$items = [
			['id' => 'ai-1', 'title' => 'Opening'],
			['uuid' => 'u-3', 'name' => 'Rondvraag'],
			['id' => 'ai-2', 'title' => 'Begroting'],
			[],
		];

		return [
			'per-item' => [
				'segments' => $segments, 'agendaItems' => $items, 'votingRounds' => $votes, 'decisions' => $decisions,
				'summaries' => ['De voorzitter opent. BENOEMING besproken.', 'Rondvraag: via lijst genoemd.', 'Begroting 2027 en stemming begroting aangenomen.'],
			],
			'no-agenda-items' => [
				'segments' => $segments, 'agendaItems' => [], 'votingRounds' => $votes, 'decisions' => $decisions,
				'summaries' => ['Alles: begroting 2027, losse motie, zonder uitkomst.'],
			],
			'no-timeline' => [
				'segments' => [['speakerLabel' => 'A', 'text' => 'Een.'], ['text' => 'Twee.', 'agendaItem' => ''], 'x'],
				'agendaItems' => $items, 'votingRounds' => [], 'decisions' => [['id' => 'd-9', 'title' => 'Niets']],
				'summaries' => ['Niets bijzonders.'],
			],
			'no-records' => [
				'segments' => $segments, 'agendaItems' => [['id' => 'ai-2']], 'votingRounds' => [], 'decisions' => [],
				'summaries' => [],
			],
			'empty' => [
				'segments' => [], 'agendaItems' => [], 'votingRounds' => [], 'decisions' => [],
				'summaries' => [''],
			],
		];
	}//end cases()
}//end class
