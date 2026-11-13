<?php

/**
 * Contract of ProxyDelegationService
 *
 * The public surface of ProxyDelegationService that other components call, so they depend
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
 * Contract of ProxyDelegationService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProxyDelegationServiceInterface {

	/**
	 * Grant proxy: delegate voting right from one participant to another for a VotingRound.
	 *
	 * Validates that the receiver has a voting role (not observer/guest) and
	 * holds fewer proxies in the round's meeting than the configured cap
	 * (`decidiq`/`max_proxies_per_holder`, default 2); a round without a
	 * meeting counts its own proxies only. Sends notification to the delegate.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $fromParticipantId The delegating participant UUID
	 * @param string $toParticipantId The receiving participant UUID
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the receiver cannot receive proxies or the cap is reached
	 * @throws \RuntimeException When the voting round does not exist
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function grantProxy(string $votingRoundId, string $fromParticipantId, string $toParticipantId): void;

	/**
	 * Revoke proxy: remove proxy delegation before the round opens.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $fromParticipantId The participant revoking their proxy
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the round is already open
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
	 */
	public function revokeProxy(string $votingRoundId, string $fromParticipantId): void;
}//end interface
