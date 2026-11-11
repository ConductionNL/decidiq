<?php

/**
 * Declaration stub of OpenRegister's Service\Flow\RunSignal.
 *
 * Mirrors the value object FlowRunSignalService::answer() and answerByUuid()
 * take. OpenRegister is a sibling app, not a composer dependency; the real
 * class wins whenever it is loaded.
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

/**
 * What a signal says, who says it, and the node it addresses.
 */
final class RunSignal {

	/**
	 * @param array       $payload  What the signaller wants the run to know.
	 * @param string|null $actorUid Who is answering; null means anonymous.
	 * @param string|null $nodeId   The node the answer addresses.
	 */
	public function __construct(
		public readonly array $payload,
		public readonly ?string $actorUid,
		public readonly ?string $nodeId = null,
	) {
	}
}//end class
