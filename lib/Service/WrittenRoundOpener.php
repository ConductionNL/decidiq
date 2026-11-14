<?php

/**
 * Opens a voting round held in writing, without a meeting.
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
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * A user in the body's signatory scope (chair, vice-chair, secretary) puts a
 * decision without a meeting to the body's entitled members. The request
 * names the body and a deadline in the future and no meeting; the round stores
 * the snapshot of the electorate. The quorum check of a meeting does not apply:
 * the electorate rule replaces it.
 *
 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
 */
class WrittenRoundOpener {

	/**
	 * The lifecycle states a decision can be put in writing from.
	 *
	 * @var list<string>
	 */
	public const OPEN_FROM = ['proposed', 'deliberating'];

	/**
	 * The rules a written round accepts.
	 *
	 * @var list<string>
	 */
	public const RULES = ['unanimous', 'threshold'];

	/**
	 * Constructor.
	 *
	 * @param WrittenRoundStore         $store      Reads the decision and stage, saves the round.
	 * @param WrittenElectorateResolver $electorate Takes the electorate snapshot.
	 * @param GovernanceScopeGuard      $scopes     The body's signatory scope.
	 * @param ITimeFactory              $time       The clock.
	 * @param VotingOpenedNotifier      $notifier   Tells the electorate the round opened.
	 */
	public function __construct(
		private readonly WrittenRoundStore $store,
		private readonly WrittenElectorateResolver $electorate,
		private readonly GovernanceScopeGuard $scopes,
		private readonly ITimeFactory $time,
		private readonly VotingOpenedNotifier $notifier,
	) {
	}//end __construct()

	/**
	 * Open a written round.
	 *
	 * @param array<string, mixed> $params The request: decisionId, governanceBodyId, votingDeadline, writtenRule, voteThreshold.
	 * @param string               $userId The Nextcloud user opening it.
	 *
	 * @return array<string, mixed> The stored round.
	 *
	 * @throws AccessDeniedException When the user is not in the body's signatory scope.
	 * @throws InvalidArgumentException When the request, the decision or the electorate does not allow the round.
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
	 */
	public function open(array $params, string $userId): array {
		$now     = $this->now();
		$request = $this->request(params: $params, now: $now);

		if ($this->scopes->isInBodyScope(userId: $userId, bodyId: $request['body'], scope: 'signatory') === false) {
			throw new AccessDeniedException(message: 'Only the chair, vice-chair or secretary of the body can put a decision to it in writing.');
		}

		$decision = $this->decision(decisionId: $request['decision']);
		$snapshot = $this->electorate->resolve(bodyId: $request['body'], day: $now);
		if ($snapshot['unreachable'] !== []) {
			throw new InvalidArgumentException('These members have no Nextcloud account and cannot answer: ' . implode(', ', $snapshot['unreachable']));
		}

		if ($snapshot['electorate'] === []) {
			throw new InvalidArgumentException('The body has no active members who can answer in writing.');
		}

		$round = [
			'procedure' => 'written',
			'decision' => (string)$decision['id'],
			'governanceBody' => $request['body'],
			'votingMethod' => 'for-against-abstain',
			'isSecret' => false,
			'writtenRule' => $request['rule'],
			'voteThreshold' => $request['threshold'],
			'electorate' => array_map(static fn (array $member): array => array_diff_key($member, ['name' => true]), $snapshot['electorate']),
			'electorateUserIds' => array_values(array_unique(array_column($snapshot['electorate'], 'userId'))),
			'openedAt' => $now->format(DateTimeInterface::ATOM),
			'votingDeadline' => $request['deadline'],
			'closedAt' => null,
		];

		$stageId = $this->activeVoteStage(decision: $decision, bodyId: $request['body']);
		if ($stageId !== null) {
			$round['decisionStage'] = $stageId;
		}

		$saved = $this->store->save(schema: 'voting-round', object: $round);
		$this->notifier->announceWritten(round: $saved, decisionId: (string)$decision['id'], decisionTitle: (string)($decision['title'] ?? ''));

		return $saved;
	}//end open()

	/**
	 * The validated request, or the 400 that says what is wrong with it.
	 *
	 * @param array<string, mixed> $params The request.
	 * @param DateTimeImmutable    $now    Now.
	 *
	 * @return array{decision: string, body: string, deadline: string, rule: string, threshold: string}
	 *
	 * @throws InvalidArgumentException When the request cannot open a written round.
	 *
	 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
	 */
	public function request(array $params, DateTimeImmutable $now): array {
		if (trim((string)($params['meetingId'] ?? '')) !== '') {
			throw new InvalidArgumentException('A written round has no meeting: leave meetingId out.');
		}

		$decision = trim((string)($params['decisionId'] ?? ''));
		$body     = trim((string)($params['governanceBodyId'] ?? ($params['governanceBody'] ?? '')));
		if ($decision === '' || $body === '') {
			throw new InvalidArgumentException('decisionId and governanceBodyId are required for a written round.');
		}

		$deadline = self::dateTimeOf(value: ($params['votingDeadline'] ?? null));
		if ($deadline === null) {
			throw new InvalidArgumentException('votingDeadline is required for a written round.');
		}

		if ($deadline <= $now) {
			throw new InvalidArgumentException('The deadline of a written round must be in the future.');
		}

		$rule = (string)($params['writtenRule'] ?? 'unanimous');
		if (in_array($rule, self::RULES, true) === false) {
			throw new InvalidArgumentException('writtenRule must be one of: ' . implode(', ', self::RULES));
		}

		$threshold = (string)($params['voteThreshold'] ?? 'simple-majority');
		if (in_array($threshold, VotingService::VOTE_THRESHOLDS, true) === false) {
			throw new InvalidArgumentException('voteThreshold must be one of: ' . implode(', ', VotingService::VOTE_THRESHOLDS));
		}

		return [
			'decision' => $decision,
			'body' => $body,
			'deadline' => $deadline->format(DateTimeInterface::ATOM),
			'rule' => $rule,
			'threshold' => $threshold,
		];
	}//end request()

	/**
	 * The decision, when it can be put in writing.
	 *
	 * @param string $decisionId The decision.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When it does not exist, has a meeting or is past deliberation.
	 */
	private function decision(string $decisionId): array {
		$decision = $this->store->find(id: $decisionId, schema: 'decision');
		if ($decision === null) {
			throw new InvalidArgumentException('The decision does not exist.');
		}

		if (WrittenRoundStore::idOf(value: ($decision['meeting'] ?? null)) !== null) {
			throw new InvalidArgumentException('This decision belongs to a meeting; vote on it in the meeting.');
		}

		if (in_array((string)($decision['lifecycle'] ?? ''), self::OPEN_FROM, true) === false) {
			throw new InvalidArgumentException('Only a proposed or deliberating decision can be put to the body in writing.');
		}

		$decision['id'] = (WrittenRoundStore::idOf(value: ($decision['id'] ?? null)) ?? $decisionId);

		return $decision;
	}//end decision()

	/**
	 * The decision's active vote stage assigned to the body, if it has one.
	 *
	 * @param array<string, mixed> $decision The decision.
	 * @param string               $bodyId   The body.
	 *
	 * @return string|null
	 */
	private function activeVoteStage(array $decision, string $bodyId): ?string {
		foreach ($this->store->findAll(schema: 'decision-stage', filters: ['decision' => (string)$decision['id']]) as $stage) {
			if ((string)($stage['status'] ?? '') === 'active'
				&& (string)($stage['method'] ?? '') === 'vote'
				&& WrittenRoundStore::idOf(value: ($stage['assignedBody'] ?? null)) === $bodyId
			) {
				return WrittenRoundStore::idOf(value: ($stage['id'] ?? null));
			}
		}

		return null;
	}//end activeVoteStage()

	/**
	 * Now, from the app clock.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return (new DateTimeImmutable())->setTimestamp($this->time->getTime());
	}//end now()

	/**
	 * A stored date-time, or null when it holds none or cannot be read.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function dateTimeOf(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			return null;
		}
	}//end dateTimeOf()
}//end class
