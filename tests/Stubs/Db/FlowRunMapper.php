<?php

/**
 * Declaration stub of OpenRegister's Db\FlowRunMapper.
 *
 * Mirrors ONLY the members decidiq calls, with the real signatures taken from
 * ConductionNL/openregister@ecaba04a (development). OpenRegister is a sibling
 * app, not a composer dependency; the real class wins whenever it is loaded.
 * Behaviour lives in OpenRegister and is tested there; tests here double it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Finds flow runs.
 */
class FlowRunMapper {

	/**
	 * @param string $uuid The run uuid.
	 *
	 * @return FlowRun The run.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such run exists.
	 */
	public function findByUuid(string $uuid): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);

		return $run;
	}

	/**
	 * @param string  $subjectUuid The subject object's uuid.
	 * @param integer $limit       Maximum runs to return.
	 *
	 * @return FlowRun[] The suspended runs for that subject, oldest first.
	 */
	public function findSuspendedBySubject(string $subjectUuid, int $limit = 25): array {
		return [];
	}
}//end class
