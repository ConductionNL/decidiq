<?php

/**
 * Decidesk Mail Vote Reply Processor
 *
 * Turns the `_mail` metadata entries on a VotingRound into cast votes,
 * re-prompts, or abandonments.
 *
 * Extracted from MailReplyHandler: the background job decides WHEN to look for
 * replies, this decides WHAT a reply means. Each entry is evaluated in
 * isolation and returns either its mutated form or null when it is untouched,
 * so the loop that persists the round never has to reason about a reference
 * into the array it is iterating.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Processes email vote replies held in VotingRound `_mail` metadata.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
 */
class MailVoteReplyProcessor {

	/**
	 * Recognised vote keywords mapped to canonical values.
	 *
	 * @var array<string, string>
	 */
	private const VOTE_KEYWORDS = [
		'voor' => 'for',
		'for' => 'for',
		'tegen' => 'against',
		'against' => 'against',
		'onthouding' => 'abstain',
		'abstain' => 'abstain',
		'abstention' => 'abstain',
	];

	/**
	 * Maximum unrecognised reply attempts before email voting is abandoned.
	 *
	 * @var int
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Constructor.
	 *
	 * @param VotingService $votingService The voting service that casts the vote
	 * @param MailVoteSigner $signer Verifies the HMAC on each _mail entry
	 * @param ContainerInterface $container The DI container (lazy-loads OpenRegister services)
	 * @param LoggerInterface $logger The logger
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function __construct(
		private readonly VotingService $votingService,
		private readonly MailVoteSigner $signer,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Process the mail reply metadata on a single VotingRound.
	 *
	 * Persists the round only when at least one entry actually changed.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array<string,mixed> $round The VotingRound object
	 * @param string $roundId The VotingRound UUID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function processRound(object $objectService, array $round, string $roundId): void {
		$entries = ($round['_mail'] ?? []);
		if (empty($entries) === true) {
			return;
		}

		$dirty = false;
		foreach ($entries as $index => $entry) {
			$updated = $this->processEntry(
				objectService: $objectService,
				entry: $entry,
				round: $round,
				roundId: $roundId
			);

			if ($updated === null) {
				continue;
			}

			$entries[$index] = $updated;
			$dirty = true;
		}

		if ($dirty === false) {
			return;
		}

		// Persist mutations: write the updated _mail metadata back to OpenRegister.
		$round['_mail'] = $entries;
		$objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

	}//end processRound()

	/**
	 * Parse the first non-empty line of an email reply for a vote keyword.
	 *
	 * Returns the canonical vote value (for/against/abstain) or null if unrecognised.
	 *
	 * @param string $body The email reply body
	 *
	 * @return string|null The canonical vote value or null
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function parseVoteKeyword(string $body): ?string {
		$lines = explode("\n", $body);
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}

			$normalised = strtolower($line);
			if (isset(self::VOTE_KEYWORDS[$normalised]) === true) {
				return self::VOTE_KEYWORDS[$normalised];
			}

			// First non-empty line is not recognised.
			return null;
		}

		return null;
	}//end parseVoteKeyword()

	/**
	 * Evaluate a single _mail entry.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array<string,mixed> $entry The _mail entry
	 * @param array<string,mixed> $round The VotingRound object
	 * @param string $roundId The VotingRound UUID
	 *
	 * @return array<string,mixed>|null The mutated entry, or null when it is unchanged
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	private function processEntry(object $objectService, array $entry, array $round, string $roundId): ?array {
		$participantId = ($entry['participantId'] ?? null);
		if ((bool)($entry['processed'] ?? false) === true || $participantId === null) {
			return null;
		}

		if ($this->isAuthentic(entry: $entry, roundId: $roundId, participantId: $participantId) === false) {
			return null;
		}

		$participant = $this->findParticipant(
			objectService: $objectService,
			participantId: $participantId,
			roundId: $roundId
		);

		if ($participant === null) {
			return null;
		}

		if ((bool)($round['isSecret'] ?? false) === true) {
			return $this->rejectSecretBallot(entry: $entry, participantId: $participantId, roundId: $roundId);
		}

		$notifyUid = $this->resolveNotifyUid(participant: $participant, participantId: $participantId);
		$keyword = $this->parseVoteKeyword(body: (string)($entry['replyBody'] ?? ''));

		if ($keyword === null) {
			return $this->handleUnrecognised(entry: $entry, notifyUid: $notifyUid, roundId: $roundId);
		}

		return $this->castVote(
			entry: $entry,
			keyword: $keyword,
			participantId: $participantId,
			roundId: $roundId,
			notifyUid: $notifyUid
		);

	}//end processEntry()

	/**
	 * Reject any _mail entry that lacks a valid HMAC signature.
	 *
	 * An entry without a signature was not written by the trusted ingestion path —
	 * it may have been injected directly into OpenRegister by a user with write
	 * access to the VotingRound object (OWASP A08:2021 / issue #299).
	 *
	 * @param array<string,mixed> $entry The _mail entry
	 * @param string $roundId The VotingRound UUID
	 * @param string $participantId The participant UUID (for the audit log)
	 *
	 * @return bool True when the entry carries a valid signature
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	private function isAuthentic(array $entry, string $roundId, string $participantId): bool {
		if ($this->signer->verify(entry: $entry, roundId: $roundId) === true) {
			return true;
		}

		$this->logger->warning(
			'Decidesk: MailReplyHandler — _mail entry rejected: missing or invalid HMAC signature',
			[
				'participantId' => $participantId,
				'votingRoundId' => $roundId,
			]
		);

		return false;
	}//end isAuthentic()

	/**
	 * Validate that a participantId from _mail metadata refers to a real Participant.
	 *
	 * Prevents manipulated metadata from casting votes on behalf of arbitrary or
	 * non-existent participants (OWASP A07:2021).
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $participantId The participant UUID
	 * @param string $roundId The VotingRound UUID (for the audit log)
	 *
	 * @return array<string,mixed>|null The participant record, or null when unknown
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	private function findParticipant(object $objectService, string $participantId, string $roundId): ?array {
		$entity = $objectService->find(id: $participantId, register: 'decidesk', schema: 'participant');
		if ($entity !== null) {
			return $entity->jsonSerialize();
		}

		$this->logger->warning(
			'Decidesk: MailReplyHandler — unknown participantId in _mail metadata, skipping',
			[
				'participantId' => $participantId,
				'votingRoundId' => $roundId,
			]
		);

		return null;
	}//end findParticipant()

	/**
	 * Refuse an email vote on a secret round.
	 *
	 * Email does not provide ballot secrecy: a reply is visible in transit logs
	 * and to the mail server operator, so counting it as a secret ballot would
	 * undermine the anonymity guarantee (issue #299, item 4 of suggested fix).
	 *
	 * @param array<string,mixed> $entry The _mail entry
	 * @param string $participantId The participant UUID
	 * @param string $roundId The VotingRound UUID
	 *
	 * @return array<string,mixed> The entry marked as rejected
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	private function rejectSecretBallot(array $entry, string $participantId, string $roundId): array {
		$this->logger->warning(
			'Decidesk: MailReplyHandler — email vote rejected: round is a secret ballot',
			[
				'participantId' => $participantId,
				'votingRoundId' => $roundId,
			]
		);

		$entry['processed'] = true;
		$entry['abandoned'] = true;
		$entry['rejectReason'] = 'secret-ballot';

		return $entry;
	}//end rejectSecretBallot()

	/**
	 * Cast the vote a recognised reply asked for.
	 *
	 * @param array<string,mixed> $entry The _mail entry
	 * @param string $keyword The canonical vote value
	 * @param string $participantId The participant UUID
	 * @param string $roundId The VotingRound UUID
	 * @param string|null $notifyUid The Nextcloud UID to confirm to, when resolvable
	 *
	 * @return array<string,mixed>|null The entry marked processed, or null when the cast failed
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	private function castVote(
		array $entry,
		string $keyword,
		string $participantId,
		string $roundId,
		?string $notifyUid,
	): ?array {
		try {
			$this->votingService->castVote(
				votingRoundId: $roundId,
				participantId: $participantId,
				value: $keyword,
				isProxy: false,
				delegatorId: null
			);
		} catch (Throwable $e) {
			$this->logger->warning('Decidesk: email vote cast failed', ['error' => $e->getMessage()]);
			return null;
		}

		$this->notify(
			notifyUid: $notifyUid,
			subject: 'email_vote_confirmed',
			parameters: [
				'value' => $keyword,
				'votingRoundId' => $roundId,
			],
			failureMessage: 'Decidesk: vote confirmation notification failed',
			roundId: $roundId
		);

		$entry['processed'] = true;
		$this->logger->info('Decidesk: email vote processed', ['participant' => $participantId]);

		return $entry;
	}//end castVote()

	/**
	 * Re-prompt on an unrecognised reply, or abandon after MAX_RETRIES attempts.
	 *
	 * @param array<string,mixed> $entry The _mail entry
	 * @param string|null $notifyUid The Nextcloud UID to prompt, when resolvable
	 * @param string $roundId The VotingRound UUID
	 *
	 * @return array<string,mixed> The entry with its retry state advanced
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	private function handleUnrecognised(array $entry, ?string $notifyUid, string $roundId): array {
		$retries = ((int)($entry['retries'] ?? 0) + 1);

		if ($retries >= self::MAX_RETRIES) {
			$entry['processed'] = true;
			$entry['abandoned'] = true;

			$this->notify(
				notifyUid: $notifyUid,
				subject: 'email_vote_abandoned',
				parameters: ['votingRoundId' => $roundId],
				failureMessage: 'Decidesk: abandoned vote notification failed',
				roundId: $roundId
			);

			return $entry;
		}

		$entry['retries'] = $retries;

		$this->notify(
			notifyUid: $notifyUid,
			subject: 'email_vote_reprompt',
			parameters: [
				'votingRoundId' => $roundId,
				'attempt' => $retries,
			],
			failureMessage: 'Decidesk: reprompt notification failed',
			roundId: $roundId
		);

		return $entry;
	}//end handleUnrecognised()

	/**
	 * Resolve the Nextcloud UID to notify for a participant.
	 *
	 * The participant UUID is not a valid Nextcloud userId. Prefers the stored
	 * nextcloudUserId and falls back to an unambiguous email lookup.
	 *
	 * @param array<string,mixed> $participant The participant record
	 * @param string $participantId The participant UUID (for the audit log)
	 *
	 * @return string|null The Nextcloud UID, or null when unresolvable
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	private function resolveNotifyUid(array $participant, string $participantId): ?string {
		$notifyUid = ($participant['nextcloudUserId'] ?? null);
		if ($notifyUid !== null) {
			return (string)$notifyUid;
		}

		$email = ($participant['email'] ?? null);
		if ($email === null) {
			return null;
		}

		try {
			$userManager = $this->container->get(\OCP\IUserManager::class);
			$users = $userManager->getByEmail($email);
			if (count($users) === 1) {
				return $users[0]->getUID();
			}
		} catch (Throwable) {
			$this->logger->warning(
				'Decidesk: could not resolve Nextcloud UID for participant',
				['participantId' => $participantId]
			);
		}

		return null;
	}//end resolveNotifyUid()

	/**
	 * Send one voting-round notification, swallowing delivery failures.
	 *
	 * @param string|null $notifyUid The Nextcloud UID, or null to skip
	 * @param string $subject The notification subject key
	 * @param array<string,mixed> $parameters The subject parameters
	 * @param string $failureMessage The log message used when delivery fails
	 * @param string $roundId The VotingRound UUID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	private function notify(
		?string $notifyUid,
		string $subject,
		array $parameters,
		string $failureMessage,
		string $roundId,
	): void {
		if ($notifyUid === null) {
			return;
		}

		try {
			$notificationService = $this->container->get('OCA\OpenRegister\Service\NotificationService');
			$notificationService->createNotification(
				userId: $notifyUid,
				app: 'decidesk',
				subject: $subject,
				subjectParameters: $parameters,
				object: 'voting-round',
				objectId: $roundId
			);
		} catch (Throwable $e) {
			$this->logger->warning($failureMessage, ['error' => $e->getMessage()]);
		}

	}//end notify()
}//end class
