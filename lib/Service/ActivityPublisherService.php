<?php

/**
 * Decidiq Activity Publisher Service
 *
 * Publishes Decidiq governance events (decision recorded/published, meeting
 * lifecycle transitions, vote initiation, resolution adoption) to the
 * Nextcloud Activity feed via OCP\Activity\IManager.
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
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Activity\GovernanceSetting;
use OCA\Decidiq\AppInfo\Application;
use OCP\Activity\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fail-soft publisher for Decidiq governance activity events.
 *
 * Every public method catches \Throwable internally: Activity is an
 * observability surface and must never abort the underlying governance
 * transition (the fail-soft observability posture).
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class ActivityPublisherService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (resolves IManager, IUserSession, ParticipantResolver lazily)
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Publish a governance event to the Activity feed.
	 *
	 * One IEvent is published per affected user (deduplicated; the acting
	 * session user is always included so an event is never published to
	 * nobody). Failures are logged at debug level and swallowed.
	 *
	 * The activity holds the subject id (one of the DecidiqProvider::SUBJECT_*
	 * constants), the object title and optional status rendered in the subject,
	 * the activity object type (e.g. 'decision', 'meeting'), the OpenRegister
	 * object uuid and the frontend route segment for the deep link (e.g.
	 * 'decisions').
	 *
	 * @param array{subject: string, title: string, status: string, objectType: string, objectUuid: string, segment: string} $activity The activity
	 * @param array<string> $affectedUserIds Nextcloud UIDs the entry is addressed to (acting user auto-added)
	 *
	 * @return int The number of activity entries published
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	public function publishGovernanceEvent(array $activity, array $affectedUserIds = []): int {
		return $this->publish(
			entry: [
				'subject' => $activity['subject'],
				'parameters' => [
					'title' => $activity['title'],
					'status' => $activity['status'],
					'uuid' => $activity['objectUuid'],
					'segment' => $activity['segment'],
				],
				'objectType' => $activity['objectType'],
				'objectUuid' => $activity['objectUuid'],
			],
			affectedUserIds: $affectedUserIds
		);

	}//end publishGovernanceEvent()

	/**
	 * Publish one entry to its audience: the affected users plus the acting
	 * session user, each once.
	 *
	 * @param array{subject: string, parameters: array<string, string>, objectType: string, objectUuid: string} $entry The entry
	 * @param array<mixed> $affectedUserIds Nextcloud UIDs the entry is addressed to
	 *
	 * @return int The number of activity entries published
	 */
	private function publish(array $entry, array $affectedUserIds): int {
		try {
			$activityManager = $this->container->get(IManager::class);

			$entry['author'] = $this->resolveSessionUserId();
			$users = self::audience(affectedUserIds: $affectedUserIds, author: $entry['author']);
			if ($users === []) {
				$this->logger->debug(
					'Decidiq: activity event has no resolvable audience, skipped',
					['subject' => $entry['subject'], 'objectUuid' => $entry['objectUuid']]
				);
				return 0;
			}

			$published = 0;
			foreach ($users as $uid) {
				$published += $this->publishTo(manager: $activityManager, entry: $entry + ['uid' => $uid]);
			}

			return $published;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidiq: activity publication skipped',
				['subject' => $entry['subject'], 'objectUuid' => $entry['objectUuid'], 'error' => $e->getMessage()]
			);
			return 0;
		}//end try

	}//end publish()

	/**
	 * The distinct non-empty UIDs of the affected users and the author.
	 *
	 * @param array<mixed> $affectedUserIds The affected users
	 * @param string|null  $author          The acting session user
	 *
	 * @return list<string>
	 */
	private static function audience(array $affectedUserIds, ?string $author): array {
		$users = $affectedUserIds;
		if ($author !== null) {
			$users[] = $author;
		}

		return array_values(array_unique(array_filter($users, static fn ($uid) => is_string($uid) === true && $uid !== '')));

	}//end audience()

	/**
	 * Publish the entry to one user; a failure is logged and counts as none.
	 *
	 * @param IManager $manager The Activity manager
	 * @param array<string, mixed> $entry The entry, its author and its user
	 *
	 * @return int 1 when published, 0 when it failed
	 */
	private function publishTo(IManager $manager, array $entry): int {
		try {
			$event = $manager->generateEvent();
			$event->setApp(Application::APP_ID)
				->setType(GovernanceSetting::TYPE_GOVERNANCE)
				->setAffectedUser($entry['uid'])
				->setTimestamp(time())
				->setSubject($entry['subject'], $entry['parameters'])
				// IEvent::setObject() requires an int id; OR uuids are
				// strings, so the uuid travels in the subject parameters
				// and crc32 provides a stable numeric grouping key.
				->setObject($entry['objectType'], (int)sprintf('%u', crc32($entry['objectUuid'])));

			if ($entry['author'] !== null) {
				$event->setAuthor($entry['author']);
			}

			$manager->publish($event);
			return 1;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidiq: failed to publish activity entry for user',
				['uid' => $entry['uid'], 'subject' => $entry['subject'], 'error' => $e->getMessage()]
			);
			return 0;
		}//end try

	}//end publishTo()

	/**
	 * Resolve the Nextcloud UIDs of all members of the governing body that
	 * owns a meeting, via the canonical ParticipantResolver.
	 *
	 * Participants without a linked Nextcloud account (legacy records use the
	 * `owner` fallback set by PR #323) are skipped.
	 *
	 * @param string $meetingId The meeting uuid
	 *
	 * @return array<string> Nextcloud UIDs (possibly empty on resolver failure)
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	public function resolveMeetingAudience(string $meetingId): array {
		try {
			$resolver = $this->container->get(ParticipantResolver::class);
			$participants = $resolver->resolveMeetingParticipants(meetingId: $meetingId);

			$uids = [];
			foreach ($participants as $participant) {
				$uid = ($participant['nextcloudUserId'] ?? ($participant['owner'] ?? null));
				if (is_string($uid) === true && $uid !== '') {
					$uids[] = $uid;
				}
			}

			return array_values(array_unique($uids));
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidiq: meeting audience resolution failed',
				['meetingId' => $meetingId, 'error' => $e->getMessage()]
			);
			return [];
		}

	}//end resolveMeetingAudience()

	/**
	 * Resolve the acting session user's UID, or null outside a user session.
	 *
	 * @return string|null
	 */
	private function resolveSessionUserId(): ?string {
		try {
			$userSession = $this->container->get(\OCP\IUserSession::class);
			$user = $userSession->getUser();
			if ($user !== null) {
				return (string)$user->getUID();
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidiq: could not resolve session user for activity',
				['error' => $e->getMessage()]
			);
		}

		return null;
	}//end resolveSessionUserId()
}//end class
