<?php

/**
 * Unit tests for SecurityClassification: decidiq's four labels are a strict
 * subset of OpenRegister's confidentiality ordinal, in the same order.
 *
 * OpenRegister's ordinal, ZaaktypeAuthorizationService::
 * VERTROUWELIJKHEIDAANDUIDING_LEVELS (openregister development): openbaar,
 * beperkt_openbaar, intern, zaakvertrouwelijk, vertrouwelijk, confidentieel,
 * geheim, zeer_geheim. The register fragment's enum is read from the file.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service\Records
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-008-security-classification-labels-on-archival-records
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service\Records;

use OCA\Decidiq\Service\Records\SecurityClassification;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the classification mapping.
 *
 * @covers \OCA\Decidiq\Service\Records\SecurityClassification
 */
class SecurityClassificationTest extends TestCase {

	private const OPENREGISTER_LEVELS = ['openbaar', 'beperkt_openbaar', 'intern', 'zaakvertrouwelijk', 'vertrouwelijk', 'confidentieel', 'geheim', 'zeer_geheim'];

	/**
	 * Every label maps onto OpenRegister's ordinal, order preserved, and the
	 * fragment's enum is exactly the mapped labels.
	 *
	 * @return void
	 */
	public function testTheLabelsAreAnOrderedSubsetOfOpenRegistersOrdinal(): void {
		$positions = [];
		foreach (SecurityClassification::LABELS as $label) {
			$level = SecurityClassification::openRegisterLevel(label: $label);
			self::assertContains($level, self::OPENREGISTER_LEVELS, $label);
			$positions[] = array_search($level, self::OPENREGISTER_LEVELS, true);
		}

		$sorted = $positions;
		sort($sorted);
		self::assertSame($sorted, $positions, 'same relative order as OpenRegister');
		self::assertSame(count($positions), count(array_unique($positions)));

		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/register.d/115-records-management-archiving.json'), true);
		foreach (['ArchivalDossier', 'Minutes', 'Decision', 'Meeting', 'DigitalDocument'] as $schema) {
			self::assertSame(SecurityClassification::LABELS, $fragment['components']['schemas'][$schema]['properties']['securityClassification']['enum'], $schema);
		}
	}//end testTheLabelsAreAnOrderedSubsetOfOpenRegistersOrdinal()

	/**
	 * Comparison and the most restrictive of a set; an absent or unknown
	 * label reads as public, and nothing above public is publishable.
	 *
	 * @return void
	 */
	public function testRestrictivenessAndPublishability(): void {
		self::assertTrue(SecurityClassification::isMoreRestrictive(label: 'vertrouwelijk', than: 'openbaar'));
		self::assertFalse(SecurityClassification::isMoreRestrictive(label: 'intern', than: 'geheim'));
		self::assertFalse(SecurityClassification::isMoreRestrictive(label: null, than: 'openbaar'));
		self::assertSame('openbaar', SecurityClassification::normalise(label: 'onbekend'));
		self::assertTrue(SecurityClassification::isPublishable(label: null));
		self::assertTrue(SecurityClassification::isPublishable(label: 'openbaar'));
		self::assertFalse(SecurityClassification::isPublishable(label: 'intern'));
	}//end testRestrictivenessAndPublishability()
}//end class
