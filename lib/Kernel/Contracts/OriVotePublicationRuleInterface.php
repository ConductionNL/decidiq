<?php

/**
 * Contract of OriVotePublicationRule
 *
 * The public surface of OriVotePublicationRule that other components call, so they depend
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
 * Contract of OriVotePublicationRule.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OriVotePublicationRuleInterface {

	/**
	 * One public vote by id, or null when the rule withholds it.
	 *
	 * @param string $voteId The vote id
	 *
	 * @return array<string, mixed>|null The ORI vote fields
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
	 */
	public function vote(string $voteId): ?array;

	/**
	 * One public vote event by id, or null when the rule withholds it.
	 *
	 * @param string $roundId The voting round id
	 *
	 * @return array<string, mixed>|null The ORI vote event fields
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
	 */
	public function voteEvent(string $roundId): ?array;

	/**
	 * The public vote events.
	 *
	 * @return list<array<string, mixed>> The ORI vote event fields
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
	 */
	public function voteEvents(): array;

	/**
	 * The public votes, optionally of one person only.
	 *
	 * @param string|null $voter A person id, or null for every voter
	 *
	 * @return list<array<string, mixed>> The ORI vote fields
	 *
	 * @spec openspec/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
	 */
	public function votes(?string $voter): array;
}//end interface
