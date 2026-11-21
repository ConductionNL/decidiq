<?php

/**
 * Decidiq ConfidentialityRestrictions
 *
 * Reads the confidentiality-restriction objects that keep a decision out of
 * the public.
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
 * @spec openspec/specs/public-publication/spec.md#requirement-a-decision-under-a-confidentiality-restriction-is-never-published
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\ConfidentialityUnreadableException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Whether a decision is under an active confidentiality restriction.
 *
 * Confidentiality lives in `confidentiality-restriction` objects (scope
 * decision, targetDecision, lifecycle imposed / ratified / dissolved), not
 * on the decision. The read runs in system context, so the answer does not
 * depend on whether the publishing user may read the restriction, and it
 * fails closed: restrictions that cannot be read raise, never answer "no".
 *
 * @spec openspec/specs/public-publication/spec.md#requirement-a-decision-under-a-confidentiality-restriction-is-never-published
 */
class ConfidentialityRestrictions {
	/**
	 * Restriction states that keep a decision out of the public.
	 */
	private const ACTIVE_STATES = ['imposed', 'ratified'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-a-decision-under-a-confidentiality-restriction-is-never-published
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Whether an imposed or ratified restriction targets this decision.
	 *
	 * @param string $decisionId The decision id.
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-a-decision-under-a-confidentiality-restriction-is-never-published
	 *
	 * @throws ConfidentialityUnreadableException When the restrictions cannot be read.
	 *
	 * @return bool True when the decision may not be published.
	 */
	public function isDecisionRestricted(string $decisionId): bool {
		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => 'decidiq',
						'schema' => 'confidentiality-restriction',
						'scope' => 'decision',
						'targetDecision' => $decisionId,
					],
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			throw new ConfidentialityUnreadableException(
				message: 'The decision was not published: its confidentiality could not be checked. Try again in a moment.',
				previous: $e
			);
		}

		// The filter narrows the read; the check below decides, so a filter
		// OpenRegister ignores can only make the read wider, never let a
		// restricted decision through.
		foreach (($rows['results'] ?? $rows) as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === false || (string)($data['targetDecision'] ?? '') !== $decisionId) {
				continue;
			}

			if (in_array(($data['lifecycle'] ?? ''), self::ACTIVE_STATES, true) === true) {
				return true;
			}
		}

		return false;
	}//end isDecisionRestricted()
}//end class
