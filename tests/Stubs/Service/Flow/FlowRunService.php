<?php

/**
 * Declaration stub of OpenRegister's Service\Flow\FlowRunService.
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

/**
 * Runs flows. Only the context keys and the signal primitive are mirrored.
 */
class FlowRunService {

	public const SIGNAL_CONTEXT_KEY = 'signal';

	public const RUN_AS_CONTEXT_KEY = 'runAs';

	/**
	 * @param FlowRun $run     The suspended run to wake.
	 * @param array   $payload What the signaller wants the run to know.
	 *
	 * @return FlowRun|null The updated run, or null when it was not suspended.
	 */
	public function signal(FlowRun $run, array $payload = []): ?FlowRun {
		return $run;
	}
}//end class
