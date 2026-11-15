<?php

/**
 * Contract of MotionStages
 *
 * The public surface of MotionStages that other components call, so they depend
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

/**
 * Contract of MotionStages.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MotionStagesInterface {

	/**
	 * The motion's stage and the steps the caller may take, or null when
	 * there is no such motion.
	 *
	 * @param string $motionId The motion (Decision) UUID
	 * @param string $uid      The caller's Nextcloud UID
	 *
	 * @return array{lifecycle: string, outcome: string|null, actions: array<int, array{to: string, outcome?: string}>}|null
	 *
	 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
	 */
	public function forCaller(string $motionId, string $uid): ?array;

	/**
	 * Whether the caller may withdraw the motion: its submitter, or the
	 * chair or secretary, while withdrawal is still a step from its stage.
	 *
	 * @param string $motionId The motion (Decision) UUID
	 * @param string $uid      The caller's Nextcloud UID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page
	 */
	public function mayWithdraw(string $motionId, string $uid): bool;
}//end interface
