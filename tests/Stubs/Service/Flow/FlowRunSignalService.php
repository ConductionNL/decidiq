<?php

/**
 * Declaration stub of OpenRegister's Service\Flow\FlowRunSignalService.
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

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Exception\FlowSignalRefused;

/**
 * The guarded signal seam.
 */
class FlowRunSignalService {

	/**
	 * @param string      $runUuid  The run to answer.
	 * @param array       $payload  What the signaller wants the run to know.
	 * @param string|null $actorUid Who is answering.
	 * @param string|null $nodeId   The node the answer addresses.
	 *
	 * @return FlowRun The parked run.
	 *
	 * @throws FlowSignalRefused When refused.
	 */
	public function signalAs(string $runUuid, array $payload, ?string $actorUid, ?string $nodeId = null): FlowRun {
		$run = new FlowRun();
		$run->setUuid($runUuid);

		return $run;
	}

	/**
	 * @param FlowRun     $run      The run to answer.
	 * @param array       $payload  What the signaller wants the run to know.
	 * @param string|null $actorUid Who is answering.
	 * @param string|null $nodeId   The node the answer addresses.
	 *
	 * @return FlowRun The parked run.
	 *
	 * @throws FlowSignalRefused When refused.
	 */
	public function signalRunAs(FlowRun $run, array $payload, ?string $actorUid, ?string $nodeId = null): FlowRun {
		return $run;
	}
}//end class
