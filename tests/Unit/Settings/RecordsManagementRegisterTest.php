<?php

/**
 * The register declares the archival dossier, its retention category, the
 * Selectielijst categories it ships and the security classification of the
 * archivable records (records-management-archiving tasks 1 and 2).
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
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use OCA\Decidiq\Service\DossierMemberCollector;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class RecordsManagementRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	/**
	 * Every register file, base first, then the fragments in order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function documents(): array {
		$files = array_merge([self::SETTINGS . 'decidesk_register.json'], (glob(self::SETTINGS . 'register.d/*.json') ?: []));
		$docs = [];
		foreach ($files as $file) {
			$docs[] = json_decode((string)file_get_contents($file), true);
		}

		return $docs;
	}//end documents()

	/**
	 * A schema as the merged register has it.
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed>
	 */
	private function mergedSchema(string $slug): array {
		$schema = [];
		foreach ($this->documents() as $doc) {
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === $slug) {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		return $schema;
	}//end mergedSchema()

	/**
	 * The dossier exists, with the canonical lifecycle dialect, the archive
	 * block OpenRegister reads (English `classification`) and no retention
	 * schema of decidiq's own.
	 *
	 * @return void
	 */
	public function testTheDossierIsDeclaredWithOpenRegistersArchiveBlock(): void {
		$dossier = $this->mergedSchema(slug: 'archival-dossier');
		self::assertNotSame([], $dossier, 'archival-dossier is not declared');
		self::assertSame(
			['enabled' => true, 'classification' => '2.1', 'classificationProperty' => 'selectielijstCategorie'],
			$dossier['archive'] ?? null
		);
		// The pointer names a real property: a dossier's own category (DECISIONS row 48).
		self::assertSame('string', $dossier['properties']['selectielijstCategorie']['type'] ?? null);

		$lifecycle = ($dossier['x-openregister-lifecycle'] ?? []);
		self::assertSame('lifecycle', $lifecycle['field'] ?? null);
		self::assertSame('forming', $lifecycle['initial'] ?? null);
		self::assertSame(['forming', 'closed', 'transferred', 'destroyed'], $lifecycle['states'] ?? null);
		self::assertArrayNotHasKey('initialState', $lifecycle);

		foreach (($dossier['properties'] ?? []) as $key => $property) {
			self::assertNotEmpty($property['title'] ?? '', $key . ' has no title');
		}

		foreach (['retention-rule', 'transfer-package', 'destruction-list'] as $slug) {
			self::assertSame([], $this->mergedSchema(slug: $slug), $slug . ' belongs to OpenRegister');
		}
	}//end testTheDossierIsDeclaredWithOpenRegistersArchiveBlock()

	/**
	 * The four Selectielijst 2020 categories ship in the shape OpenRegister's
	 * register import reads (openregister#4228).
	 *
	 * @return void
	 */
	public function testTheSelectionListCategoriesShipWithTheRegister(): void {
		$entries = [];
		foreach ($this->documents() as $doc) {
			foreach (($doc['components']['selectionLists'] ?? []) as $entry) {
				$entries[$entry['category']] = $entry;
			}
		}

		self::assertSame(['2.1', '3.1', '19.1', '11.1'], array_keys($entries));
		self::assertSame('vernietigen', $entries['19.1']['action']);
		self::assertSame(5, $entries['19.1']['retentionYears']);
		self::assertSame('bewaren', $entries['2.1']['action']);
		foreach ($entries as $entry) {
			self::assertSame([], array_diff(array_keys($entry), ['category', 'retentionYears', 'action', 'description', 'organisation']));
		}
	}//end testTheSelectionListCategoriesShipWithTheRegister()

	/**
	 * The archivable records carry a security classification that defaults to
	 * public and only uses levels OpenRegister knows, in its order.
	 *
	 * @return void
	 */
	public function testArchivableRecordsCarryASecurityClassification(): void {
		$orLevels = ['openbaar', 'beperkt_openbaar', 'intern', 'zaakvertrouwelijk', 'vertrouwelijk', 'confidentieel', 'geheim', 'zeer_geheim'];
		foreach (['archival-dossier', 'minutes', 'decision', 'meeting', 'digital-document'] as $slug) {
			$property = ($this->mergedSchema(slug: $slug)['properties']['securityClassification'] ?? []);
			self::assertSame('openbaar', $property['default'] ?? null, $slug);
			$enum = ($property['enum'] ?? []);
			self::assertSame(['openbaar', 'intern', 'vertrouwelijk', 'geheim'], $enum, $slug);
			self::assertSame($enum, array_values(array_intersect($orLevels, $enum)), $slug . ' leaves OpenRegister\'s order');
		}
	}//end testArchivableRecordsCarryASecurityClassification()

	/**
	 * The dossier service finds a meeting's decisions through the decision's
	 * own meeting and agenda item. Fragments merge by schema KEY, so fragment
	 * 67's slug-less `Decision` block declares both on the decision schema.
	 *
	 * @return void
	 */
	public function testADecisionDeclaresItsMeetingAndAgendaItem(): void {
		$decision = [];
		foreach ($this->documents() as $doc) {
			$decision = array_replace_recursive($decision, ($doc['components']['schemas']['Decision'] ?? []));
		}

		self::assertSame('decision', $decision['slug'] ?? null);
		self::assertSame('uuid', $decision['properties']['meeting']['format'] ?? null);
		self::assertSame('agenda-item', $decision['properties']['agendaItem']['$ref'] ?? null);
	}//end testADecisionDeclaresItsMeetingAndAgendaItem()

	/**
	 * The municipality example set carries a forming dossier, a closed one
	 * and one closed with a reason, each valid against the merged schema,
	 * with gaps the service itself writes and no retention values of its own.
	 *
	 * @return void
	 */
	public function testTheExampleDossiersValidate(): void {
		$profile = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/municipality.json'), true);
		$objects = $profile['x-openregister']['seedData']['objects'];
		$dossiers = ($objects['archival-dossier'] ?? []);
		self::assertSame(['forming', 'closed', 'closed'], array_column($dossiers, 'lifecycle'));

		$meetings = array_column($objects['meeting'], 'slug');
		$gaps = [DossierMemberCollector::GAP_MINUTES_MISSING, DossierMemberCollector::GAP_MINUTES_NOT_APPROVED, DossierMemberCollector::GAP_MEETING_NOT_CLOSED];
		foreach ($dossiers as $row) {
			self::assertContains($row['meeting'], $meetings, 'the dossier names a meeting of the example set');
			self::assertSame([], array_diff($row['gaps'], $gaps));
			self::assertArrayNotHasKey('retention', $row, 'OpenRegister resolves retention, the example set never authors it');
			self::assertSame(($row['gaps'] !== [] && $row['lifecycle'] === 'closed'), isset($row['closeOverrideReason']));
			$this->assertValid(row: $row);
		}

		$mock = json_decode((string)file_get_contents(self::SETTINGS . 'decidiq_mock_register.json'), true);
		$demo = array_filter($mock['components']['objects'], static fn (array $object): bool => ($object['@self']['schema'] ?? '') === 'ArchivalDossier');
		self::assertCount(3, $demo, 'the demo register carries three dossiers');
		foreach ($demo as $row) {
			$this->assertValid(row: $row);
		}
	}//end testTheExampleDossiersValidate()

	/**
	 * Validate one example dossier with Opis against the merged schema;
	 * slugs stand in for uuids in the example set.
	 *
	 * @param array<string, mixed> $row The example object
	 *
	 * @return void
	 */
	private function assertValid(array $row): void {
		$schema = $this->mergedSchema(slug: 'archival-dossier');
		unset($row['@self'], $row['slug']);
		$properties = array_map(
			static function (array $property): array {
				unset($property['$ref'], $property['facetable'], $property['items']['$ref'], $property['items']['format']);
				if (($property['format'] ?? '') === 'uuid') {
					unset($property['format']);
				}

				return $property;
			},
			$schema['properties']
		);

		$result = (new Validator())->validate(
			json_decode((string)json_encode($row)),
			json_decode((string)json_encode(['type' => 'object', 'properties' => $properties, 'required' => $schema['required'], 'additionalProperties' => false]))
		);
		self::assertTrue($result->isValid(), 'example dossier must validate: ' . json_encode($row));
	}//end assertValid()
}//end class
