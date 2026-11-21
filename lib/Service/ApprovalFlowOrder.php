<?php

/**
 * One order of an approval route, while it is compiled to a flow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * The steps that share one order, where approving them leads, and the orders before it.
 *
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md
 */
final class ApprovalFlowOrder {
	/**
	 * Constructor.
	 *
	 * @param int                              $number  The order.
	 * @param list<array<string, mixed>>       $steps   Its steps; more than one run side by side.
	 * @param string                           $next    The node approving leads to.
	 * @param list<int>                        $earlier The orders before it, a return can go to.
	 */
	public function __construct(
		public readonly int $number,
		public readonly array $steps,
		public readonly string $next,
		public readonly array $earlier,
	) {
	}//end __construct()
}//end class
