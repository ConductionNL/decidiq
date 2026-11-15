<?php

/**
 * Unit tests for the BodyProvenance value object.
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
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\BodyProvenance;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Decidiq\Service\BodyProvenance
 */
class BodyProvenanceTest extends TestCase {

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function rows(): array {
		return [
			'same app and reference' => [['sourceApp' => 'pipelinq', 'externalReference' => 'ext-7'], true],
			'other app' => [['sourceApp' => 'dossiq', 'externalReference' => 'ext-7'], false],
			'other reference' => [['sourceApp' => 'pipelinq', 'externalReference' => 'ext-8'], false],
			'no provenance' => [[], false],
			'only the app' => [['sourceApp' => 'pipelinq'], false],
			'case differs' => [['sourceApp' => 'Pipelinq', 'externalReference' => 'ext-7'], false],
		];

	}//end rows()

	/**
	 * @dataProvider rows
	 *
	 * @param array<string, mixed> $row The candidate row
	 */
	public function testMatchesOnlyWhenBothAppAndReferenceAgree(array $row, bool $expected): void {
		$provenance = new BodyProvenance(sourceApp: 'pipelinq', externalReference: 'ext-7');

		$this->assertSame($expected, $provenance->isProvenanceOf($row));

	}//end testMatchesOnlyWhenBothAppAndReferenceAgree()

	public function testNumericReferencesAreComparedAsStrings(): void {
		$provenance = new BodyProvenance(sourceApp: 'pipelinq', externalReference: '17');

		$this->assertTrue($provenance->isProvenanceOf(['sourceApp' => 'pipelinq', 'externalReference' => 17]));

	}//end testNumericReferencesAreComparedAsStrings()

	public function testAnEmptyProvenanceMatchesARowWithoutOne(): void {
		$provenance = new BodyProvenance(sourceApp: '', externalReference: '');

		$this->assertTrue($provenance->isProvenanceOf([]));

	}//end testAnEmptyProvenanceMatchesARowWithoutOne()
}//end class
