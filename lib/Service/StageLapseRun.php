<?php

/**
 * Decidiq StageLapseRun
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
 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-015, REQ-AR-017)
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;

/**
 * One stage being lapsed by the sweep: the stage as it was read, and the
 * sweep's clock.
 *
 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-015, REQ-AR-017)
 */
final class StageLapseRun {

	/**
	 * Create a run.
	 *
	 * @param array<string, mixed> $stage The stage as the sweep read it.
	 * @param DateTimeImmutable    $now   The sweep's clock.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $stage,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()

	/**
	 * The stage's id.
	 *
	 * @return string The id, or ''.
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-015, REQ-AR-017)
	 */
	public function uuid(): string {
		return (string)($this->stage['id'] ?? '');
	}//end uuid()

	/**
	 * The person the stage is assigned to.
	 *
	 * @return string The person, or ''.
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-015, REQ-AR-017)
	 */
	public function actor(): string {
		return (string)($this->stage['assignedPerson'] ?? '');
	}//end actor()

	/**
	 * The clock as the ISO-8601 instant every lapse field is stamped with.
	 *
	 * @return string The instant.
	 *
	 * @spec openspec/specs/approval-routes/spec.md (REQ-AR-015, REQ-AR-017)
	 */
	public function stampedAt(): string {
		return $this->now->format(DateTimeImmutable::ATOM);
	}//end stampedAt()
}//end class
