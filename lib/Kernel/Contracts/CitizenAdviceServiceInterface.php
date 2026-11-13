<?php

/**
 * Contract of CitizenAdviceService
 *
 * The public surface of CitizenAdviceService that other components call, so they depend
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

use OCA\Decidiq\Exception\NotFoundException;
use OCA\Decidiq\Exception\ParticipationValidationException;

/**
 * Contract of CitizenAdviceService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CitizenAdviceServiceInterface {

	/**
	 * Close the advisory vote on a motion and store its counts.
	 *
	 * @param string $motionId The motion's uuid.
	 *
	 * @return array<string, mixed> The motion after the change.
	 *
	 * @throws NotFoundException When no motion has this id.
	 * @throws ParticipationValidationException When the advisory vote is not open.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-003-the-advisory-result-shows-apart-from-the-councils-vote
	 */
	public function close(string $motionId): array;

	/**
	 * Open the advisory vote on a motion.
	 *
	 * Refused unless the motion is published, allows citizen voting, uses the
	 * simple method, and has not been opened before.
	 *
	 * @param string $motionId The motion's uuid.
	 *
	 * @return array<string, mixed> The motion after the change.
	 *
	 * @throws NotFoundException When no motion has this id.
	 * @throws ParticipationValidationException When the motion cannot be opened, with the reason.
	 *
	 * @spec openspec/changes/participation-citizen-advisory-vote-on-motions/specs/citizen-participation/spec.md#requirement-req-cav-001-the-griffie-opens-and-closes-an-advisory-vote-on-a-motion
	 */
	public function open(string $motionId): array;
}//end interface
