<?php

/**
 * Decidesk Proxy Delegation Service
 *
 * Grant and revoke a proxy (volmacht) on a VotingRound.
 *
 * Extracted from VotingService: delegation is about WHO may vote, not about how
 * a ballot is counted, and it is the only voting concern that writes structured
 * notes onto the round and notifies a delegate. Keeping it here leaves
 * VotingService with the ballot lifecycle alone.
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
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
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
	 * Constructor for ProxyDelegationService.
	 *
	 * @param ContainerInterface $container The DI container (OpenRegister is resolved lazily)
	 * @param LoggerInterface $logger Logger for fail-soft notification failures
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Grant proxy: delegate voting right from one participant to another for a VotingRound.
	 *
	 * Validates that the receiver has a voting role (not observer/guest).
	 * Sends notification to the delegate.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $fromParticipantId The delegating participant UUID
	 * @param string $toParticipantId The receiving participant UUID
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the receiver cannot receive proxies
	 *
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-2.1
	 */
	public function grantProxy(string $votingRoundId, string $fromParticipantId, string $toParticipantId): void {
		if ($fromParticipantId === $toParticipantId) {
			throw new InvalidArgumentException('Een deelnemer kan geen volmacht aan zichzelf verlenen');
		}

		$objectService = $this->objectService();

		$toParticipantEntity = $objectService->find(id: $toParticipantId, register: 'decidesk', schema: 'participant');
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
			'grantedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		// Store proxy as a structured note on the VotingRound.
		$roundEntity = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
		$round = null;
		if ($roundEntity !== null) {
			$round = $roundEntity->jsonSerialize();
		}

		if ($round !== null) {
			$notes = ($round['notes'] ?? []);
			$notes[] = [
				'title' => 'Proxy',
				'body' => json_encode($proxyRecord),
			];
			$round['notes'] = $notes;
			$objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);
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
		$roundEntity = $objectService->find(id: $votingRoundId, register: 'decidesk', schema: 'voting-round');
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

		$notes = ($round['notes'] ?? []);
		$filtered = array_values(
			array_filter(
				$notes,
				static function (array $note) use ($fromParticipantId): bool {
					if (($note['title'] ?? '') !== 'Proxy') {
						return true;
					}

					$body = json_decode($note['body'] ?? '{}', true);
					return ($body['fromParticipantId'] ?? '') !== $fromParticipantId;
				}
			)
		);

		$round['notes'] = $filtered;
		$objectService->saveObject(register: 'decidesk', schema: 'voting-round', object: $round);

	}//end revokeProxy()

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
			$notification->setApp('decidesk')
				->setUser($nextcloudUserId)
				->setDateTime(new DateTime())
				->setObject('voting-round', $votingRoundId)
				->setSubject('proxy_granted', ['from' => $fromParticipantId, 'votingRoundId' => $votingRoundId]);
			$notificationManager->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning('Decidesk: proxy grant notification failed', ['error' => $e->getMessage()]);
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
