<?php

/**
 * Unit tests for SecurityClassification label by label: how a raw label is
 * normalised, which OpenRegister level each lands on, the full
 * restrictiveness order and what may be published.
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
 * Tests for each label's normalisation, level, order and publishability.
 *
 * @covers \OCA\Decidiq\Service\Records\SecurityClassification
 */
class SecurityClassificationBehaviourTest extends TestCase {

	/**
	 * A raw label is trimmed and lower-cased before it is matched; anything
	 * that is not one of the four, including a near miss, reads as public.
	 *
	 * @param string|null $raw      The raw label
	 * @param string      $expected The normalised label
	 *
	 * @return void
	 *
	 * @dataProvider rawLabels
	 */
	public function testARawLabelIsNormalised(?string $raw, string $expected): void {
		self::assertSame($expected, (new SecurityClassification())->normalise(label: $raw));
	}//end testARawLabelIsNormalised()

	/**
	 * Raw labels and what they normalise to.
	 *
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function rawLabels(): array {
		return [
			'null' => [null, 'openbaar'],
			'empty' => ['', 'openbaar'],
			'whitespace' => ['   ', 'openbaar'],
			'upper case' => ['GEHEIM', 'geheim'],
			'mixed case with spaces' => ["  Vertrouwelijk\t", 'vertrouwelijk'],
			'intern' => ['intern', 'intern'],
			'an OpenRegister level decidiq does not use' => ['zeer_geheim', 'openbaar'],
			'a near miss' => ['interne', 'openbaar'],
		];
	}//end rawLabels()

	/**
	 * Each label lands on the OpenRegister level of the same name, after
	 * normalisation; an unknown label lands on public.
	 *
	 * @return void
	 */
	public function testEachLabelLandsOnItsOpenRegisterLevel(): void {
		$classification = new SecurityClassification();

		self::assertSame('openbaar', $classification->openRegisterLevel(label: 'openbaar'));
		self::assertSame('intern', $classification->openRegisterLevel(label: ' Intern '));
		self::assertSame('vertrouwelijk', $classification->openRegisterLevel(label: 'vertrouwelijk'));
		self::assertSame('geheim', $classification->openRegisterLevel(label: 'GEHEIM'));
		self::assertSame('openbaar', $classification->openRegisterLevel(label: 'confidentieel'));
		self::assertSame('openbaar', $classification->openRegisterLevel(label: null));
	}//end testEachLabelLandsOnItsOpenRegisterLevel()

	/**
	 * Over every pair, a label is more restrictive exactly when it comes
	 * later in LABELS; a label is never more restrictive than itself.
	 *
	 * @return void
	 */
	public function testRestrictivenessFollowsTheOrderOfTheLabels(): void {
		$classification = new SecurityClassification();
		foreach (SecurityClassification::LABELS as $i => $label) {
			foreach (SecurityClassification::LABELS as $j => $than) {
				self::assertSame($i > $j, $classification->isMoreRestrictive(label: $label, than: $than), $label . ' vs ' . $than);
			}
		}
	}//end testRestrictivenessFollowsTheOrderOfTheLabels()

	/**
	 * Comparison normalises both sides: an unknown label counts as public,
	 * so it is less restrictive than internal, and case does not matter.
	 *
	 * @return void
	 */
	public function testComparisonNormalisesBothSides(): void {
		$classification = new SecurityClassification();

		self::assertTrue($classification->isMoreRestrictive(label: 'intern', than: 'onbekend'));
		self::assertFalse($classification->isMoreRestrictive(label: 'onbekend', than: 'intern'));
		self::assertFalse($classification->isMoreRestrictive(label: 'GEHEIM', than: 'geheim'));
		self::assertTrue($classification->isMoreRestrictive(label: 'Geheim', than: 'VERTROUWELIJK'));
		self::assertFalse($classification->isMoreRestrictive(label: null, than: null));
	}//end testComparisonNormalisesBothSides()

	/**
	 * Only a public label is publishable; every other label, however
	 * written, is not.
	 *
	 * @return void
	 */
	public function testOnlyPublicIsPublishable(): void {
		$classification = new SecurityClassification();

		self::assertTrue($classification->isPublishable(label: ' OPENBAAR '));
		self::assertTrue($classification->isPublishable(label: ''));
		self::assertFalse($classification->isPublishable(label: 'Intern'));
		self::assertFalse($classification->isPublishable(label: 'vertrouwelijk'));
		self::assertFalse($classification->isPublishable(label: 'geheim'));
	}//end testOnlyPublicIsPublishable()
}//end class
