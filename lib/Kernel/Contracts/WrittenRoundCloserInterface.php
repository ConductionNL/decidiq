<?php

/**
 * Contract of WrittenRoundCloser
 *
 * The public surface of WrittenRoundCloser that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of WrittenRoundCloser.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WrittenRoundCloserInterface {

	/**
	 * Close the round at its deadline: silence counts as not agreeing.
	 * A closed round, an in-meeting round or one before its deadline is left alone.
	 *
	 * @param array<string, mixed> $round The round.
	 * @param DateTimeImmutable    $now   Now.
	 *
	 * @return bool Whether it was closed now.
	 *
	 * @spec openspec/changes/archive/2026-11-24-voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-004-the-round-closes-as-soon-as-its-outcome-is-known-and-at-the-latest-at-its-deadline
	 */
	public function closeAtDeadline(array $round, DateTimeImmutable $now): bool;
}//end interface
