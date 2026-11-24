<?php

/**
 * Admissibility Trail
 *
 * Reads the admissibility verdict of a subject off its approval actions, so
 * the consuming case app can read it through the clearance answer it already
 * asks (REQ-DWP-004). The action trail holds the verdict for every subject a
 * route travels, a case as well as a decision: the verdict is the action's
 * verb, its ground the comment and its actor who gave it. The newest verdict
 * wins, so a verdict given again after a return replaces the earlier one.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * The subject's admissibility verdict, as the case app reads it.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
 */
class AdmissibilityTrail {

	/**
	 * Constructor.
	 *
	 * @param RegisterObjectStore $store Reads the approval actions.
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function __construct(
		private readonly RegisterObjectStore $store,
	) {
	}//end __construct()

	/**
	 * The newest admissibility verdict on a subject, or null when none was given.
	 *
	 * @param string $subject The subject's uuid.
	 *
	 * @return array{ontvankelijkheid: string, ground: string, decidedBy: string, decidedAt: string}|null
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-004)
	 */
	public function verdictOn(string $subject): ?array {
		$newest = null;
		foreach ($this->store->findAll(schema: 'approval-action', filters: ['subject' => $subject]) as $action) {
			if (in_array((string)($action['action'] ?? ''), AdmissibilityVerdictService::VERDICTS, true) === false) {
				continue;
			}

			if ($newest === null || strcmp((string)($action['recordedAt'] ?? ''), (string)($newest['recordedAt'] ?? '')) >= 0) {
				$newest = $action;
			}
		}

		if ($newest === null) {
			return null;
		}

		return [
			'ontvankelijkheid' => (string)$newest['action'],
			'ground'           => (string)($newest['comment'] ?? ''),
			'decidedBy'        => (string)($newest['actor'] ?? ''),
			'decidedAt'        => (string)($newest['recordedAt'] ?? ''),
		];
	}//end verdictOn()
}//end class
