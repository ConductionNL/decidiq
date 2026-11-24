<?php

/**
 * Decidiq Proxy Holdings
 *
 * Read side of proxy delegation: which proxies a participant holds on a
 * voting round and which one they gave, so the voting panel can offer the
 * holder a vote on the grantor's behalf.
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
 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads the proxy grants stored as `Proxy` notes on a voting round.
 *
 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
 */
class ProxyHoldings {
	/**
	 * Constructor for ProxyHoldings.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister ObjectService contract
	 * @param LoggerInterface        $logger        The logger
	 *
	 * @return void
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Say which proxies a participant holds and has given on a round.
	 *
	 * Each held entry carries the delegator's participant UUID (what the cast
	 * endpoint takes as `delegatorId`) and display name (vot-10, #1377).
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $participantId The caller's participant UUID
	 *
	 * @return array{participantId: string, held: list<array{participantId: string, displayName: string}>, granted: string|null}
	 *
	 * @throws RuntimeException When the round does not exist
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	public function proxiesFor(string $votingRoundId, string $participantId): array {
		$round = $this->objectService->find(id: $votingRoundId, options: new ObjectFindOptions(register: 'decidiq', schema: 'voting-round'));
		if ($round === null) {
			throw new RuntimeException("VotingRound {$votingRoundId} not found");
		}

		$held = [];
		$granted = null;
		foreach (self::grants(notes: ($round->jsonSerialize()['notes'] ?? [])) as $grant) {
			if ($grant['fromParticipantId'] === $participantId) {
				$granted = $grant['toParticipantId'];
			}

			if ($grant['toParticipantId'] === $participantId) {
				$held[] = [
					'participantId' => $grant['fromParticipantId'],
					'displayName' => $this->displayName(participantId: $grant['fromParticipantId']),
				];
			}
		}

		return [
			'participantId' => $participantId,
			'held' => $held,
			'granted' => $granted,
		];
	}//end proxiesFor()

	/**
	 * Decode the Proxy notes on a round into grant records.
	 *
	 * @param mixed $notes The round's notes
	 *
	 * @return list<array{fromParticipantId: string, toParticipantId: string}>
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	private static function grants(mixed $notes): array {
		if (is_array($notes) === false) {
			return [];
		}

		$grants = [];
		foreach ($notes as $note) {
			if (is_array($note) === false || ($note['title'] ?? '') !== 'Proxy') {
				continue;
			}

			$body = json_decode((string)($note['body'] ?? '{}'), true);
			if (is_array($body) === false) {
				continue;
			}

			$grants[] = [
				'fromParticipantId' => (string)($body['fromParticipantId'] ?? ''),
				'toParticipantId' => (string)($body['toParticipantId'] ?? ''),
			];
		}

		return $grants;
	}//end grants()

	/**
	 * A participant's display name, or their UUID when it cannot be read.
	 *
	 * @param string $participantId The participant UUID
	 *
	 * @return string
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	private function displayName(string $participantId): string {
		try {
			$entity = $this->objectService->find(id: $participantId, options: new ObjectFindOptions(register: 'decidiq', schema: 'participant'));
			$name = '';
			if ($entity !== null) {
				$name = (string)($entity->jsonSerialize()['displayName'] ?? '');
			}

			if ($name !== '') {
				return $name;
			}
		} catch (Throwable $e) {
			$this->logger->debug('Decidiq: proxy delegator name lookup failed', ['error' => $e->getMessage()]);
		}

		return $participantId;
	}//end displayName()
}//end class
