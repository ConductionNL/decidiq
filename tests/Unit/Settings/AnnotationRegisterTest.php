<?php

/**
 * An annotation on a document is an OpenRegister object bound to the document
 * version it was made on: the schema is declared with its anchors, its read
 * rule shows a member only their own annotations, and the municipality example
 * set seeds four annotations of every type and visibility (document-annotations
 * task 1).
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
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-001-annotation-is-an-openregister-object-anchored-to-a-document-version
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Annotation schema and its seeds, read from the merged register files.
 */
class AnnotationRegisterTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	private const READERS = ['decidiq-administrators', 'decidesk-administrators'];

	private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

	/**
	 * The schema with the given slug, every register file merged in order.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string, mixed>
	 */
	private function mergedSchema(string $slug): array {
		$files = array_merge([self::SETTINGS . 'decidesk_register.json'], (glob(self::SETTINGS . 'register.d/*.json') ?: []));
		$schema = [];
		foreach ($files as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach (($doc['components']['schemas'] ?? []) as $name => $fragment) {
				if (($fragment['slug'] ?? $name) === $slug) {
					$schema = array_replace_recursive($schema, $fragment);
				}
			}
		}

		return $schema;
	}//end mergedSchema()

	/**
	 * The municipality example set's seed objects, by schema slug.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function seeds(): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/municipality.json'), true);
		return $doc['x-openregister']['seedData']['objects'];
	}//end seeds()

	/**
	 * The schema carries the file, its version, the page, the anchors and the context.
	 *
	 * @return void
	 */
	public function testTheAnnotationSchemaIsDeclared(): void {
		$schema = $this->mergedSchema('annotation');
		self::assertNotSame([], $schema, 'annotation exists in the merged register');
		self::assertFalse($schema['x-openregister']['hardDelete']);
		self::assertSame(['annotationType', 'fileId', 'fileVersion', 'page', 'visibility', 'author'], $schema['required']);
		self::assertSame(['highlight', 'sticky-note', 'drawing'], $schema['properties']['annotationType']['enum']);
		self::assertSame(['private', 'faction', 'body'], $schema['properties']['visibility']['enum']);
		self::assertSame('private', $schema['properties']['visibility']['default']);
		self::assertSame(1, $schema['properties']['page']['minimum']);
		self::assertSame('digital-document', $schema['properties']['document']['$ref']);
		self::assertSame('agenda-item', $schema['properties']['agendaItem']['$ref']);
		self::assertSame('meeting', $schema['properties']['meeting']['$ref']);
		foreach (['quads', 'point', 'path', 'quote', 'bodyText', 'color', 'versionLabel'] as $property) {
			self::assertArrayHasKey($property, $schema['properties']);
		}

		foreach ($schema['properties'] as $key => $property) {
			self::assertNotSame('', (string)($property['title'] ?? ''), $key . ' has a title');
		}

		$listed = [];
		foreach ((glob(self::SETTINGS . 'register.d/*.json') ?: []) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			$listed = array_merge($listed, ($doc['components']['registers']['decidiq']['schemas'] ?? []));
		}

		self::assertContains('annotation', $listed, 'the decidiq register lists annotation');
	}//end testTheAnnotationSchemaIsDeclared()

	/**
	 * Anchors are bounded and page-relative, so a client cannot store an unbounded payload.
	 *
	 * @return void
	 */
	public function testAnchorsAreBoundedAndPageRelative(): void {
		$properties = $this->mergedSchema('annotation')['properties'];

		self::assertSame(200, $properties['quads']['maxItems']);
		self::assertSame(8, $properties['quads']['items']['minItems']);
		self::assertSame(8, $properties['quads']['items']['maxItems']);
		self::assertSame(5000, $properties['path']['maxItems']);
		self::assertSame(2, $properties['path']['items']['maxItems']);
		foreach ([$properties['quads']['items']['items'], $properties['path']['items']['items'], $properties['point']['properties']['x'], $properties['point']['properties']['y']] as $coordinate) {
			self::assertSame(0, $coordinate['minimum']);
			self::assertSame(1, $coordinate['maximum']);
		}

		self::assertSame(4000, $properties['bodyText']['maxLength']);
	}//end testAnchorsAreBoundedAndPageRelative()

	/**
	 * Through OpenRegister a member reads only their own annotations, and nobody but the owner writes.
	 *
	 * @return void
	 */
	public function testAMemberReadsOnlyTheirOwnAnnotationsThroughOpenRegister(): void {
		$auth = $this->mergedSchema('annotation')['authorization'] ?? [];
		self::assertSame(['read'], array_keys($auth), 'writes are omitted, so the object API denies them to members');
		self::assertNotContains('authenticated', $auth['read']);
		self::assertNotContains('decidesk-members', $auth['read'], 'members never read every annotation');
		self::assertSame(self::READERS, array_values(array_filter($auth['read'], 'is_string')));
		self::assertSame([['group' => 'decidesk-members', 'match' => ['author' => '$userId']]], array_values(array_filter($auth['read'], 'is_array')));
	}//end testAMemberReadsOnlyTheirOwnAnnotationsThroughOpenRegister()

	/**
	 * The municipality set seeds one annotation of each type and every visibility, on seeded context.
	 *
	 * @return void
	 */
	public function testTheMunicipalitySetSeedsFourAnnotations(): void {
		$seeds = $this->seeds();
		$annotations = array_column(($seeds['annotation'] ?? []), null, 'slug');
		self::assertSame(
			['hl-bestemmingsplan-bouwhoogte', 'note-begroting-dekking', 'draw-situatietekening-kruising', 'note-notulen-actiepunt-griffie'],
			array_keys($annotations)
		);

		$types = array_column($annotations, 'annotationType');
		sort($types);
		self::assertSame(['drawing', 'highlight', 'sticky-note', 'sticky-note'], $types);
		self::assertEqualsCanonicalizing(['private', 'faction', 'private', 'body'], array_column($annotations, 'visibility'));

		$items = array_column($seeds['agenda-item'], null, 'slug');
		$meetings = array_column($seeds['meeting'], null, 'slug');
		$documents = array_column($seeds['digital-document'], null, 'slug');
		foreach ($annotations as $slug => $annotation) {
			self::assertSame('annotation', $annotation['@self']['schema']);
			self::assertSame(self::NIL_UUID, $annotation['author'], $slug . ' uses the author placeholder');
			self::assertSame(0, $annotation['fileId'], $slug . ' uses the file placeholder');
			self::assertArrayHasKey($annotation['agendaItem'], $items, $slug . ' names a seeded agenda item');
			if (isset($annotation['meeting']) === true) {
				self::assertArrayHasKey($annotation['meeting'], $meetings, $slug . ' names a seeded meeting');
			}

			if (isset($annotation['document']) === true) {
				self::assertArrayHasKey($annotation['document'], $documents, $slug . ' names a seeded document');
			}
		}

		self::assertSame('maximale bouwhoogte van 12 meter', $annotations['hl-bestemmingsplan-bouwhoogte']['quote']);
		self::assertSame('v2', $annotations['note-notulen-actiepunt-griffie']['versionLabel']);
		self::assertCount(8, $annotations['draw-situatietekening-kruising']['path']);
	}//end testTheMunicipalitySetSeedsFourAnnotations()
}//end class
