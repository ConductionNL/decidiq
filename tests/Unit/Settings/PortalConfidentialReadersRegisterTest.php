<?php

/**
 * The register fields for named portal readers of a confidential agenda item, and the example set's item.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * An agenda item names its portal readers by person; a person carries its portal account.
 *
 * @coversNothing
 */
class PortalConfidentialReadersRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	private const FRAGMENT = self::SETTINGS . 'register.d/999-portal-confidential-readers.json';

	/**
	 * The agenda item names its portal readers as a list of person ids, empty by default.
	 *
	 * @return void
	 */
	public function testTheAgendaItemNamesItsPortalReaders(): void {
		$property = MergedRegisterSchema::forSlug(slug: 'agenda-item')['properties']['authorisedPersons'];

		self::assertSame('array', $property['type']);
		self::assertSame(['type' => 'string'], $property['items']);
		self::assertSame([], $property['default']);
		self::assertSame(
			[],
			MergedRegisterSchema::violations(slug: 'agenda-item', payload: ['title' => 'Grondaankoop Lindelaan', 'itemType' => 'decision', 'orderNumber' => 10, 'public' => false, 'authorisedPersons' => ['pieter-bos']])
		);

	}//end testTheAgendaItemNamesItsPortalReaders()

	/**
	 * A person carries the reference of its portal account, a plain string.
	 *
	 * @return void
	 */
	public function testThePersonCarriesItsPortalAccount(): void {
		$property = MergedRegisterSchema::forSlug(slug: 'person')['properties']['portalAccountRef'];

		self::assertSame('string', $property['type']);
		self::assertSame([], MergedRegisterSchema::violations(slug: 'person', payload: ['name' => 'Pieter Bos', 'portalAccountRef' => 'portal-account-pieter-bos']));

	}//end testThePersonCarriesItsPortalAccount()

	/**
	 * The fragment names a slug on every schema and its versions win the merge.
	 *
	 * @return void
	 */
	public function testEveryFragmentSchemaHasASlugAndItsVersionWins(): void {
		$schemas = json_decode((string)file_get_contents(self::FRAGMENT), true)['components']['schemas'];

		self::assertSame(['agenda-item', 'person'], array_values(array_map(static fn (array $schema): string => (string)($schema['slug'] ?? ''), $schemas)));
		self::assertSame('1.7.0', MergedRegisterSchema::forSlug(slug: 'agenda-item')['version']);
		self::assertSame('0.4.0', MergedRegisterSchema::forSlug(slug: 'person')['version']);

	}//end testEveryFragmentSchemaHasASlugAndItsVersionWins()

	/**
	 * The municipality example set holds a non-public item with two non-public papers and one named reader.
	 *
	 * @return void
	 */
	public function testTheExampleSetHasAConfidentialItemWithANamedReader(): void {
		$objects = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/municipality.json'), true)['x-openregister']['seedData']['objects'];

		$items = array_values(array_filter($objects['agenda-item'], static fn (array $item): bool => ($item['title'] ?? '') === 'Grondaankoop Lindelaan'));
		self::assertCount(1, $items);
		self::assertFalse($items[0]['public']);
		self::assertSame(['pieter-bos'], $items[0]['authorisedPersons']);

		$papers = array_values(array_filter($objects['digital-document'], static fn (array $doc): bool => ($doc['agendaItem'] ?? '') === $items[0]['slug']));
		self::assertCount(2, $papers);
		self::assertSame([false, false], array_map(static fn (array $doc): bool => $doc['public'], $papers));

		$readers = array_values(array_filter($objects['person'], static fn (array $person): bool => $person['slug'] === 'pieter-bos'));
		self::assertCount(1, $readers);
		self::assertSame('Pieter Bos', $readers[0]['name']);
		self::assertNotSame('', $readers[0]['portalAccountRef']);
		self::assertSame([], MergedRegisterSchema::violations(slug: 'agenda-item', payload: array_diff_key($items[0], ['@self' => true, 'slug' => true])));

	}//end testTheExampleSetHasAConfidentialItemWithANamedReader()
}//end class
