<?php

/**
 * Decidiq register persistence.
 *
 * The OpenRegister reads and writes a decidiq service needs, kept out of the
 * services themselves so each holds only its rules and not the shape of the
 * storage layer underneath.
 *
 * Extracted from ApprovalRouteService as ApprovalRouteStore and generalised
 * when GovernanceBodyCommandService became its second consumer. The alternative
 * was a second store with the same three methods, which is the "second store
 * that drifts" hazard in miniature.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;

/**
 * Reads and writes decidiq's OpenRegister objects.
 *
 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */
class RegisterObjectStore {
	/**
	 * The register every schema here lives in.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * Constructor.
	 *
	 * TYPED INJECTION, not a container lookup. ADR-083 rule 1: a dependency
	 * fetched by string from the container is declared nowhere a reader or a
	 * gate can see it. `ObjectServiceInterface` is aliased to OpenRegister's
	 * ObjectService in Application::register() — an alias, so it resolves only
	 * when something asks for it, and an instance without OpenRegister still
	 * boots and fails at the route that needed the data.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Write an object.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $object The object or patch.
	 * @param string|null $uuid The uuid when updating.
	 *
	 * @return array<string, mixed> The stored object.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 */
	public function save(string $schema, array $object, ?string $uuid = null): array {
		$stored = $this->objectService->saveObject(
			object: $object,
			register: self::REGISTER,
			schema: $schema,
			uuid: $uuid,
		);

		return $this->normalise(row: $stored);
	}//end save()

	/**
	 * Read objects.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/approval-routes/specs/approval-routes/spec.md
	 */
	public function findAll(string $schema, array $filters): array {
		$results = $this->objectService->findAll(
			[
				'filters' => (['register' => self::REGISTER, 'schema' => $schema] + $filters),
			]
		);
		// No is_array() guard: ObjectServiceInterface::findAll() is typed `: array`,
		// so the check was unreachable — phpstan proved it. It was defensible
		// against the untyped container lookup this class used to do; against the
		// typed injection it is dead code pretending to be caution.
		$rows = [];
		foreach ($results as $row) {
			$rows[] = $this->normalise(row: $row);
		}

		return $rows;
	}//end findAll()

	/**
	 * Collapse OpenRegister's array-or-entity shape.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The array form.
	 */
	private function normalise(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		return (array)$row;
	}//end normalise()

}//end class
