<?php

/**
 * Decidesk Mail Reply Handler Background Job
 *
 * Polls for email replies to voting notification threads and casts votes
 * based on the first non-empty line of the reply body.
 *
 * Vote-by-email is an ADR-022 statutory-voting exception: the vote-casting
 * logic stays in the in-app voting path (VotingService::castVote) and is NOT
 * migrated to a leaf (migrate-email-links-to-email-leaf, design D2). Thread
 * association never used the retired in-app EmailLink store — it is held by
 * HMAC-signed `_mail` metadata on the VotingRound OR object (the registry's
 * own object), so retiring EmailLinkService does not affect this handler.
 * The vote thread surfaces through the Email integration leaf bound to the
 * motion/decision object via the registry, not an EmailLink object.
 *
 * @category BackgroundJob
 * @package  OCA\Decidesk\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3
 * @spec openspec/changes/migrate-email-links-to-email-leaf/tasks.md#task-3.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\BackgroundJob;

use OCA\Decidesk\AppInfo\Application;
use OCA\Decidesk\Service\MailVoteReplyProcessor;
use OCA\Decidesk\Service\MailVoteSigner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Background job that polls for email vote replies on open VotingRounds.
 *
 * The job owns the schedule and the feature flag; what a reply MEANS is
 * MailVoteReplyProcessor's job and how an entry is authenticated is
 * MailVoteSigner's. The signing methods below stay on this class because the
 * ingestion path already calls them and their contract is public API.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
 */
class MailReplyHandler extends TimedJob {
	/**
	 * Constructor for MailReplyHandler.
	 *
	 * @param ITimeFactory $time Nextcloud time factory
	 * @param IAppConfig $appConfig The app config
	 * @param ContainerInterface $container The DI container
	 * @param LoggerInterface $logger The logger
	 * @param MailVoteSigner $signer Signs and verifies _mail entries
	 * @param MailVoteReplyProcessor $processor Turns _mail entries into votes
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly MailVoteSigner $signer,
		private readonly MailVoteReplyProcessor $processor,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(time: $time);
		// Run every 5 minutes.
		$this->setInterval(seconds: 300);

	}//end __construct()

	/**
	 * Compute the HMAC for a _mail entry.
	 *
	 * @param string $participantId The participant UUID
	 * @param string $roundId The VotingRound UUID
	 * @param string $timestamp ISO 8601 timestamp written by the ingestion path
	 *
	 * @return string The hex HMAC
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function computeMailHmac(string $participantId, string $roundId, string $timestamp): string {
		return $this->signer->compute(
			participantId: $participantId,
			roundId: $roundId,
			timestamp: $timestamp
		);

	}//end computeMailHmac()

	/**
	 * Sign a _mail entry array and return it with the hmac field set.
	 *
	 * The ingestion path (SMTP webhook, future API) MUST call this method before
	 * writing a _mail entry to the VotingRound object. Any entry lacking a valid
	 * hmac field is rejected by MailVoteReplyProcessor before counting.
	 *
	 * @param array<string,mixed> $entry The raw _mail entry (must contain participantId and timestamp)
	 * @param string $roundId The VotingRound UUID (used to derive the secret)
	 *
	 * @return array<string,mixed> The entry with hmac appended
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	public function signMailEntry(array $entry, string $roundId): array {
		return $this->signer->sign(entry: $entry, roundId: $roundId);
	}//end signMailEntry()

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
		return $this->processor->parseVoteKeyword(body: $body);
	}//end parseVoteKeyword()

	/**
	 * Run the background job: poll email replies and process votes.
	 *
	 * @param mixed $argument The job argument (unused)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 * the OCP\BackgroundJob\Job::run() signature this method overrides. The job
	 * carries no argument, but the parameter cannot be dropped without breaking
	 * the parent contract.
	 */
	protected function run(mixed $argument): void {
		$emailVotingEnabled = $this->appConfig->getValueString(Application::APP_ID, 'email_voting_enabled', '0');
		if ($emailVotingEnabled !== '1') {
			return;
		}

		try {
			$this->processOpenRounds();
		} catch (\Throwable $e) {
			$this->logger->error('Decidesk: MailReplyHandler failed', ['error' => $e->getMessage()]);
		}

	}//end run()

	/**
	 * Find open VotingRounds and process their email reply metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-3.2
	 */
	private function processOpenRounds(): void {
		$this->objectService->setRegister('decidesk');
		$this->objectService->setSchema('voting-round');
		$roundEntities = $this->objectService->findAll(['filters' => ['closedAt' => null, 'openedAt' => ['!=' => null]]]);

		foreach ($roundEntities as $roundEntity) {
			$round = $roundEntity->jsonSerialize();
			$roundId = ($round['uuid'] ?? $round['id'] ?? null);
			if ($roundId === null) {
				continue;
			}

			$this->processor->processRound(
				objectService: $objectService,
				round: $round,
				roundId: $roundId
			);
		}

	}//end processOpenRounds()
}//end class
