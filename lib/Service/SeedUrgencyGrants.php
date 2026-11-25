<?php

/**
 * Lets an example set's own urgent decisions past the urgency field guard while the set is imported.
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
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use Psr\Log\LoggerInterface;
use Throwable;


/**
 * Urgency is declared through the urgency procedure, so UrgencyFieldGuardListener
 * refuses a decision save that sets the urgency fields, unless that decision's
 * write is granted (UrgencyWriteGrant). An example set ships decisions that
 * were declared urgent already: imported from the setup wizard (as the
 * administrator), every one of them was refused and the set arrived without
 * its urgent decisions (found live on 25 Nov 2026, "Skipping seed object for
 * 'decision'"). The import grants exactly the decisions the set itself
 * declares urgent, by their fixed id, and revokes the grants afterwards.
 *
 * A set imports its decisions before their stages, so a decision's
 * materialised `awaitingRatification` (urgent and a ratifying stage still
 * open) was computed without its stages and read false for every seeded
 * decision. While still granted, each urgent decision is saved once more
 * after the import, unchanged, so the calculation sees its stages.
 *
 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md
 */
class SeedUrgencyGrants {

	/**
	 * Constructor.
	 *
	 * @param UrgencyWriteGrant      $grant         The grant the field guard reads.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service
	 * @param LoggerInterface        $logger        Logger
	 */
	public function __construct(
		private readonly UrgencyWriteGrant $grant,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the import with the set's own urgent decisions granted.
	 *
	 * @param array<string, mixed> $descriptor The example set.
	 * @param callable(): mixed    $import     The import.
	 *
	 * @return mixed What the import returns.
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md
	 */
	public function during(array $descriptor, callable $import): mixed {
		$ids = self::urgentDecisionIds(descriptor: $descriptor);
		foreach ($ids as $id) {
			$this->grant->grant(decisionId: $id);
		}

		try {
			$result = $import();
			foreach ($ids as $id) {
				$this->resave(decisionId: $id);
			}

			return $result;
		} finally {
			foreach ($ids as $id) {
				$this->grant->revoke(decisionId: $id);
			}
		}
	}//end during()

	/**
	 * Save a decision again, unchanged, so its materialised calculations are
	 * computed with everything the import wrote after it. A failure is logged
	 * and leaves the decision as imported.
	 *
	 * @param string $decisionId The decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md
	 */
	private function resave(string $decisionId): void {
		try {
			// A patch that writes what is there already: the decision is saved
			// again and its calculations run, nothing else changes.
			$this->objectService->patchObject(
				patch: new ObjectPatch(objectId: $decisionId, data: ['isUrgent' => true], target: new ObjectFindTarget(register: 'decidiq', schema: 'decision')),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: a seeded urgent decision could not be saved again after the import',
				['decision' => $decisionId, 'error' => $e->getMessage()]
			);
		}
	}//end resave()

	/**
	 * The fixed ids of the set's decisions that carry any urgency field.
	 *
	 * A seeded urgent decision without a fixed id gets no grant and stays refused.
	 *
	 * @param array<string, mixed> $descriptor The example set.
	 *
	 * @return list<string> The ids.
	 *
	 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md
	 */
	public static function urgentDecisionIds(array $descriptor): array {
		$decisions = ($descriptor['x-openregister']['seedData']['objects']['decision'] ?? []);
		$ids       = [];
		foreach ((array)$decisions as $decision) {
			if (is_array($decision) === false || self::declaresUrgency(decision: $decision) === false) {
				continue;
			}

			$id = (string)($decision['id'] ?? ($decision['uuid'] ?? ''));
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end urgentDecisionIds()

	/**
	 * Whether a seeded decision sets any of the urgency fields.
	 *
	 * @param array<string, mixed> $decision The seeded decision.
	 *
	 * @return bool
	 */
	private static function declaresUrgency(array $decision): bool {
		foreach (UrgencyDeclarationService::URGENCY_FIELDS as $field) {
			$value = ($decision[$field] ?? null);
			if ($value !== null && $value !== false && $value !== '') {
				return true;
			}
		}

		return false;
	}//end declaresUrgency()
}//end class
