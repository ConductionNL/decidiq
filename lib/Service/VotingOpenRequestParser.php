<?php

/**
 * Decidiq Voting Open-Request Parser
 *
 * Turns the raw POST body of `POST /api/voting-rounds` into the validated
 * argument set VotingService::openVotingRound() expects, or into a single
 * client-error message. Validating the configurable voting rules up front
 * keeps a bad value a clean 400 and never a 500.
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

/**
 * Request parsing + validation for opening a voting round.
 *
 * Process-configuration: an OMITTED rule param is passed on as null so the
 * opening body's process-template default applies; an explicit param wins.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @spec openspec/specs/process-configuration/spec.md
 */
class VotingOpenRequestParser {
	/**
	 * The configurable voting rules, mapped to their accepted values.
	 *
	 * Validated in declaration order so the first offending rule is the one
	 * reported.
	 *
	 * @var array<string, string[]>
	 */
	private const RULE_ENUMS = [
		'voteThreshold' => VotingService::VOTE_THRESHOLDS,
		'abstentionHandling' => VotingService::ABSTENTION_MODES,
		'tieBreakRule' => VotingService::TIE_BREAK_RULES,
	];

	/**
	 * The accepted subject types for a voting round.
	 *
	 * @var string[]
	 */
	private const SUBJECT_TYPES = ['motion', 'amendment'];

	/**
	 * Parse and validate an open-voting-round request body.
	 *
	 * @param array<string,mixed> $params The raw request parameters
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 * @spec openspec/specs/process-configuration/spec.md
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 *
	 * @return array<string,mixed> Keys: `error` (message or null) and `payload` (the arguments).
	 */
	public function parse(array $params): array {
		$error = $this->requestError(params: $params);
		if ($error !== null) {
			return ['error' => $error, 'payload' => []];
		}

		return ['error' => null, 'payload' => $this->payload(params: $params)];

	}//end parse()

	/**
	 * Why the request cannot open a round, in the order it is checked: the
	 * ids, each voting rule, the subject type; null when it can.
	 *
	 * @param array<string,mixed> $params The raw request parameters
	 *
	 * @return string|null The message, or null.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function requestError(array $params): ?string {
		if (($params['motionId'] ?? '') === '' || ($params['meetingId'] ?? '') === '') {
			return 'motionId and meetingId are required';
		}

		$ruleError = $this->ruleError(params: $params);
		if ($ruleError !== null) {
			return $ruleError;
		}

		// Subject type (motion-amendment spec): validate up front so a bad
		// value is a clean 400, never a 500.
		if (in_array($this->subjectType(params: $params), self::SUBJECT_TYPES, true) === false) {
			return 'subjectType must be one of: ' . implode(', ', self::SUBJECT_TYPES);
		}

		return null;
	}//end requestError()

	/**
	 * The refusal of the first voting rule set to a value it does not accept.
	 *
	 * @param array<string,mixed> $params The raw request parameters
	 *
	 * @return string|null The message, or null when every rule is acceptable.
	 *
	 * @spec openspec/specs/process-configuration/spec.md
	 */
	private function ruleError(array $params): ?string {
		foreach (self::RULE_ENUMS as $rule => $allowed) {
			$value = $this->looseString(params: $params, key: $rule);
			if ($value !== null && in_array($value, $allowed, true) === false) {
				return $rule . ' must be one of: ' . implode(', ', $allowed);
			}
		}

		return null;
	}//end ruleError()

	/**
	 * The subject type the request names; a motion when it names none.
	 *
	 * @param array<string,mixed> $params The raw request parameters
	 *
	 * @return string The subject type.
	 */
	private function subjectType(array $params): string {
		return (string)($params['subjectType'] ?? 'motion');
	}//end subjectType()

	/**
	 * The arguments of a valid open-round request.
	 *
	 * @param array<string,mixed> $params The raw request parameters
	 *
	 * @return array<string,mixed> The payload.
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
	 */
	private function payload(array $params): array {
		return [
			'motionId' => $params['motionId'],
			'meetingId' => $params['meetingId'],
			'votingMethod' => ($params['votingMethod'] ?? 'for-against-abstain'),
			'isSecret' => (bool)($params['isSecret'] ?? false),
			'closedAt' => $this->presentValue(params: $params, key: 'closedAt'),
			'presetIds' => $this->listValue(params: $params, key: 'presetParticipantIds'),
			'voteThreshold' => $this->looseString(params: $params, key: 'voteThreshold'),
			'abstentionHandling' => $this->looseString(params: $params, key: 'abstentionHandling'),
			'tieBreakRule' => $this->looseString(params: $params, key: 'tieBreakRule'),
			'revoteOfRoundId' => $this->strictString(params: $params, key: 'revoteOfRound'),
			'subjectType' => $this->subjectType(params: $params),
			'governanceBodyId' => $this->strictString(params: $params, key: 'governanceBody'),
			'options' => $this->listValue(params: $params, key: 'options'),
		];
	}//end payload()

	/**
	 * A present, non-empty parameter value, unconverted.
	 *
	 * @param array<string,mixed> $params The raw request parameters
	 * @param string $key The parameter name
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return mixed The value, or null when absent or an empty string
	 */
	private function presentValue(array $params, string $key): mixed {
		if (isset($params[$key]) === true && $params[$key] !== '') {
			return $params[$key];
		}

		return null;
	}//end presentValue()

	/**
	 * A present, non-empty parameter value cast to a string.
	 *
	 * @param array<string,mixed> $params The raw request parameters
	 * @param string $key The parameter name
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return string|null The value as a string, or null when absent or empty
	 */
	private function looseString(array $params, string $key): ?string {
		$value = $this->presentValue(params: $params, key: $key);
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end looseString()

	/**
	 * A parameter that must already be a non-empty string.
	 *
	 * @param array<string,mixed> $params The raw request parameters
	 * @param string $key The parameter name
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return string|null The string value, or null when absent or not a string
	 */
	private function strictString(array $params, string $key): ?string {
		$value = ($params[$key] ?? null);
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end strictString()

	/**
	 * A parameter that must be an array, defaulting to an empty list.
	 *
	 * @param array<string,mixed> $params The raw request parameters
	 * @param string $key The parameter name
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 *
	 * @return array<int,mixed> The list value, or an empty array
	 */
	private function listValue(array $params, string $key): array {
		$value = ($params[$key] ?? null);
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end listValue()
}//end class
