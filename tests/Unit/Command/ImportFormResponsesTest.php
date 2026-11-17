<?php

/**
 * Unit tests for the ImportFormResponses command.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/leaf-integrations/spec.md#requirement-req-leaf-004-consultation-intake-shall-be-offered-via-the-forms-leaf-into-the-existing-reaction-path
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Command;

use OCA\Decidiq\Command\ImportFormResponses;
use OCA\Decidiq\Service\Forms\FormResponseImportService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ImportFormResponses::class)]
final class ImportFormResponsesTest extends TestCase {

	/**
	 * The command imports for the named consultation and prints the counts.
	 *
	 * @return void
	 */
	public function testItImportsTheConsultationAndPrintsTheCounts(): void {
		$import = $this->createMock(FormResponseImportService::class);
		$import->expects(self::once())->method('import')->with('c1')
			->willReturn(['forms' => 1, 'imported' => 2, 'skipped' => 3, 'refused' => 1]);

		$tester = new CommandTester(new ImportFormResponses(import: $import));
		$code   = $tester->execute(['consultation' => 'c1']);

		self::assertSame(Command::SUCCESS, $code);
		self::assertStringContainsString('1 linked form(s): 2 imported, 3 imported before, 1 refused', $tester->getDisplay());
		self::assertSame('decidiq:consultation:import-form-responses', (new ImportFormResponses(import: $import))->getName());
	}//end testItImportsTheConsultationAndPrintsTheCounts()

	/**
	 * A refused import fails the command with its reason.
	 *
	 * @return void
	 */
	public function testARefusedImportFails(): void {
		$import = $this->createMock(FormResponseImportService::class);
		$import->method('import')->willThrowException(new RuntimeException('PublicConsultation c9 not found'));

		$tester = new CommandTester(new ImportFormResponses(import: $import));

		self::assertSame(Command::FAILURE, $tester->execute(['consultation' => 'c9']));
		self::assertStringContainsString('PublicConsultation c9 not found', $tester->getDisplay());
	}//end testARefusedImportFails()
}//end class
