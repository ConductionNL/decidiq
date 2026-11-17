<?php

/**
 * The council-information example set is the ORI demo dataset translated
 * through the importer (ori-adoption task 4.1): every object validates, names
 * itself by a fixed uuid, references only objects of the set, and carries no
 * ORI identifier.
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
 * @spec openspec/changes/ori-adoption/tasks.md#4-demo-seed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class CouncilInformationExampleSetTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings/';

	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	/**
	 * The ORI source vocabulary: schema names, property names and values the
	 * mapper translates. None may survive into a decidiq object.
	 */
	private const ORI_IDENTIFIERS = [
		'vergadering', 'agendapunt', 'raadsdocument', 'stemming', 'raadslid', 'fractie',
		'zetels', 'stem', 'onthoudingen', 'omschrijving', 'actief', 'politicalGroupResults',
		'aangenomen', 'verworpen', 'coalitiepartij', 'oppositiepartij', 'raadsvergadering',
		'commissievergadering', 'brief', 'voor', 'tegen',
	];

	/**
	 * The seed objects by schema.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function seeds(): array {
		$doc = json_decode((string)file_get_contents(self::SETTINGS . 'profiles/council-information.json'), true, 512, JSON_THROW_ON_ERROR);
		return $doc['x-openregister']['seedData']['objects'];
	}//end seeds()

	/**
	 * The schema with this slug, merged over the base register and every fragment.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string, mixed>
	 */
	private function mergedSchema(string $slug): array {
		$files  = array_merge([self::SETTINGS . 'decidesk_register.json'], (glob(self::SETTINGS . 'register.d/*.json') ?: []));
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
	 * Every object validates against its merged schema, unknown properties refused.
	 *
	 * @return void
	 */
	public function testEveryObjectValidatesAgainstItsSchema(): void {
		$checked = 0;
		foreach ($this->seeds() as $slug => $objects) {
			$schema     = $this->mergedSchema(slug: $slug);
			$properties = [];
			foreach ($schema['properties'] as $key => $property) {
				if (isset($property['$ref']) === true) {
					unset($property['format']);
				}

				if (isset($property['items']['$ref']) === true) {
					unset($property['items']['$ref'], $property['items']['format']);
				}

				unset($property['$ref'], $property['facetable']);
				$properties[$key] = $property;
			}

			$definition = json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties, 'additionalProperties' => false]));
			foreach ($objects as $object) {
				self::assertSame($slug, $object['@self']['schema']);
				unset($object['@self'], $object['slug'], $object['uuid']);
				$result = (new Validator())->validate(json_decode((string)json_encode($object)), $definition);
				self::assertTrue($result->isValid(), sprintf('The %s seed validates: %s', $slug, json_encode($object)));
				$checked++;
			}
		}

		self::assertSame(196, $checked);
	}//end testEveryObjectValidatesAgainstItsSchema()

	/**
	 * Each object names itself by a fixed, unique uuid and a unique slug, so a
	 * second import finds every object and adds nothing.
	 *
	 * @return void
	 */
	public function testEveryObjectHasAFixedUniqueUuidAndSlug(): void {
		$uuids = [];
		foreach ($this->seeds() as $slug => $objects) {
			$slugs = array_column($objects, 'slug');
			self::assertSame(count($slugs), count(array_unique($slugs)), $slug . ' slugs are unique');
			foreach ($objects as $object) {
				self::assertMatchesRegularExpression(self::UUID, (string)($object['uuid'] ?? ''));
				$uuids[] = $object['uuid'];
			}
		}

		self::assertSame(count($uuids), count(array_unique($uuids)), 'uuids are unique across the set');
	}//end testEveryObjectHasAFixedUniqueUuidAndSlug()

	/**
	 * Every uuid a value holds is an object of the set: the meetings' bodies,
	 * the items' meetings and documents, a decision's subject, a round's
	 * decision and its parties.
	 *
	 * @return void
	 */
	public function testEveryReferenceNamesAnObjectOfTheSet(): void {
		$known = [];
		foreach ($this->seeds() as $objects) {
			foreach ($objects as $object) {
				$known[$object['uuid']] = true;
			}
		}

		$references = 0;
		foreach ($this->seeds() as $slug => $objects) {
			foreach ($objects as $object) {
				unset($object['uuid']);
				array_walk_recursive(
					$object,
					function (mixed $value) use ($known, $slug, &$references): void {
						if (is_string($value) === true && preg_match(self::UUID, $value) === 1) {
							self::assertArrayHasKey($value, $known, $slug . ' references ' . $value);
							$references++;
						}
					}
				);
			}
		}

		self::assertGreaterThan(200, $references);
	}//end testEveryReferenceNamesAnObjectOfTheSet()

	/**
	 * A decided decision carries its outcome and date, as the decision schema's
	 * terminal-completeness rule asks.
	 *
	 * @return void
	 */
	public function testDecidedDecisionsCarryOutcomeAndDate(): void {
		$decisions = $this->seeds()['decision'];
		self::assertCount(6, $decisions);
		foreach ($decisions as $decision) {
			self::assertSame('decided', $decision['lifecycle']);
			self::assertContains($decision['outcome'], ['adopted', 'rejected']);
			self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)($decision['decisionDate'] ?? ''));
		}
	}//end testDecidedDecisionsCarryOutcomeAndDate()

	/**
	 * The content stays Dutch, the identifiers do not: no ORI key or value is
	 * left in a property name, and none as a whole value.
	 *
	 * @return void
	 */
	public function testNoOriIdentifierSurvives(): void {
		$keys   = [];
		$values = [];
		$walk   = function (array $node) use (&$walk, &$keys, &$values): void {
			foreach ($node as $key => $value) {
				if (is_string($key) === true) {
					$keys[] = $key;
				}

				if (is_array($value) === true) {
					$walk($value);
				} else if (is_string($value) === true) {
					$values[] = $value;
				}
			}
		};
		foreach ($this->seeds() as $objects) {
			$walk($objects);
		}

		self::assertSame([], array_values(array_intersect(self::ORI_IDENTIFIERS, $keys)));
		self::assertSame([], array_values(array_intersect(self::ORI_IDENTIFIERS, $values)));
		self::assertContains('Raadsvergadering 2 september 2025', $values, 'the content stays Dutch');
	}//end testNoOriIdentifierSurvives()
}//end class
