<?php

/**
 * Decidiq Voting Round Preflight
 *
 * The fail-closed checks and preparations that precede persisting a voting
 * round: resolving and validating the configurable voting rules, the
 * revote-once guard, preset-participant validation, and the guarded subject
 * lifecycle transition.
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
 * @spec openspec/specs/voting-system/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fail-closed preflight for opening a voting round.
 *
 * Process-configuration: the resolution order per rule is caller value
 * (non-null) -> body template default -> built-in default. Unknown values are
 * rejected, never silently defaulted.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/process-configuration/spec.md
 */
class VotingRoundPreflight {
	/**
	 * Accepted subject types for a voting round.
	 *
	 * ADR-005 turned these from schema slugs into `decisionType` discriminator
	 * values on the unified `decision` schema. The refusal below must therefore
	 * stay ahead of any register access: a value that reaches a schema lookup
	 * on a retired slug raises DoesNotExistException, which the 400 mapper does
	 * not catch, so the endpoint answers 500 instead of 400.
	 *
	 * @var string[]
	 */
	private const SUBJECT_TYPES = ['motion', 'amendment'];

	/**
	 * Constructor for VotingRoundPreflight.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param MotionService $motionService The motion service for lifecycle transitions
	 * @param ParticipantResolver $participantResolver Participant resolver for preset validation
	 * @param ProcessTemplateService $templateService Resolves a body's template voting-rule defaults
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly MotionService $motionService,
		private readonly ParticipantResolver $participantResolver,
		private readonly ProcessTemplateService $templateService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the effective voting rules and validate them (fail closed).
	 *
	 * @param string|null $governanceBodyId Body opening the round; its process template supplies defaults
	 * @param string|null $voteThreshold Caller-supplied majority rule, or null
	 * @param string|null $abstentionHandling Caller-supplied abstention mode, or null
	 * @param string|null $tieBreakRule Caller-supplied tie-break rule, or null
	 * @param string $subjectType What is being voted: 'motion' or 'amendment'
	 *
	 * @return array<string,string> The effective voteThreshold, abstentionHandling and tieBreakRule
	 *
	 * @throws InvalidArgumentException When a rule or subjectType value is not in its enum
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/process-configuration/spec.md
	 */
	public function resolveRules(
		?string $governanceBodyId,
		?string $voteThreshold,
		?string $abstentionHandling,
		?string $tieBreakRule,
		string $subjectType,
	): array {
		$template = $this->templateService->resolveVotingRuleForBody(governanceBodyId: $governanceBodyId);

		$rules = [
			'voteThreshold' => ($voteThreshold ?? ($template['voteThreshold'] ?? 'simple-majority')),
			'abstentionHandling' => ($abstentionHandling ?? ($template['abstentionHandling'] ?? 'exclude')),
			'tieBreakRule' => ($tieBreakRule ?? ($template['tieBreakRule'] ?? 'rejected')),
		];

		$this->assertInEnum(name: 'voteThreshold', value: $rules['voteThreshold'], allowed: VotingService::VOTE_THRESHOLDS);
		$this->assertInEnum(name: 'abstentionHandling', value: $rules['abstentionHandling'], allowed: VotingService::ABSTENTION_MODES);
		$this->assertInEnum(name: 'tieBreakRule', value: $rules['tieBreakRule'], allowed: VotingService::TIE_BREAK_RULES);
		$this->assertInEnum(name: 'subjectType', value: $subjectType, allowed: self::SUBJECT_TYPES);

		return $rules;
	}//end resolveRules()

	/**
	 * Reject a value that is not in its enum (fail closed).
	 *
	 * @param string $name The rule name, used in the message
	 * @param mixed $value The effective value
	 * @param string[] $allowed The accepted values
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the value is not accepted
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function assertInEnum(string $name, mixed $value, array $allowed): void {
		if (in_array($value, $allowed, true) === true) {
			return;
		}

		throw new InvalidArgumentException("Unknown {$name} '{$value}'");
	}//end assertInEnum()

	/**
	 * Guard the single permitted revote of a tied round (fail closed).
	 *
	 * A revote is only allowed when the referenced round exists, was tallied
	 * 'tied', carries tieBreakRule 'revote', and has not already been revoted
	 * (no other round references it via revoteOfRound). Every mismatch throws.
	 *
	 * @param string $revoteOfRoundId The tied round UUID being revoted
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the revote guard fails
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function assertRevoteAllowed(string $revoteOfRoundId): void {
		$originalEntity = $this->objectService->find(id: $revoteOfRoundId, register: 'decidesk', schema: 'voting-round');
		if ($originalEntity === null) {
			throw new RuntimeException("Revote refused: round {$revoteOfRoundId} not found");
		}

		$original = $originalEntity->jsonSerialize();
		if (($original['result'] ?? null) !== 'tied') {
			throw new RuntimeException('Revote refused: the referenced round is not tied');
		}

		if (($original['tieBreakRule'] ?? 'rejected') !== 'revote') {
			throw new RuntimeException("Revote refused: the referenced round's tie-break rule is not 'revote'");
		}

		// The "once" guarantee: no other round may already reference this round.
		$this->objectService->setRegister('decidesk');
		$this->objectService->setSchema('voting-round');
		$existingRevotes = $this->objectService->findAll(['filters' => ['revoteOfRound' => $revoteOfRoundId]]);
		foreach ($existingRevotes as $revoteEntity) {
			$revote = $revoteEntity->jsonSerialize();
			if (($revote['revoteOfRound'] ?? null) === $revoteOfRoundId) {
				throw new RuntimeException('Revote refused: this round has already been revoted once');
			}
		}

	}//end assertRevoteAllowed()

	/**
	 * Split preset participant UUIDs into the eligible ones (active members of
	 * the meeting) and the excluded ones.
	 *
	 * @param string $meetingId The meeting UUID
	 * @param array<string> $presetIds The requested preset participant UUIDs
	 *
	 * @return array<string, array<int, string>> Keys: `eligible` and `excluded`.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function splitPresetParticipants(string $meetingId, array $presetIds): array {
		if (count($presetIds) === 0) {
			return ['eligible' => [], 'excluded' => []];
		}

		$participantArrays = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);
		$activeMembers = array_column($participantArrays, 'id', 'id');

		$excluded = [];
		foreach ($presetIds as $uuid) {
			if (isset($activeMembers[$uuid]) === false) {
				$excluded[] = $uuid;
			}
		}

		return [
			'eligible' => array_diff($presetIds, $excluded),
			'excluded' => $excluded,
		];

	}//end splitPresetParticipants()

	/**
	 * Assemble the VotingRound payload that gets persisted.
	 *
	 * @param string $motionId The motion UUID (amendment UUID for amendment rounds)
	 * @param string $subjectType 'motion' or 'amendment' — the subject relation schema
	 * @param string $votingMethod The voting method
	 * @param bool $isSecret Whether the ballot is secret
	 * @param string|null $closedAt Optional pre-defined close time
	 * @param bool $quorumWith Whether quorum was met when the round opened
	 * @param array<string> $rules The effective voting rules
	 * @param string|null $revoteOfRoundId UUID of the tied round this round revotes, or null
	 * @param array<string> $participantIds Eligible preset participant UUIDs
	 *
	 * @return array<string,mixed> The voting-round payload
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function buildRoundPayload(
		string $motionId,
		string $subjectType,
		string $votingMethod,
		bool $isSecret,
		?string $closedAt,
		bool $quorumWith,
		array $rules,
		?string $revoteOfRoundId,
		array $participantIds,
	): array {
		$relations = [['register' => 'decidesk', 'schema' => $subjectType, 'id' => $motionId]];
		foreach ($participantIds as $uuid) {
			$relations[] = ['register' => 'decidesk', 'schema' => 'participant', 'id' => $uuid];
		}

		$round = [
			'votingMethod' => $votingMethod,
			'isSecret' => $isSecret,
			'openedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'closedAt' => $closedAt,
			'quorumWith' => $quorumWith,
			'result' => null,
			'votesFor' => 0,
			'votesAgainst' => 0,
			'votesAbstain' => 0,
			'voteThreshold' => $rules['voteThreshold'],
			'abstentionHandling' => $rules['abstentionHandling'],
			'tieBreakRule' => $rules['tieBreakRule'],
			'relations' => $relations,
		];

		if ($revoteOfRoundId !== null) {
			$round['revoteOfRound'] = $revoteOfRoundId;
		}

		return $round;
	}//end buildRoundPayload()

	/**
	 * Transition the subject lifecycle to 'voting' via the guarded state machine.
	 *
	 * @param string $subjectId The motion or amendment UUID
	 * @param string $subjectType 'motion' or 'amendment'
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the transition is not legal
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function transitionSubjectToVoting(string $subjectId, string $subjectType): void {
		try {
			$this->motionService->transitionLifecycle(
				objectId: $subjectId,
				objectType: $subjectType,
				newState: 'voting',
				actorId: 'system',
			);
		} catch (InvalidArgumentException $e) {
			throw new RuntimeException('Cannot open voting round: ' . $e->getMessage(), 0, $e);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: failed to transition motion lifecycle', ['error' => $e->getMessage()]);
		}//end try

	}//end transitionSubjectToVoting()
}//end class
