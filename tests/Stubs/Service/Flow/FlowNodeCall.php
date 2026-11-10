<?php

// VERBATIM COPY (minus its spec-traceability tags) of openregister lib/Service/Flow/FlowNodeCall.php at ConductionNL/openregister@116ea5f9
// (openregister #9090), for standalone unit runs and static analysis. OpenRegister
// is a sibling app, not a composer dependency; the real class wins whenever
// OpenRegister is loaded. Refresh by copying, never by editing. The spec
// tags are dropped because they name OpenRegister's own openspec, which this
// repo does not hold (gate-46 resolves anchors in this repo only).

/**
 * What a flow step is called with: its input items, its configuration and the run context.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * One call of a node: {@see IFlowNode::execute()} and {@see IFlowNodePreview::preview()} take it.
 *
 * The items are the data channel ({@see FlowItems}); the context is run-level
 * metadata and never carries data between steps.
 */
final class FlowNodeCall {
	/**
	 * Constructor.
	 *
	 * @param array $items   The input items.
	 * @param array $config  The step's authored configuration.
	 * @param array $context Run-level metadata.
	 */
	public function __construct(
		public readonly array $items=[],
		public readonly array $config=[],
		public readonly array $context=[],
	) {
	}//end __construct()
}//end class
