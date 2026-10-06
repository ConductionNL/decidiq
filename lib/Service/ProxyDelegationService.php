<?php

/**
 * Decidiq Proxy Delegation Service
 *
 * Grant and revoke a proxy (volmacht) on a VotingRound.
 *
 * Extracted from VotingService: delegation is about WHO may vote, not about how
 * a ballot is counted, and it is the only voting concern that writes structured
 * notes onto the round and notifies a delegate. Keeping it here leaves
 * VotingService with the ballot lifecycle alone.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Proxy (volmacht) delegation on a VotingRound.
 *
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
 */
class ProxyDelegationService {
	/**
	 * Participant roles that may never receive a proxy.
	 *
	 * @var string[]
	 */
	private const NON_VOTING_ROLES = ['observer', 'guest'];

	/**
	 * The per-holder proxy cap applied to every grant.
	 *
	 * @var ProxyHolderCap
	 */
	private readonly ProxyHolderCap $holderCap;

	/**
	 * Constructor for ProxyDelegationService.
	 *
	 * @param ContainerInterface $container The DI container (OpenRegister is resolved lazily)
	 * @param LoggerInterface $logger Logger for fail-soft notification failures
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param ProxyHolderCap|null $holderCap The per-holder proxy cap (built from the container when omitted)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		?ProxyHolderCap $holderCap = null,
	) {
		$this->holderCap = ($holderCap ?? new ProxyHolderCap(container: $container, logger: $logger));

	}//end __construct()

	/**
	 * Grant proxy: delegate voting right from one participant to another for a VotingRound.
	 *
	 * Validates that the receiver has a voting role (not observer/guest) and
	 * that the receiver does not already hold the maximum number of proxies
	 * on this round (app config `decidiq`/`max_proxies_per_holder`, the same
	 * cap ProxyVoteService::register() applies, NL governance default 2).
	 * A grantor holds at most one grant per round: granting again replaces
	 * their earlier grant instead of adding a second one.
	 * Sends notification to the delegate.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $fromParticipantId The delegating participant UUID
	 * @param string $toParticipantId The receiving participant UUID
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the receiver cannot receive proxies or already holds the maximum
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function grantProxy(string $votingRoundId, string $fromParticipantId, string $toParticipantId): void {
		if ($fromParticipantId === $toParticipantId) {
			throw new InvalidArgumentException('Een deelnemer kan geen volmacht aan zichzelf verlenen');
		}

		$objectService = $this->objectService();

		$toParticipantEntity = $objectService->find(id: $toParticipantId, register: 'decidiq', schema: 'participant');
		$toParticipant = null;
		if ($toParticipantEntity !== null) {
			$toParticipant = $toParticipantEntity->jsonSerialize();
		}

		if ($toParticipant !== null) {
			$role = strtolower($toParticipant['role'] ?? '');
			if (in_array($role, self::NON_VOTING_ROLES, true) === true) {
				throw new InvalidArgumentException(
					"Deelnemer met rol '{$role}' kan geen volmacht ontvangen"
				);
			}
		}

		$proxyRecord = [
			'fromParticipantId' => $fromParticipantId,
			'toParticipantId' => $toParticipantId,
			'votingRoundId' => $votingRoundId,
			'grantedAt' => (new DateTime())->format(DateTime::ATOM),
		];

		// Store proxy as a structured note on the VotingRound.
		$roundEntity = $objectService->find(id: $votingRoundId, register: 'decidiq', schema: 'voting-round');
		$round = null;
		if ($roundEntity !== null) {
			$round = $roundEntity->jsonSerialize();
		}

		if ($round !== null) {
			// A re-grant replaces the grantor's earlier grant on this round.
			$notes = $this->withoutGrantFrom(notes: ($round['notes'] ?? []), fromParticipantId: $fromParticipantId);
			$this->holderCap->assertRoomFor(grants: $this->grants(notes: $notes), toParticipantId: $toParticipantId);
			$notes[] = [
				'title' => 'Proxy',
				'body' => json_encode($proxyRecord),
			];
			$round['notes'] = $notes;
			$objectService->saveObject(register: 'decidiq', schema: 'voting-round', object: $round);
		}

		if ($toParticipant !== null) {
			$this->notifyDelegate(
				toParticipant: $toParticipant,
				votingRoundId: $votingRoundId,
				fromParticipantId: $fromParticipantId
			);
		}

	}//end grantProxy()

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
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
	 */
	public function revokeProxy(string $votingRoundId, string $fromParticipantId): void {
		$objectService = $this->objectService();
		$roundEntity = $objectService->find(id: $votingRoundId, register: 'decidiq', schema: 'voting-round');
		$round = null;
		if ($roundEntity !== null) {
			$round = $roundEntity->jsonSerialize();
		}

		if ($round === null) {
			throw new RuntimeException("VotingRound {$votingRoundId} not found");
		}

		if (($round['openedAt'] ?? null) !== null) {
			throw new RuntimeException('Stemronde is al geopend — volmacht kan niet meer worden ingetrokken');
		}

		$round['notes'] = $this->withoutGrantFrom(notes: ($round['notes'] ?? []), fromParticipantId: $fromParticipantId);
		$objectService->saveObject(register: 'decidiq', schema: 'voting-round', object: $round);

	}//end revokeProxy()

	/**
	 * Say which proxies a participant holds and has given on a round.
	 *
	 * Read by the voting panel so a proxy holder can cast the vote they were
	 * given on the grantor's behalf, and a grantor sees the grant they made.
	 * Each held entry carries the delegator's participant UUID (what the cast
	 * endpoint takes as `delegatorId`) and display name.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $participantId The caller's participant UUID
	 *
	 * @return array{participantId: string, held: list<array{participantId: string, displayName: string}>, granted: string|null}
	 *
	 * @throws \RuntimeException When the round does not exist
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	public function proxiesFor(string $votingRoundId, string $participantId): array {
		$roundEntity = $this->objectService()->find(id: $votingRoundId, register: 'decidiq', schema: 'voting-round');
		if ($roundEntity === null) {
			throw new RuntimeException("VotingRound {$votingRoundId} not found");
		}

		$held = [];
		$granted = null;
		foreach ($this->grants(notes: ($roundEntity->jsonSerialize()['notes'] ?? [])) as $grant) {
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
	 * @param array<int, mixed> $notes The round's notes
	 *
	 * @return list<array{fromParticipantId: string, toParticipantId: string}>
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
	 */
	private function grants(array $notes): array {
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
	 * Drop the grantor's Proxy note(s) from a round's notes.
	 *
	 * @param array<int, mixed> $notes The round's notes
	 * @param string $fromParticipantId The grantor whose grant is removed
	 *
	 * @return array<int, mixed> The remaining notes
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
	 */
	private function withoutGrantFrom(array $notes, string $fromParticipantId): array {
		return array_values(
			array_filter(
				$notes,
				static function (mixed $note) use ($fromParticipantId): bool {
					if (is_array($note) === false || ($note['title'] ?? '') !== 'Proxy') {
						return true;
					}

					$body = json_decode((string)($note['body'] ?? '{}'), true);
					return (($body['fromParticipantId'] ?? '') !== $fromParticipantId);
				}
			)
		);

	}//end withoutGrantFrom()

	/**
	 * A participant's display name, or their UUID when it cannot be read.
	 *
	 * @param string $participantId The participant UUID
	 *
	 * @return string
	 *
	 * @spec openspec/specs/voting-system/spec.md
	 */
	private function displayName(string $participantId): string {
		try {
			$entity = $this->objectService()->find(id: $participantId, register: 'decidiq', schema: 'participant');
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

	/**
	 * Notify the delegate that a proxy was granted to them (fail-soft).
	 *
	 * Resolves the Nextcloud UID from the participant object, falling back to an
	 * email lookup when nextcloudUserId is not stored on the participant.
	 *
	 * @param array<string,mixed> $toParticipant The receiving participant object
	 * @param string $votingRoundId The voting round UUID
	 * @param string $fromParticipantId The delegating participant UUID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
	 */
	private function notifyDelegate(array $toParticipant, string $votingRoundId, string $fromParticipantId): void {
		try {
			$nextcloudUserId = ($toParticipant['nextcloudUserId'] ?? null);

			if ($nextcloudUserId === null) {
				$email = ($toParticipant['email'] ?? null);
				if ($email !== null) {
					$userManager = $this->container->get(IUserManager::class);
					$users = $userManager->getByEmail($email);
					if (count($users) === 1) {
						$nextcloudUserId = $users[0]->getUID();
					}
				}
			}

			if ($nextcloudUserId === null) {
				return;
			}

			$notificationManager = $this->container->get(IManager::class);
			$notification = $notificationManager->createNotification();
			$notification->setApp('decidiq')
				->setUser($nextcloudUserId)
				->setDateTime(new DateTime())
				->setObject('voting-round', $votingRoundId)
				->setSubject('proxy_granted', ['from' => $fromParticipantId, 'votingRoundId' => $votingRoundId]);
			$notificationManager->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: proxy grant notification failed', ['error' => $e->getMessage()]);
		}//end try

	}//end notifyDelegate()

	/**
	 * Resolve OpenRegister ObjectService.
	 *
	 * @return object The OpenRegister ObjectService
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()
}//end class
