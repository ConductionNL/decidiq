<?php

/**
 * Reads authority delegations from the schema they live on.
 *
 * The delegation register moved from `bevoegdheidstoedeling` to
 * `authority-delegation` (the-last-two-dutch-names). The repair step copies
 * every row and records where it came from in `migratedFromObject`, so a
 * reference written before the move (an approval step's mandate, a decision's
 * basis) still names the retired row. A read here answers from the copy: the
 * retired row is never consulted, because it no longer follows a withdrawal or
 * a lapse recorded on the copy.
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
 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use RuntimeException;

/**
 * Finds delegations on `authority-delegation`, following a pre-rename id to its copy.
 *
 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
 */
final class AuthorityDelegationRows {

	/**
	 * The schema the delegations live on.
	 */
	public const SCHEMA = OndermandaatGuardService::SCHEMA;

	/**
	 * Where a copied row records the retired row it came from.
	 */
	public const ORIGIN_KEY = 'migratedFromObject';

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads the register.
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
	) {
	}//end __construct()

	/**
	 * The delegations matching the filters.
	 *
	 * @param array<string, mixed> $filters The filters, for example a status.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
	 */
	public function findAll(array $filters): array {
		return $this->store->findAll(schema: self::SCHEMA, filters: $filters);
	}//end findAll()

	/**
	 * One delegation by id: the row itself, or the copy of the retired row with that id.
	 *
	 * @param string $uuid The id as the reference holds it.
	 *
	 * @return array<string, mixed>|null The row, or null when neither exists.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/delegated-authority-check/specs/delegatie-mandaatregister/spec.md
	 */
	public function find(string $uuid): ?array {
		$uuid = trim($uuid);
		if ($uuid === '') {
			return null;
		}

		$row = $this->store->find(schema: self::SCHEMA, uuid: $uuid);
		if ($row !== null) {
			return $row;
		}

		$copies = $this->store->findAll(schema: self::SCHEMA, filters: [self::ORIGIN_KEY => $uuid]);

		return ($copies[0] ?? null);
	}//end find()
}//end class
