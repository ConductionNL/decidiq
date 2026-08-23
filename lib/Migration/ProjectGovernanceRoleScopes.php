<?php

/**
 * Decidiq repair step — backfill governance RBAC scopes
 *
 * One-shot idempotent backfill that projects every existing GovernanceBody's
 * chair/signatory roster into its OpenRegister RBAC scopes via
 * GovernanceRoleScopeProjector (consume-or-rbac-authorization, REQ-RBAC-001).
 * Re-running makes no further change. Fail-soft: a projection error is logged
 * and the migration continues (the scopes fail closed, so an unpopulated scope
 * denies rather than over-grants).
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
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use OCA\Decidiq\Service\GovernanceRoleScopeProjector;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Backfills per-body OR RBAC scopes for existing bodies. Idempotent.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
 */
class ProjectGovernanceRoleScopes implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param GovernanceRoleScopeProjector $projector Scope projector
	 * @param LoggerInterface $logger Diagnostic logger
	 */
	public function __construct(
		private readonly GovernanceRoleScopeProjector $projector,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 *
	 * @spec exclude Trivial repair-step label accessor.
	 */
	public function getName(): string {
		return 'Project Decidiq governance-body roles into OpenRegister RBAC scopes';
	}//end getName()

	/**
	 * Run the backfill for every GovernanceBody.
	 *
	 * @param IOutput $output Progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	public function run(IOutput $output): void {
		try {
			$count = $this->projector->reconcileAll();
			$output->info(sprintf('Reconciled governance RBAC scopes for %d body/bodies.', $count));
		} catch (\Throwable $e) {
			// Fail soft: OpenRegister may not yet be initialised at repair time.
			$this->logger->warning(
				'Decidiq: governance role scope backfill skipped',
				['exception' => $e->getMessage()]
			);
			$output->warning('Governance RBAC scope backfill skipped: ' . $e->getMessage());
		}//end try
	}//end run()
}//end class
