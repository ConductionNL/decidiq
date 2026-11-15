<?php

/**
 * Contract of OriPublicationService
 *
 * The public surface of OriPublicationService that other components call, so they depend
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
 * Contract of OriPublicationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OriPublicationServiceInterface {

	/**
	 * Get the current publication status for a VotingRound.
	 *
	 * Returns 'not_configured' if no ORI endpoint is set, 'published' if the
	 * round has an oriPublishedAt timestamp (set after a successful ORI POST),
	 * or 'pending' otherwise.
	 *
	 * @param string $votingRoundId UUID of the VotingRound
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-3.1
	 *
	 * @return string 'not_configured' | 'published' | 'pending'
	 */
	public function getPublicationStatus(string $votingRoundId): string;

	/**
	 * Publish a VotingRound's results to the ORI API.
	 *
	 * Reads the ORI endpoint from IAppConfig. If not configured or the URL fails
	 * validation, returns silently. Builds a JSON-LD payload following ORI 1.0
	 * format and POSTs it via Nextcloud's IClientService. On success, stamps
	 * oriPublishedAt on the VotingRound object. On failure, logs a warning.
	 *
	 * @param string $votingRoundId UUID of the VotingRound to publish
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-3.1
	 *
	 * @return void
	 */
	public function publish(string $votingRoundId): void;
}//end interface
