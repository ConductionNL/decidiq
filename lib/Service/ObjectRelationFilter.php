<?php
/**
 * Decidesk Object Relation Filter
 *
 * Scopes an OpenRegister `_relations.<schema>` result set down to the objects
 * that genuinely reference a specific related id.
 *
 * The OpenRegister `_relations.<schema>` filter matches any object carrying a
 * relation of that schema — it does NOT scope by the related id (the filter
 * value is ignored). Tally / quorum / dedup logic needs an exact match, so every
 * caller has to re-check the returned objects. This helper is that check, shared
 * by the statutory voting path and the advisory citizen-vote path so the two can
 * never drift apart.
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
class ObjectRelationFilter
{
    /**
     * Keep only the entities that actually reference $targetId.
     *
     * Both the structured (`[{ 'id' => ..., 'schema' => ... }, ...]`) and the
     * legacy flat (`'<field>' => '<id>'`) relation shapes are honoured.
     *
     * @param array<int, mixed> $entities The ObjectEntity result set from findAll()
     * @param string            $schema   The related schema slug (e.g. 'voting-round')
     * @param string            $targetId The related object UUID that must be referenced
     *
     * @return array<int, mixed> Entities that genuinely reference $targetId
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    public function matching(array $entities, string $schema, string $targetId): array
    {
        $matched = [];
        foreach ($entities as $entity) {
            $object    = $entity->jsonSerialize();
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
     * @param string       $schema    The expected related schema slug
     * @param string       $targetId  The related UUID to look for
     *
     * @return bool True when $targetId is referenced by the relations
     *
     * @spec openspec/specs/voting-system/spec.md
     */
    private function references(array $relations, string $schema, string $targetId): bool
    {
        // Structured list form: [{ 'id' => ..., 'schema' => ... }, ...].
        foreach ($relations as $value) {
            if (is_array($value) === true) {
                $relSchema = ($value['schema'] ?? null);
                $relId     = ($value['id'] ?? null);
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
