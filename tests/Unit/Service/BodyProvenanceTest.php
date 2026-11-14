<?php

/**
 * Unit tests for BodyProvenance: which row a governing body was imported as.
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

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\BodyProvenance;
use PHPUnit\Framework\TestCase;

/**
 * A row is this provenance's only when both the source app and the external reference match.
 *
 * @covers \OCA\Decidiq\Service\BodyProvenance
 */
class BodyProvenanceTest extends TestCase {

	/**
	 * Both fields must match; one is not enough, and a row without them never matches.
	 *
	 * @return void
	 */
	public function testARowMatchesOnSourceAppAndReference(): void {
		$provenance = new BodyProvenance(sourceApp: 'notubiz', externalReference: 'body-42');

		$this->assertTrue($provenance->isProvenanceOf(row: ['sourceApp' => 'notubiz', 'externalReference' => 'body-42', 'name' => 'Raad']));
		$this->assertFalse($provenance->isProvenanceOf(row: ['sourceApp' => 'notubiz', 'externalReference' => 'body-43']));
		$this->assertFalse($provenance->isProvenanceOf(row: ['sourceApp' => 'ibabs', 'externalReference' => 'body-42']));
		$this->assertFalse($provenance->isProvenanceOf(row: []));
	}//end testARowMatchesOnSourceAppAndReference()

	/**
	 * An empty provenance matches only a row that carries neither field.
	 *
	 * @return void
	 */
	public function testAnEmptyProvenanceMatchesOnlyAnUnmarkedRow(): void {
		$provenance = new BodyProvenance(sourceApp: '', externalReference: '');

		$this->assertTrue($provenance->isProvenanceOf(row: ['name' => 'Raad']));
		$this->assertFalse($provenance->isProvenanceOf(row: ['sourceApp' => 'notubiz']));
	}//end testAnEmptyProvenanceMatchesOnlyAnUnmarkedRow()
}//end class
