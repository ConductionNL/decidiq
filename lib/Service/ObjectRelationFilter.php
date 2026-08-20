<?php

/**
 * Decidesk Object Relation Filter
 *
 * Scopes an OpenRegister relation-filtered result set down to the objects that
 * genuinely reference a specific related id, and supplies the one relation
 * filter that actually matches the way decidesk writes relations.
 *
 * ── WHY `_relations.<schema-slug>` MATCHES NOTHING ──────────────────────────
 * decidesk writes links as a STRUCTURED relations array on the object payload:
 *
 *     'relations' => [['register' => 'decidesk', 'schema' => 'vote', 'id' => $id]]
 *
 * OpenRegister's SaveObject::scanForRelations() flattens that into the
 * `_relations` JSONB keyed by the PROPERTY PATH it walked — `relations.0.id`,
 * `relations.0.schema`, … — never by the related schema's slug. Its
 * MagicSearchHandler then resolves a `_relations.<field>` filter as
 *
 *     kv.value = <id> AND (kv.key = '<field>' OR kv.key LIKE '<field>.%')
 *
 * so a filter keyed on the schema slug (`_relations.voting-round`,
 * `_relations.board-evaluation`, `_relations.public-consultation`, …) can never
 * match a key of the form `relations.0.id`. It returns ZERO rows on a healthy
 * HTTP 200, with no error and nothing in the log — a tally of 0/0/0, an empty
 * evaluation list, an empty moderation digest, all indistinguishable from
 * "there is genuinely no data".
 *
 * `relations` IS the correct field name for this write shape: `kv.key LIKE
 * 'relations.%'` matches `relations.0.id`, and the value predicate pins it to
 * the exact related UUID. Use {@see self::filterFor()} rather than hand-writing
 * the key, so the reasoning above lives in exactly one place.
 *
 * NOTE the value predicate means the OpenRegister filter DOES scope by id (an
 * earlier version of this docblock claimed it did not). What it does NOT scope
 * by is the related SCHEMA — `relations.0.schema` is itself stored as a
 * relation value — so tally / quorum / dedup logic still re-checks each row
 * through {@see self::matching()}, which is shared by the statutory voting path
 * and the advisory citizen-vote path so the two can never drift apart.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/voting-system/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

/**
 * Exact-id scoping for OpenRegister relation-filtered result sets.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
class ObjectRelationFilter {
	/**
	 * The `_relations` field name that matches decidesk's structured relation writes.
	 *
	 * See the class docblock: relations written as
	 * `['relations' => [['schema' => …, 'id' => $id]]]` land in the `_relations`
	 * JSONB under `relations.<n>.id`, so `relations` is the only field name a
	 * `_relations.<field>` filter can match them by. A schema slug never can.
	 *
	 * @var string
	 */
	public const RELATION_FILTER_FIELD = '_relations.relations';

	/**
	 * Build the OpenRegister findAll() filter that matches objects referencing $targetId.
	 *
	 * @param string $targetId The related object UUID that must be referenced
	 *
	 * @return array<string,string> The filter fragment to merge into a findAll() query
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function filterFor(string $targetId): array {
		return [self::RELATION_FILTER_FIELD => $targetId];
	}//end filterFor()

	/**
	 * Keep only the entities that actually reference $targetId.
	 *
	 * Both the structured (`[{ 'id' => ..., 'schema' => ... }, ...]`) and the
	 * legacy flat (`'<field>' => '<id>'`) relation shapes are honoured.
	 *
	 * @param array<int, mixed> $entities The ObjectEntity result set from findAll()
	 * @param string $schema The related schema slug (e.g. 'voting-round')
	 * @param string $targetId The related object UUID that must be referenced
	 *
	 * @return array<int, mixed> Entities that genuinely reference $targetId
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function matching(array $entities, string $schema, string $targetId): array {
		$matched = [];
		foreach ($entities as $entity) {
			$object = $entity->jsonSerialize();
			$relations = ($object['@self']['relations'] ?? ($object['relations'] ?? []));
			if (is_array($relations) === false) {
				continue;
			}

			if ($this->references(relations: $relations, schema: $schema, targetId: $targetId) === true) {
				$matched[] = $entity;
			}
		}

		return $matched;
	}//end matching()

	/**
	 * Determine whether a serialised relations structure references $targetId.
	 *
	 * @param array<mixed> $relations The object's relations structure
	 * @param string $schema The expected related schema slug
	 * @param string $targetId The related UUID to look for
	 *
	 * @return bool True when $targetId is referenced by the relations
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function references(array $relations, string $schema, string $targetId): bool {
		// Structured list form: [{ 'id' => ..., 'schema' => ... }, ...].
		foreach ($relations as $value) {
			if (is_array($value) === true) {
				$relSchema = ($value['schema'] ?? null);
				$relId = ($value['id'] ?? null);
				if ($relId === $targetId && ($relSchema === null || $relSchema === $schema)) {
					return true;
				}

				continue;
			}

			// Flat scalar form: '<field>' => '<id>' or 'relations.N.id' => '<id>'.
			if (is_string($value) === true && $value === $targetId) {
				return true;
			}
		}

		return false;
	}//end references()
}//end class
