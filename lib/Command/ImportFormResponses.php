<?php

/**
 * Decidiq Import Form Responses Command
 *
 * Runs as occ decidiq:consultation:import-form-responses <consultation>.
 *
 * @category Command
 * @package  OCA\Decidiq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Command;

use OCA\Decidiq\Service\Forms\FormResponseImportService;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Import the responses of a consultation's intake form as pending reactions, on demand.
 *
 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
 */
class ImportFormResponses extends Command {

	/**
	 * Constructor.
	 *
	 * @param FormResponseImportService $import The form response import.
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	public function __construct(
		private readonly FormResponseImportService $import,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and the consultation argument.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	protected function configure(): void {
		$this->setName(name: 'decidiq:consultation:import-form-responses')
			->setDescription(description: 'Import the responses of the forms linked to a consultation as reactions awaiting moderation')
			->addArgument(name: 'consultation', mode: InputArgument::REQUIRED, description: 'The consultation UUID');
	}//end configure()

	/**
	 * Run the import and print the counts.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$counts = $this->import->import(consultationId: (string)$input->getArgument('consultation'));
		} catch (RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return self::FAILURE;
		}

		$output->writeln(
			sprintf(
				'%d linked form(s): %d imported, %d imported before, %d refused (empty or too long).',
				$counts['forms'],
				$counts['imported'],
				$counts['skipped'],
				$counts['refused']
			)
		);

		return self::SUCCESS;
	}//end execute()
}//end class
