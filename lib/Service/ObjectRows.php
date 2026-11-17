<?php

/**
 * Decidiq ObjectRows
 *
 * Turns an OpenRegister findAll answer into plain rows: each object's data
 * with its uuid under `id`.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * Maps a findAll answer to rows with their `id`.
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
 */
final class ObjectRows {

	/**
	 * The objects of a findAll answer (a paged `results` list or a bare list)
	 * as arrays with their uuid under `id`; anything that is not an object
	 * entity is left out.
	 *
	 * @param array<mixed> $found The findAll answer.
	 *
	 * @return list<array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results
	 */
	public static function of(array $found): array {
		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$rows[] = ['id' => (string)$row->getUuid()] + (array)$row->getObject();
			}
		}

		return $rows;
	}//end of()
}//end class
