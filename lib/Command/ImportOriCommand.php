<?php

/**
 * occ decidiq:import-ori — migrate council information from an ORI register.
 *
 * @category Command
 * @package  OCA\Decidiq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Command;

use OCA\Decidiq\Service\Ori\OriImportReport;
use OCA\Decidiq\Service\Ori\OriImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Import, preview or roll back the migration of an ORI register into decidiq.
 *
 * `--strict` previews first and writes nothing when the preview finds a
 * problem, so a strict run either imports everything or nothing.
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */
class ImportOriCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param OriImportService $import The importer.
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function __construct(
		private readonly OriImportService $import,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'decidiq:import-ori')
			->setDescription(description: 'Import council information from an ORI register into decidiq (the source register is only read)')
			->addOption(name: 'source-register', mode: InputOption::VALUE_REQUIRED, description: 'Slug of the source register', default: 'ori')
			->addOption(name: 'dry-run', mode: InputOption::VALUE_NONE, description: 'Write nothing; report counts and every object that would be written')
			->addOption(name: 'rollback', mode: InputOption::VALUE_NONE, description: 'Delete every object an import wrote (tagged ori:*), and nothing else')
			->addOption(name: 'strict', mode: InputOption::VALUE_NONE, description: 'Write nothing when a reference or value cannot be carried over');
	}//end configure()

	/**
	 * Run the import, the preview or the rollback.
	 *
	 * @param InputInterface  $input  The options.
	 * @param OutputInterface $output Where the report goes.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = ($input->getOption('dry-run') === true);

		if ($input->getOption('rollback') === true) {
			return $this->rollback(output: $output, dryRun: $dryRun);
		}

		$source = (string)$input->getOption('source-register');
		if ($input->getOption('strict') === true && $dryRun === false && $this->strictPreviewFails(output: $output, source: $source) === true) {
			return self::FAILURE;
		}

		if ($dryRun === true) {
			$this->write(output: $output, report: $this->import->preview(sourceRegister: $source));
			return self::SUCCESS;
		}

		$report = $this->import->import(sourceRegister: $source);
		$this->write(output: $output, report: $report);
		if ($input->getOption('strict') === true && $report->problems() !== []) {
			return self::FAILURE;
		}

		return self::SUCCESS;
	}//end execute()

	/**
	 * Roll the import back, or preview the rollback in a dry run.
	 *
	 * @param OutputInterface $output Where the report goes.
	 * @param bool            $dryRun Whether to preview only.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	private function rollback(OutputInterface $output, bool $dryRun): int {
		if ($dryRun === true) {
			$this->write(output: $output, report: $this->import->previewRollback());
			return self::SUCCESS;
		}

		$this->write(output: $output, report: $this->import->rollback());
		return self::SUCCESS;
	}//end rollback()

	/**
	 * Preview a strict import and print it when it found problems, so nothing is written.
	 *
	 * @param OutputInterface $output Where the report goes.
	 * @param string          $source The source register.
	 *
	 * @return bool True when the preview found problems.
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	private function strictPreviewFails(OutputInterface $output, string $source): bool {
		$preview = $this->import->preview(sourceRegister: $source);
		if ($preview->problems() === []) {
			return false;
		}

		$this->write(output: $output, report: $preview);
		$output->writeln('<error>Strict: ' . count($preview->problems()) . ' problem(s) found, nothing was written.</error>');
		return true;
	}//end strictPreviewFails()

	/**
	 * Print counts, the preview of a dry run, and every problem.
	 *
	 * @param OutputInterface $output Where to print.
	 * @param OriImportReport $report The report.
	 *
	 * @return void
	 */
	private function write(OutputInterface $output, OriImportReport $report): void {
		$prefix = '';
		if ($report->dryRun === true) {
			$prefix = '[dry run] ';
		}

		foreach ($report->sourceCounts() as $schema => $count) {
			$output->writeln(sprintf('%ssource %s: %d', $prefix, $schema, $count));
		}

		foreach ($report->targetCounts() as $schema => $counts) {
			$output->writeln(
				sprintf('%starget %s: %d created, %d updated, %d deleted', $prefix, $schema, $counts['created'], $counts['updated'], $counts['deleted'])
			);
		}

		$this->writePreview(output: $output, report: $report);

		foreach ($report->problems() as $problem) {
			$output->writeln(sprintf('<comment>problem: %s %s %s</comment>', $problem['schema'], $problem['source'], $problem['problem']));
		}
	}//end write()

	/**
	 * Print what a dry run would write; a real run prints nothing here.
	 *
	 * @param OutputInterface $output Where to print.
	 * @param OriImportReport $report The report.
	 *
	 * @return void
	 */
	private function writePreview(OutputInterface $output, OriImportReport $report): void {
		if ($report->dryRun !== true) {
			return;
		}

		foreach ($report->preview() as $line) {
			$output->writeln(sprintf('  would %s %s %s %s', rtrim($line['action'], 'd'), $line['schema'], $line['reference'], $line['label']));
		}
	}//end writePreview()
}//end class
