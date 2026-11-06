<?php

/**
 * Decidiq Voting Round Request
 *
 * What a chair asks for when opening a voting round: the subject, the
 * meeting, the method and secrecy, and the optional deadline, preset
 * participants, revote reference and decision rules.
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
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * An immutable request to open a voting round.
 *
 * The optional parts are set with opening(); left unset they mean no
 * deadline, no presets, a fresh round and the default rules.
 *
 * @spec openspec/specs/voting-system/spec.md
 */
final class VotingRoundRequest {

	/**
	 * Optional pre-defined close time.
	 *
	 * @var string|null
	 */
	private ?string $closedAt = null;

	/**
	 * Participant UUIDs of a voting group preset.
	 *
	 * @var array<string>
	 */
	private array $presetParticipantIds = [];

	/**
	 * UUID of the tied round this round is the single permitted revote of.
	 *
	 * @var string|null
	 */
	private ?string $revoteOfRoundId = null;

	/**
	 * The configurable decision rules.
	 *
	 * @var VotingRoundRules
	 */
	private VotingRoundRules $rules;

	/**
	 * Constructor for a VotingRoundRequest.
	 *
	 * @param string $motionId     The motion UUID (the amendment UUID when the subject type is 'amendment')
	 * @param string $meetingId    The meeting UUID
	 * @param string $votingMethod The voting method (for-against-abstain, show-of-hands, etc.)
	 * @param bool   $isSecret     Whether the ballot is secret
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		public readonly string $motionId,
		public readonly string $meetingId,
		public readonly string $votingMethod,
		public readonly bool $isSecret,
	) {
		$this->rules = new VotingRoundRules();
	}//end __construct()

	/**
	 * The same request with its optional parts.
	 *
	 * @param string|null           $closedAt             Optional pre-defined close time
	 * @param array<string>         $presetParticipantIds Participant UUIDs of a voting group preset
	 * @param string|null           $revoteOfRoundId      UUID of the tied round this round revotes, or null
	 * @param VotingRoundRules|null $rules                The decision rules; null means all defaults
	 *
	 * @return self The completed request.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/process-configuration/spec.md
	 */
	public function opening(?string $closedAt, array $presetParticipantIds, ?string $revoteOfRoundId, ?VotingRoundRules $rules): self {
		$request = clone $this;
		$request->closedAt = $closedAt;
		$request->presetParticipantIds = $presetParticipantIds;
		$request->revoteOfRoundId = $revoteOfRoundId;
		$request->rules = ($rules ?? new VotingRoundRules());
		return $request;
	}//end opening()

	/**
	 * The pre-defined close time.
	 *
	 * @return string|null The close time, or null.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function closedAt(): ?string {
		return $this->closedAt;
	}//end closedAt()

	/**
	 * The participant UUIDs of the voting group preset.
	 *
	 * @return array<string> The preset UUIDs.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function presetParticipantIds(): array {
		return $this->presetParticipantIds;
	}//end presetParticipantIds()

	/**
	 * The tied round this round revotes.
	 *
	 * @return string|null The round UUID, or null for a fresh round.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function revoteOfRoundId(): ?string {
		return $this->revoteOfRoundId;
	}//end revoteOfRoundId()

	/**
	 * Whether this is a fresh round rather than a revote.
	 *
	 * @return bool True when no round is revoted.
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	public function isFreshRound(): bool {
		return $this->revoteOfRoundId === null;
	}//end isFreshRound()

	/**
	 * The decision rules.
	 *
	 * @return VotingRoundRules The rules.
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 */
	public function rules(): VotingRoundRules {
		return $this->rules;
	}//end rules()
}//end class
