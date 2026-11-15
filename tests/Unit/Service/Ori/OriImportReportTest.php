<?php

/**
 * The ORI import report: source counts, target counts per action, preview rows and problems.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Ori;

use OCA\Decidiq\Service\Ori\OriImportReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OriImportReport::class)]
final class OriImportReportTest extends TestCase {

	public function testANewReportIsEmptyAndNotADryRunByDefault(): void {
		$report = new OriImportReport();

		$this->assertFalse($report->dryRun);
		$this->assertSame([], $report->sourceCounts());
		$this->assertSame([], $report->targetCounts());
		$this->assertSame([], $report->preview());
		$this->assertSame([], $report->problems());
		$this->assertTrue((new OriImportReport(dryRun: true))->dryRun);
	}

	public function testSourceCountsAddUpPerSchema(): void {
		$report = new OriImportReport();
		$report->read(schema: 'vergadering', count: 3);
		$report->read(schema: 'stemming', count: 1);
		$report->read(schema: 'vergadering', count: 2);

		$this->assertSame(['vergadering' => 5, 'stemming' => 1], $report->sourceCounts());
	}

	public function testWritesAreCountedPerActionAndListedInOrder(): void {
		$report = new OriImportReport();
		$report->wrote(action: 'created', schema: 'meeting', reference: 'ori:v1', label: 'Raad');
		$report->wrote(action: 'created', schema: 'meeting', reference: 'ori:v2');
		$report->wrote(action: 'updated', schema: 'meeting', reference: 'ori:v3', label: 'Commissie');
		$report->wrote(action: 'deleted', schema: 'decision', reference: 'ori:s1', label: 'Motie');

		$this->assertSame(
			[
				'meeting' => ['created' => 2, 'updated' => 1, 'deleted' => 0],
				'decision' => ['created' => 0, 'updated' => 0, 'deleted' => 1],
			],
			$report->targetCounts()
		);
		$this->assertSame(
			[
				['action' => 'created', 'schema' => 'meeting', 'reference' => 'ori:v1', 'label' => 'Raad'],
				['action' => 'created', 'schema' => 'meeting', 'reference' => 'ori:v2', 'label' => ''],
				['action' => 'updated', 'schema' => 'meeting', 'reference' => 'ori:v3', 'label' => 'Commissie'],
				['action' => 'deleted', 'schema' => 'decision', 'reference' => 'ori:s1', 'label' => 'Motie'],
			],
			$report->preview()
		);
	}

	public function testProblemsAreKeptInOrderAndDoNotCountAsWrites(): void {
		$report = new OriImportReport();
		$report->problem(schema: 'raadslid', source: 'r1', problem: 'has no name');
		$report->problem(schema: 'stemming', source: 's1', problem: 'unknown type "x"');

		$this->assertSame(
			[
				['schema' => 'raadslid', 'source' => 'r1', 'problem' => 'has no name'],
				['schema' => 'stemming', 'source' => 's1', 'problem' => 'unknown type "x"'],
			],
			$report->problems()
		);
		$this->assertSame([], $report->targetCounts());
		$this->assertSame([], $report->preview());
	}
}
