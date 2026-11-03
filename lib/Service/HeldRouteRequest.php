<?php

/**
 * Decidiq Held Route Request
 *
 * A route asked for by naming people rather than a template: the subject,
 * the people in order, what to call the route, and optionally a deadline and
 * when to ask each approver's substitute too.
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
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * An immutable request to hold a route from named people.
 *
 * The deadline and the substitute fraction are set with due(); left unset
 * the route has no due dates and asks no substitute.
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md
 */
final class HeldRouteRequest {

	/**
	 * The deadline for the whole route, as an ISO-8601 instant; empty means none.
	 *
	 * @var string
	 */
	private string $deadline = '';

	/**
	 * When to ask each approver's substitute too, as a fraction of the step's
	 * term (0 to 1); null asks no substitute.
	 *
	 * @var float|null
	 */
	private ?float $askSubstituteAfter = null;

	/**
	 * Constructor for a HeldRouteRequest.
	 *
	 * @param string             $subject       The subject's uuid
	 * @param string             $subjectSchema The subject's schema slug
	 * @param array<int, string> $actors        The people to ask, in order
	 * @param string             $name          What to call the route on a surface
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-008)
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $subjectSchema,
		public readonly array $actors,
		public readonly string $name = 'Review',
	) {
	}//end __construct()

	/**
	 * The same request with a deadline and a substitute fraction.
	 *
	 * @param string     $deadline           The deadline as an ISO-8601 instant; empty means no due dates
	 * @param float|null $askSubstituteAfter When to ask each substitute too, or null for none
	 *
	 * @return self The request with its terms.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-016)
	 */
	public function due(string $deadline, ?float $askSubstituteAfter): self {
		$request = clone $this;
		$request->deadline = $deadline;
		$request->askSubstituteAfter = $askSubstituteAfter;
		return $request;
	}//end due()

	/**
	 * The deadline for the whole route.
	 *
	 * @return string The deadline, or an empty string.
	 *
	 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009)
	 */
	public function deadline(): string {
		return $this->deadline;
	}//end deadline()

	/**
	 * When to ask each approver's substitute too.
	 *
	 * @return float|null The fraction, or null.
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-016)
	 */
	public function askSubstituteAfter(): ?float {
		return $this->askSubstituteAfter;
	}//end askSubstituteAfter()
}//end class
