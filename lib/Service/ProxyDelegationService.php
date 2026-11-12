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
 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Proxy (volmacht) delegation on a VotingRound.
 *
 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
 */
class ProxyDelegationService {
	/**
	 * Participant roles that may never receive a proxy.
	 *
	 * @var string[]
	 */
	private const NON_VOTING_ROLES = ['observer', 'guest'];

	/**
	 * App config key holding how many proxies one holder may hold.
	 *
	 * @var string
	 */
	public const MAX_PROXIES_CONFIG_KEY = 'max_proxies_per_holder';

	/**
	 * NL governance default: a member may hold at most 2 proxies.
	 *
	 * @var int
	 */
	public const MAX_PROXIES_DEFAULT = 2;

	/**
	 * Constructor for ProxyDelegationService.
	 *
	 * @param ContainerInterface $container The DI container (OpenRegister is resolved lazily)
	 * @param LoggerInterface $logger Logger for fail-soft notification failures
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 * @param IAppConfig $appConfig App config holding the per-holder proxy cap
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Grant proxy: delegate voting right from one participant to another for a VotingRound.
	 *
	 * Validates that the receiver has a voting role (not observer/guest) and
	 * holds fewer proxies in the round's meeting than the configured cap
	 * (`decidiq`/`max_proxies_per_holder`, default 2); a round without a
	 * meeting counts its own proxies only. Sends notification to the delegate.
	 *
	 * @param string $votingRoundId The voting round UUID
	 * @param string $fromParticipantId The delegating participant UUID
	 * @param string $toParticipantId The receiving participant UUID
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the receiver cannot receive proxies or the cap is reached
	 * @throws \RuntimeException When the voting round does not exist
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
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
			'grantedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		// Store proxy as a structured note on the VotingRound. Without the round
		// the held proxies cannot be counted, so the grant is refused.
		$roundEntity = $objectService->find(id: $votingRoundId, register: 'decidiq', schema: 'voting-round');
		if ($roundEntity === null) {
			throw new RuntimeException("VotingRound {$votingRoundId} not found");
		}

		$round = $roundEntity->jsonSerialize();
		$notes = ($round['notes'] ?? []);

		$this->assertBelowProxyCap(notes: $this->notesInMeeting(round: $round, votingRoundId: $votingRoundId), fromParticipantId: $fromParticipantId, toParticipantId: $toParticipantId);

		$notes[] = [
			'title' => 'Proxy',
			'body' => json_encode($proxyRecord),
		];
		$round['notes'] = $notes;
		$objectService->saveObject(register: 'decidiq', schema: 'voting-round', object: $round);

		if ($toParticipant !== null) {
			$this->notifyDelegate(
				toParticipant: $toParticipant,
				votingRoundId: $votingRoundId,
				fromParticipantId: $fromParticipantId
			);
		}

	}//end grantProxy()

	/**
	 * Refuse the grant when the holder already holds the maximum number of proxies.
	 *
	 * Counts the distinct grantors whose proxy note names the holder; the
	 * grantor of this request is left out, so a repeated grant does not count
	 * twice.
	 *
	 * @param array<int,mixed> $notes The notes of every round the cap spans
	 * @param string $fromParticipantId The delegating participant UUID
	 * @param string $toParticipantId The receiving participant UUID
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the holder has reached the cap
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	private function assertBelowProxyCap(array $notes, string $fromParticipantId, string $toParticipantId): void {
		$grantors = [];
		foreach ($notes as $note) {
			if (is_array($note) === false || ($note['title'] ?? '') !== 'Proxy') {
				continue;
			}

			$body = json_decode((string)($note['body'] ?? '{}'), true);
			if (is_array($body) === false || ($body['toParticipantId'] ?? '') !== $toParticipantId) {
				continue;
			}

			$grantor = (string)($body['fromParticipantId'] ?? '');
			if ($grantor !== $fromParticipantId) {
				$grantors[$grantor] = true;
			}
		}

		$held = count($grantors);
		$maxProxies = $this->maxProxiesPerHolder();
		if ($held >= $maxProxies) {
			throw new InvalidArgumentException(
				sprintf(
					'Maximaal aantal volmachten bereikt: deze deelnemer heeft al %d van de %d toegestane volmachten',
					$held,
					$maxProxies
				)
			);
		}

	}//end assertBelowProxyCap()

	/**
	 * The notes of the round and of every other round of its meeting.
	 *
	 * A round without a meeting contributes its own notes only. The other
	 * rounds are read without RBAC or organisation scoping, so a round the
	 * granting user cannot see still counts towards the cap.
	 *
	 * @param array<string,mixed> $round The voting round the proxy is granted on
	 * @param string $votingRoundId The voting round UUID
	 *
	 * @return array<int,mixed> The notes the cap counts
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	private function notesInMeeting(array $round, string $votingRoundId): array {
		$notes = ($round['notes'] ?? []);
		$meetingId = ($round['meeting'] ?? '');
		if (is_string($meetingId) === false || $meetingId === '') {
			return $notes;
		}

		$rounds = $this->objectService->findAll(
			config: ['filters' => ['register' => 'decidiq', 'schema' => 'voting-round', 'meeting' => $meetingId], 'limit' => 1000],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($rounds as $entity) {
			$other = $entity->jsonSerialize();
			if (($other['id'] ?? '') === $votingRoundId || ($other['meeting'] ?? '') !== $meetingId) {
				continue;
			}

			$notes = array_merge($notes, ($other['notes'] ?? []));
		}

		return $notes;

	}//end notesInMeeting()

	/**
	 * Resolve the configured per-holder proxy cap.
	 *
	 * Values below 1 and lookup failures fall back to the default of 2, so a
	 * misconfigured cap never disables the limit.
	 *
	 * @return int The maximum number of proxies one holder may hold
	 *
	 * @spec openspec/specs/voting-system/spec.md#requirement-proxy-voting
	 */
	private function maxProxiesPerHolder(): int {
		try {
			$value = $this->appConfig->getValueInt('decidiq', self::MAX_PROXIES_CONFIG_KEY, self::MAX_PROXIES_DEFAULT);
			if ($value >= 1) {
				return $value;
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: max_proxies_per_holder config lookup failed, using default',
				['exception' => $e->getMessage()]
			);
		}

		return self::MAX_PROXIES_DEFAULT;

	}//end maxProxiesPerHolder()

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
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
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
		$objectService->saveObject(register: 'decidiq', schema: 'voting-round', object: $round);

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
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
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
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2.1
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()
}//end class
