<?php

/**
 * Decidiq — legacy Task/Delegation → ActionItem migration (RETIRED to a no-op).
 *
 * Action items are now CalDAV VTODOs exposed as a READ-ONLY OpenRegister
 * projection (action-items-vtodo-deck-reconcile). The original repair step wrote
 * app-local `ActionItem` objects via ObjectService::saveObject — which the
 * read-only projection now rejects, and which the projection would not serve
 * anyway. Creating the canonical VTODOs from legacy task/delegation data needs
 * per-user CalDAV context that a repair step (no user session) cannot provide.
 *
 * This step is therefore a no-op; the legacy task/delegation → VTODO migration
 * is a documented per-user follow-up. Kept as a registered repair step so its
 * removal does not break the repair-step registry.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Retired no-op: legacy task/delegation → VTODO migration is a per-user follow-up.
 *
 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
 */
class MigrateActionItemsToDeckLeaf implements IRepairStep {
	/**
	 * Get the name of this repair step.
	 *
	 * @return string The repair step name.
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
	 */
	public function getName(): string {
		return 'Migrate legacy Decidiq Task/Delegation objects to Deck-leaf VTODO action items (deferred to per-user follow-up)';
	}//end getName()

	/**
	 * No-op. See the class docblock: the action-item schema is a read-only VTODO
	 * projection, so this saveObject-based migration cannot run; the legacy
	 * task/delegation → VTODO migration is a documented per-user follow-up.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/action-items-vtodo-deck-reconcile/tasks.md#task-3.1
	 */
	public function run(IOutput $output): void {
		$output->info(
			'Decidiq action-item migration skipped: action items are a read-only VTODO '
			. 'projection; legacy task/delegation → VTODO migration is a per-user follow-up.'
		);
	}//end run()
}//end class
