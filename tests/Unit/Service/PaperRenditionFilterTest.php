<?php

/**
 * Tests for PaperRenditionFilter: the meeting package carries the PDF, not
 * the Office paper it was made of.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\PaperRenditionFilter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\PaperRenditionFilter
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf
 */
class PaperRenditionFilterTest extends TestCase {

	/**
	 * A file node with an id and a name.
	 *
	 * @param int    $id   The file id.
	 * @param string $name The file name.
	 *
	 * @return object
	 */
	private function node(int $id, string $name): object {
		return new class($id, $name) {
			/**
			 * @param int    $id   The file id.
			 * @param string $name The file name.
			 */
			public function __construct(private int $id, private string $name) {
			}

			/** @return int */
			public function getId(): int {
				return $this->id;
			}

			/** @return string */
			public function getName(): string {
				return $this->name;
			}
		};
	}//end node()

	/**
	 * The names of the nodes.
	 *
	 * @param array<int, object> $nodes The nodes.
	 *
	 * @return list<string>
	 */
	private function names(array $nodes): array {
		return array_map(static fn (object $n): string => $n->getName(), $nodes);
	}//end names()

	/**
	 * A converted paper whose PDF is among the files leaves the package; the
	 * PDF and every other file stay.
	 *
	 * @return void
	 */
	public function testThePackageHoldsThePdfAndNotTheWordFile(): void {
		$nodes = [$this->node(11, 'Programmabegroting 2027.docx'), $this->node(12, 'Programmabegroting 2027.pdf'), $this->node(13, 'Bijlage investeringen.xlsx')];
		$renditions = [
			['sourceFileId' => 11, 'sourceName' => 'Programmabegroting 2027.docx', 'pdfFileId' => 12, 'backend' => 'office'],
			['sourceFileId' => 13, 'sourceName' => 'Bijlage investeringen.xlsx', 'failure' => 'No backend could convert this file'],
		];

		$kept = (new PaperRenditionFilter())->preferPdf(nodes: $nodes, renditions: $renditions);

		$this->assertSame(['Programmabegroting 2027.pdf', 'Bijlage investeringen.xlsx'], $this->names($kept));
	}//end testThePackageHoldsThePdfAndNotTheWordFile()

	/**
	 * A rendition whose PDF is not among the files keeps the original, so a
	 * paper never disappears from the package.
	 *
	 * @return void
	 */
	public function testAMissingPdfKeepsTheOriginal(): void {
		$nodes = [$this->node(11, 'Programmabegroting 2027.docx')];

		$kept = (new PaperRenditionFilter())->preferPdf(nodes: $nodes, renditions: [['sourceFileId' => 11, 'sourceName' => 'x', 'pdfFileId' => 99]]);

		$this->assertSame(['Programmabegroting 2027.docx'], $this->names($kept));
	}//end testAMissingPdfKeepsTheOriginal()
}//end class
