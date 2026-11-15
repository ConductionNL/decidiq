<?php

/**
 * Golden test for the CSV bodies RegulatorExportRenderer writes.
 *
 * Renders every scope (resolutions, minutes, audit-log and an unknown scope
 * that falls back to the audit-log columns) over benign rows, sparse rows,
 * non-string values and hostile cells: commas, quotes, line feeds, lone
 * carriage returns, semicolons and formula-leading text, with and without
 * leading whitespace. The expected bodies live in
 * tests/fixtures/regulator-export-renderer-golden.json; run with
 * RECORD_REGULATOR_EXPORT_RENDERER_GOLDEN=1 to write them, then run again
 * without it.
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
 * @spec openspec/changes/archive/2026-06-12-board-meeting-resolutions/tasks.md#task-6.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\RegulatorExportRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the regulator export CSV.
 *
 * @covers \OCA\Decidiq\Service\RegulatorExportRenderer
 * @uses   \OCA\Decidiq\Service\CsvCell
 */
class RegulatorExportRendererGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/regulator-export-renderer-golden.json';

	/**
	 * Every CSV body matches the recording.
	 *
	 * @return void
	 */
	public function testEveryCsvBodyMatchesTheRecording(): void {
		$renderer = new RegulatorExportRenderer(
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);
		$actual   = [];
		foreach ($this->cases() as $name => [$scope, $rows]) {
			$actual[$name] = $renderer->render(format: 'csv', title: 'T', generatedAt: '2026-01-01T00:00:00Z', scope: $scope, rows: $rows);
		}

		if (getenv('RECORD_REGULATOR_EXPORT_RENDERER_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		self::assertSame(array_keys($golden), array_keys($actual));
		foreach ($actual as $name => $body) {
			self::assertSame($golden[$name], $body, $name);
		}
	}//end testEveryCsvBodyMatchesTheRecording()

	/**
	 * The cases: a scope and its rows.
	 *
	 * @return array<string, array{0: string, 1: list<array<string, mixed>>}>
	 */
	private function cases(): array {
		return [
			'resolutions-benign'  => ['resolutions', [
				['id' => 'r-1', 'meetingIntegration' => 'm-1', 'resolutionNumber' => 'R-2026-001', 'title' => 'Approve budget', 'type' => 'approval', 'status' => 'adopted', 'voteThreshold' => 0.5, 'adoptionDate' => '2026-04-01'],
				['id' => 'r-2', 'title' => 'Approve, budget "2027"', 'voteThreshold' => 2, 'extra' => 'ignored'],
				[],
			]],
			'resolutions-hostile' => ['resolutions', [
				['id' => 'r-3', 'title' => '=HYPERLINK("https://evil.example","open")', 'type' => '+cmd', 'status' => '-', 'resolutionNumber' => '@SUM(A1)'],
				['id' => 'r-4', 'title' => "first\rsecond", 'type' => "line\none", 'status' => "\tadopted"],
				['id' => 'r-5', 'title' => 'x;=1+1;', 'type' => ' =2+2', 'status' => "\n=3+3", 'adoptionDate' => '  -1'],
			]],
			'minutes'             => ['minutes', [
				['id' => 'n-1', 'meetingIntegration' => 'm-1', 'language' => 'nl', 'version' => 3, 'preparedBy' => 'Anna de Vries', 'reviewedBy' => 'Bob', 'signingCompletionDate' => '2026-04-02', 'hashSha256' => 'ab12'],
				['id' => 'n-2', 'preparedBy' => '=1+1', 'reviewedBy' => 'Dora; chair', 'hashSha256' => " ab"],
			]],
			'audit-log'           => ['audit-log', [
				['id' => 'a-1', 'timestamp' => '2026-04-01T10:00:00Z', 'actorUuid' => 'alice', 'action' => 'vote', 'previousHash' => 'GENESIS', 'currentHash' => 'h1'],
				['id' => 'a-2', 'actorUuid' => 'eve;=1+1', 'action' => '-vote', 'previousHash' => 'h1', 'currentHash' => "h2\r"],
			]],
			'unknown-scope'       => ['other', [
				['id' => 'a-3', 'actorUuid' => 'carl', 'title' => 'not a column'],
			]],
			'no-rows'             => ['minutes', []],
		];
	}//end cases()
}//end class
