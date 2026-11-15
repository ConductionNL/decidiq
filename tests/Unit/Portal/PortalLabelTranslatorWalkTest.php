<?php

/**
 * Decidiq portal label translator walk test
 *
 * The translator walks a whole manifest: the visible keys at any depth are
 * read in the reader's language, and identifiers and machine values never.
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Portal;

use OCA\Decidiq\Portal\PortalLabelTranslator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Visible strings are translated wherever they sit; everything portaliq matches on stays.
 *
 * @covers \OCA\Decidiq\Portal\PortalLabelTranslator
 */
class PortalLabelTranslatorWalkTest extends TestCase {

	/**
	 * The translator over a catalogue.
	 *
	 * @param IL10N $l10n The catalogue.
	 *
	 * @return PortalLabelTranslator
	 */
	private static function translator(IL10N $l10n): PortalLabelTranslator {
		return new PortalLabelTranslator(l10n: $l10n);
	}//end translator()

	/**
	 * A catalogue that prefixes every string it is asked for.
	 *
	 * @return IL10N
	 */
	private function catalogue(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);

		return $l10n;
	}//end catalogue()

	/**
	 * Labels and groups are translated at any depth, in lists and maps alike.
	 *
	 * @return void
	 */
	public function testVisibleKeysAreTranslatedAtAnyDepth(): void {
		$translated = self::translator($this->catalogue())->translate(
			manifest: [
				'label' => 'Council and participation',
				'collections' => [
					['id' => 'publicCalendar', 'label' => 'Council calendar', 'columns' => [['field' => 'title', 'label' => 'Meeting']]],
					['id' => 'citizenVotes', 'group' => 'My participation'],
				],
				'actions' => ['subscribe' => ['label' => 'Follow publications', 'schema' => 'subscription']],
			]
		);

		self::assertSame(
			[
				'label' => 'nl:Council and participation',
				'collections' => [
					['id' => 'publicCalendar', 'label' => 'nl:Council calendar', 'columns' => [['field' => 'title', 'label' => 'nl:Meeting']]],
					['id' => 'citizenVotes', 'group' => 'nl:My participation'],
				],
				'actions' => ['subscribe' => ['label' => 'nl:Follow publications', 'schema' => 'subscription']],
			],
			$translated
		);
	}//end testVisibleKeysAreTranslatedAtAnyDepth()

	/**
	 * Nothing below a machine key is translated, even a key named label.
	 *
	 * @return void
	 */
	public function testMachineKeysAreNeverTranslated(): void {
		$machine = [
			'fields' => ['label', 'title'],
			'defaults' => ['label' => 'x'],
			'defaultFilters' => ['label' => 'x'],
			'defaultSort' => ['label' => 'asc'],
			'parentConstraint' => ['label' => 'open'],
			'set' => ['label' => 'y'],
			'filter' => ['label' => 'z'],
		];

		self::assertSame($machine, self::translator($this->catalogue())->translate(manifest: $machine));
	}//end testMachineKeysAreNeverTranslated()

	/**
	 * Identifiers, other strings, empty labels and non-string labels stay as they are.
	 *
	 * @return void
	 */
	public function testOnlyNonEmptyVisibleStringsAreTranslated(): void {
		$manifest = [
			'id' => 'citizenReactions',
			'schema' => 'consultation-reaction',
			'title' => 'Reactions',
			'description' => 'What you said',
			'label' => '',
			'group' => 7,
			'valueLabels' => 'Meeting',
		];

		self::assertSame($manifest, self::translator($this->catalogue())->translate(manifest: $manifest));
	}//end testOnlyNonEmptyVisibleStringsAreTranslated()

	/**
	 * Nested value-label maps keep their stored values as keys at every level.
	 *
	 * @return void
	 */
	public function testNestedValueLabelsKeepTheirValues(): void {
		$translated = self::translator($this->catalogue())->translate(
			manifest: [
				'fieldConfigs' => [
					'oriType' => ['label' => 'Kind', 'valueLabels' => ['Vergadering' => 'Meeting', 'Besluit' => 'Decision']],
				],
			]
		);

		self::assertSame(
			['fieldConfigs' => ['oriType' => ['label' => 'nl:Kind', 'valueLabels' => ['Vergadering' => 'nl:Meeting', 'Besluit' => 'nl:Decision']]]],
			$translated
		);
	}//end testNestedValueLabelsKeepTheirValues()

	/**
	 * The catalogue is asked once per visible string, and never for a machine value.
	 *
	 * @return void
	 */
	public function testTheCatalogueIsAskedOnlyForVisibleStrings(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects(self::exactly(2))
			->method('t')
			->willReturnCallback(static fn (string $text): string => $text);

		self::translator($l10n)->translate(
			manifest: ['label' => 'A', 'group' => 'B', 'id' => 'C', 'fields' => ['label' => 'D']]
		);
	}//end testTheCatalogueIsAskedOnlyForVisibleStrings()
}//end class
