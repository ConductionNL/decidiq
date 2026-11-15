<?php

/**
 * Decidiq portal label translator test
 *
 * @category Tests
 * @package  OCA\Decidiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/council-calendar-meeting-type-in-the-readers-language/specs/activity-calendar/spec.md#requirement-the-council-calendar-names-the-meeting-type-in-the-readers-language
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Portal;

use OCA\Decidiq\Portal\PortalLabelTranslator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Value labels are translated and their stored values kept.
 *
 * @covers \OCA\Decidiq\Portal\PortalLabelTranslator
 */
class PortalLabelTranslatorTest extends TestCase {
	/**
	 * A flat map and a map per field are both translated; keys, empty and
	 * non-string labels stay as they are.
	 *
	 * @return void
	 */
	public function testValueLabelsAreTranslatedAndTheirValuesKept(): void {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:'.$text);

		$translated = (new PortalLabelTranslator(l10n: $l10n))->translate(
			manifest: [
				'label' => 'Calendar',
				'filter' => ['label' => 'machine'],
				'valueLabels' => [
					'meetingType' => ['regular' => 'Regular meeting', 'empty' => ''],
					'kind' => 'Meeting',
					'count' => 3,
				],
			]
		);

		self::assertSame(
			expected: [
				'label' => 'nl:Calendar',
				'filter' => ['label' => 'machine'],
				'valueLabels' => [
					'meetingType' => ['regular' => 'nl:Regular meeting', 'empty' => ''],
					'kind' => 'nl:Meeting',
					'count' => 3,
				],
			],
			actual: $translated
		);
	}//end testValueLabelsAreTranslatedAndTheirValuesKept()

	/**
	 * Without a catalogue the manifest stays in its English source.
	 *
	 * @return void
	 */
	public function testWithoutACatalogueNothingChanges(): void {
		$manifest = ['valueLabels' => ['regular' => 'Regular meeting']];

		self::assertSame(expected: $manifest, actual: (new PortalLabelTranslator())->translate(manifest: $manifest));
	}//end testWithoutACatalogueNothingChanges()
}//end class
