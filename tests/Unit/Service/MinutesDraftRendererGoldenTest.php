<?php

/**
 * Golden test for MinutesDraftRenderer.
 *
 * Renders draft minutes over meetings, agenda items, motions, voting rounds,
 * decisions and attendance in every shape the renderer reads: fields present,
 * absent or under their fallback names, empty lists, unknown attendance
 * statuses and dates that do or do not parse. The expected text of every
 * case lives in tests/fixtures/minutes-draft-renderer-golden.json; run with
 * RECORD_MINUTES_DRAFT_RENDERER_GOLDEN=1 to write it, then run again without it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/minutes-generation/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\MinutesDraftRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the draft minutes text.
 *
 * @covers \OCA\Decidiq\Service\MinutesDraftRenderer
 */
class MinutesDraftRendererGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/minutes-draft-renderer-golden.json';

	/**
	 * Every case renders as recorded.
	 *
	 * @return void
	 */
	public function testEveryDraftMatchesTheRecording(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->render(case: $case);
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		if (getenv('RECORD_MINUTES_DRAFT_RENDERER_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryDraftMatchesTheRecording()

	/**
	 * Render one case.
	 *
	 * @param array<string, mixed> $case The case
	 *
	 * @return string
	 */
	private function render(array $case): string {
		return (new MinutesDraftRenderer())->render(
			minutes: $case['minutes'],
			meeting: $case['meeting'],
			agendaItems: $case['agendaItems'],
			motions: $case['motions'],
			votingRounds: $case['votingRounds'],
			decisions: $case['decisions'],
			attendance: $case['attendance']
		);
	}//end render()

	/**
	 * The cases: every combination of a few shapes per part.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$parts = [
			'minutes' => [[], ['title' => 'Notulen raad']],
			'meeting' => [
				[],
				['title' => 'Raad', 'scheduledDate' => '2026-10-08T19:30:00+02:00', 'location' => 'Raadzaal'],
				['name' => 'Commissie', 'date' => 'not a date', 'location' => ''],
				['title' => null, 'name' => 'Naam', 'scheduledDate' => null, 'date' => '2026-01-02'],
			],
			'agendaItems' => [
				[],
				[
					['orderNumber' => 3, 'title' => 'Begroting', 'description' => 'Jaarlijks'],
					['name' => 'Rondvraag', 'description' => ''],
					[],
					['orderNumber' => null, 'title' => null, 'name' => null],
				],
			],
			'motions' => [
				[],
				[['title' => 'Motie A', 'text' => 'Verzoekt'], ['name' => 'Motie B', 'description' => 'Overwegende'], ['text' => '']],
			],
			'votingRounds' => [
				[],
				[['title' => 'Ronde 1', 'result' => '12-3'], ['name' => 'Ronde 2', 'outcome' => 'verworpen'], []],
			],
			'decisions' => [
				[],
				[
					['title' => 'Besluit 1', 'outcome' => 'adopted', 'text' => 'Vastgesteld'],
					['outcome' => 'rejected', 'description' => 'Niet'],
					['title' => 'Besluit 3', 'outcome' => ''],
					['outcome' => 5],
				],
			],
			'attendance' => [
				[],
				[['status' => 'unknown', 'name' => 'X']],
				[
					['status' => 'absent', 'name' => 'Cees'],
					['status' => 'present', 'name' => 'Anna'],
					['status' => 'proxy', 'name' => 'Dirk'],
					['status' => 'present'],
					['name' => 'Geen status'],
					['status' => 'excused', 'name' => 'Bram'],
				],
			],
		];

		$cases = ['all-empty' => array_map(static fn (array $options): array => $options[0], $parts)];
		foreach ($parts as $part => $options) {
			foreach ($options as $index => $option) {
				$cases[$part . '-' . $index] = array_merge($cases['all-empty'], [$part => $option]);
			}
		}

		$cases['all-full'] = array_map(static fn (array $options): array => $options[count($options) - 1], $parts);
		$cases['all-full-meeting-1'] = array_merge($cases['all-full'], ['meeting' => $parts['meeting'][1], 'minutes' => $parts['minutes'][1]]);
		return $cases;
	}//end cases()
}//end class
