<?php

/**
 * The governing-documents register fragment and its seed data, read from the shipped files.
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
 * @spec openspec/changes/governing-documents-register
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Tasks 1, 2 and 6 of governing-documents-register.
 */
class GoverningDocumentsRegisterFragmentTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	/**
	 * A JSON file under lib/Settings.
	 *
	 * @param string $path The path under lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		$decoded = json_decode((string)file_get_contents(self::SETTINGS . $path), true);
		self::assertIsArray($decoded, $path);
		return $decoded;
	}//end json()

	/**
	 * The fragment's schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		return self::json('register.d/55-governing-documents-register.json')['components']['schemas'];
	}//end schemas()

	/**
	 * The seed objects of a profile, by slug.
	 *
	 * @param string $profile The profile file.
	 * @param string $schema  The schema slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function seeds(string $profile, string $schema): array {
		$bySlug = [];
		foreach ((self::json('profiles/' . $profile)['x-openregister']['seedData']['objects'][$schema] ?? []) as $object) {
			$bySlug[(string)$object['slug']] = $object;
		}

		return $bySlug;
	}//end seeds()

	public function testADocumentNeedsItsCitationTitleAndGoverningBody(): void {
		$document = self::schemas()['GoverningDocument'];

		self::assertSame('governing-document', $document['slug']);
		self::assertContains('citationTitle', $document['required']);
		self::assertContains('governingBody', $document['required']);
		self::assertSame('governance-body', $document['properties']['governingBody']['$ref']);
		self::assertSame(['in-force', 'lapsed'], $document['x-openregister-lifecycle']['states']);
		self::assertSame([['from' => 'in-force', 'to' => 'lapsed']], $document['x-openregister-lifecycle']['transitions']);
	}//end testADocumentNeedsItsCitationTitleAndGoverningBody()

	public function testAVersionRefersToItsDocumentAndItsEnactingDecision(): void {
		$version = self::schemas()['GoverningDocumentVersie'];

		self::assertSame('governing-document-versie', $version['slug']);
		self::assertSame('governing-document', $version['properties']['document']['$ref']);
		self::assertSame('Decision', $version['properties']['determinedBy']['$ref']);
		self::assertNotContains('determinedBy', $version['required'], 'a constitutive first version may omit it; the rule is save-time');
		self::assertSame('draft', $version['x-openregister-lifecycle']['initial']);
		self::assertSame(['replaced', 'lapsed'], $version['x-openregister-lifecycle']['terminal']);
	}//end testAVersionRefersToItsDocumentAndItsEnactingDecision()

	public function testTheNotificationFiresOnlyWhenAVersionTakesEffect(): void {
		$rule = self::schemas()['GoverningDocumentVersie']['x-openregister-notifications']['newEffectiveVersion'];

		self::assertSame('updated', $rule['trigger']['type']);
		self::assertSame(['field' => 'status', 'operator' => 'equals', 'value' => 'in-effect', 'from' => 'adopted'], $rule['trigger']['condition']);
		self::assertSame(['object-acl', 'groups'], array_column($rule['recipients'], 'kind'));
		self::assertSame(['decidesk-members'], $rule['recipients'][1]['groups']);
		self::assertSame('Nieuwe geldende versie: {{title}}', $rule['subject']['nl']);
		self::assertSame('New effective version: {{title}}', $rule['subject']['en']);
	}//end testTheNotificationFiresOnlyWhenAVersionTakesEffect()

	public function testTheDecisionCitationIsOptionalAndCarriesTheReferenceShape(): void {
		$decision = self::schemas()['Decision'];
		$citation = $decision['properties']['citesGoverningDocuments'];

		self::assertArrayNotHasKey('required', $decision, 'existing decisions stay valid');
		self::assertSame('array', $citation['type']);
		self::assertSame(['document'], $citation['items']['required']);
		self::assertSame(['document', 'version', 'article'], array_keys($citation['items']['properties']));
	}//end testTheDecisionCitationIsOptionalAndCarriesTheReferenceShape()

	public function testOnlyAPublicDocumentIsReadAnonymously(): void {
		$read = self::schemas()['GoverningDocument']['authorization']['read'];

		self::assertSame(['group' => 'public', 'match' => ['isPublic' => true], 'crossOrganisation' => true], $read[0]);
		self::assertFalse(self::schemas()['GoverningDocument']['properties']['isPublic']['default']);
		self::assertArrayNotHasKey('authorization', self::schemas()['GoverningDocumentVersie'], 'versions are never read anonymously');
	}//end testOnlyAPublicDocumentIsReadAnonymously()

	public function testTheStatutenChainIsSeededWithItsDeedAndItsAmendment(): void {
		$documents = self::seeds('association.json', 'governing-document');
		$versions  = self::seeds('association.json', 'governing-document-versie');

		self::assertTrue($documents['statuten-vng']['isPublic']);
		self::assertSame('replaced', $versions['statuten-vng-v1']['status']);
		self::assertNull($versions['statuten-vng-v1']['determinedBy'] ?? null);
		self::assertNotEmpty($versions['statuten-vng-v1']['deedDate']);
		self::assertNotEmpty($versions['statuten-vng-v1']['notary']);
		self::assertSame('in-effect', $versions['statuten-vng-v2']['status']);
		self::assertSame('besluit-statutenwijziging-vng-2021', $versions['statuten-vng-v2']['determinedBy']);
	}//end testTheStatutenChainIsSeededWithItsDeedAndItsAmendment()

	public function testTheDeedOfDivisionAndTheRulesOfProcedureAreSeeded(): void {
		$association = self::seeds('association.json', 'governing-document-versie');
		$municipal   = self::seeds('municipality.json', 'governing-document-versie');

		self::assertSame('vve-parkstaete', self::seeds('association.json', 'governing-document')['splitsingsakte-vve-parkstaete']['governingBody']);
		self::assertNull($association['splitsingsakte-parkstaete-v1']['determinedBy'] ?? null);
		self::assertNotEmpty($association['splitsingsakte-parkstaete-v1']['deedDate']);
		self::assertSame('besluit-vaststelling-rvo-amsterdam', $municipal['rvo-amsterdam-v1']['determinedBy']);
	}//end testTheDeedOfDivisionAndTheRulesOfProcedureAreSeeded()

	public function testTheSeededAmendmentDecisionCitesTheStatuten(): void {
		$decisions = self::seeds('association.json', 'decision');
		$citations = $decisions['besluit-statutenwijziging-vng-2021']['citesGoverningDocuments'];

		self::assertSame('statuten-vng', $citations[0]['document']);
		self::assertNotEmpty($citations[0]['article']);
	}//end testTheSeededAmendmentDecisionCitesTheStatuten()
}//end class
