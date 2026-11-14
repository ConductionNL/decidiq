<?php

/**
 * occ decidiq:import-ori: dry run, strict, rollback.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Command;

use OCA\Decidiq\Command\ImportOriCommand;
use OCA\Decidiq\Service\Ori\OriImportReport;
use OCA\Decidiq\Service\Ori\OriImportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ImportOriCommand::class)]
#[UsesClass(OriImportReport::class)]
final class ImportOriCommandTest extends TestCase {

	private OriImportService&MockObject $import;

	/**
	 * Every import call: [sourceRegister, dryRun].
	 *
	 * @var array<int, array{string, bool}>
	 */
	private array $calls = [];

	protected function setUp(): void {
		$this->import = $this->createMock(OriImportService::class);
	}

	/**
	 * Let import() answer with a report, with or without a problem.
	 *
	 * @param bool $problem Whether the report carries a problem.
	 *
	 * @return void
	 */
	private function answers(bool $problem): void {
		foreach (['import' => false, 'preview' => true] as $method => $dryRun) {
			$this->import->method($method)->willReturnCallback(
				function (string $sourceRegister='ori') use ($problem, $dryRun): OriImportReport {
					$this->calls[] = [$sourceRegister, $dryRun];
					$report = new OriImportReport(dryRun: $dryRun);
					$report->read(schema: 'vergadering', count: 2);
					$report->wrote(action: 'created', schema: 'meeting', reference: 'ori:v1', label: 'Raad');
					if ($problem === true) {
						$report->problem(schema: 'agendapunt', source: 'a9', problem: 'meeting "x" does not exist');
					}

					return $report;
				}
			);
		}
	}

	private function runCommand(array $input): CommandTester {
		$tester = new CommandTester(new ImportOriCommand($this->import));
		$tester->execute($input);
		return $tester;
	}

	public function testADryRunPrintsCountsAndThePreview(): void {
		$this->answers(problem: false);

		$tester = $this->runCommand(['--dry-run' => true, '--source-register' => 'council']);

		$this->assertSame(Command::SUCCESS, $tester->getStatusCode());
		$this->assertSame([['council', true]], $this->calls);
		$this->assertStringContainsString('[dry run] source vergadering: 2', $tester->getDisplay());
		$this->assertStringContainsString('[dry run] target meeting: 1 created, 0 updated, 0 deleted', $tester->getDisplay());
		$this->assertStringContainsString('would create meeting ori:v1 Raad', $tester->getDisplay());
	}

	public function testStrictWritesNothingWhenThePreviewFindsAProblem(): void {
		$this->answers(problem: true);

		$tester = $this->runCommand(['--strict' => true]);

		$this->assertSame(Command::FAILURE, $tester->getStatusCode());
		$this->assertSame([['ori', true]], $this->calls);
		$this->assertStringContainsString('problem: agendapunt a9 meeting "x" does not exist', $tester->getDisplay());
		$this->assertStringContainsString('nothing was written', $tester->getDisplay());
	}

	public function testStrictImportsAfterACleanPreview(): void {
		$this->answers(problem: false);

		$tester = $this->runCommand(['--strict' => true]);

		$this->assertSame(Command::SUCCESS, $tester->getStatusCode());
		$this->assertSame([['ori', true], ['ori', false]], $this->calls);
	}

	public function testWithoutStrictAProblemIsReportedButTheRunSucceeds(): void {
		$this->answers(problem: true);

		$tester = $this->runCommand([]);

		$this->assertSame(Command::SUCCESS, $tester->getStatusCode());
		$this->assertSame([['ori', false]], $this->calls);
		$this->assertStringNotContainsString('would create', $tester->getDisplay());
	}

	public function testRollbackRollsBackAndDoesNotImport(): void {
		$report = new OriImportReport(dryRun: false);
		$report->wrote(action: 'deleted', schema: 'meeting', reference: 'ori:v1');
		$this->import->expects($this->once())->method('rollback')->willReturn($report);
		$this->import->expects($this->never())->method('previewRollback');
		$this->import->expects($this->never())->method('import');

		$tester = $this->runCommand(['--rollback' => true]);

		$this->assertSame(Command::SUCCESS, $tester->getStatusCode());
		$this->assertStringContainsString('target meeting: 0 created, 0 updated, 1 deleted', $tester->getDisplay());
	}

	public function testARollbackDryRunOnlyPreviews(): void {
		$this->import->expects($this->never())->method('rollback');
		$this->import->expects($this->once())->method('previewRollback')->willReturn(new OriImportReport(dryRun: true));

		$this->assertSame(Command::SUCCESS, $this->runCommand(['--rollback' => true, '--dry-run' => true])->getStatusCode());
	}
}
