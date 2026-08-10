<?php
/**
 * Decidesk Activity Publisher Service
 *
 * Publishes Decidesk governance events (decision recorded/published, meeting
 * lifecycle transitions, vote initiation, resolution adoption) to the
 * Nextcloud Activity feed via OCP\Activity\IManager.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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

namespace OCA\Decidesk\Service;

use OCA\Decidesk\Activity\GovernanceSetting;
use OCA\Decidesk\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fail-soft publisher for Decidesk governance activity events.
 *
 * Every public method catches \Throwable internally: Activity is an
 * observability surface and must never abort the underlying governance
 * transition (the fail-soft observability posture).
 *
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class ActivityPublisherService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (resolves IManager, IUserSession, ParticipantResolver lazily)
     * @param LoggerInterface    $logger    The logger
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
     * @param string        $subject         Subject id (one of the DecideskProvider::SUBJECT_* constants)
     * @param string        $title           The object title rendered in the subject
     * @param string        $status          Optional status/lifecycle rendered in the subject
     * @param string        $objectType      Activity object type (e.g. 'decision', 'meeting', 'voting-round', 'resolution')
     * @param string        $objectUuid      The OpenRegister object uuid
     * @param string        $segment         Frontend route segment for the deep link (e.g. 'decisions')
     * @param array<string> $affectedUserIds Nextcloud UIDs the entry is addressed to (acting user auto-added)
     *
     * @return int The number of activity entries published
     *
     * @spec openspec/specs/nextcloud-integration/spec.md
     */
    public function publishGovernanceEvent(
        string $subject,
        string $title,
        string $status,
        string $objectType,
        string $objectUuid,
        string $segment,
        array $affectedUserIds=[],
    ): int {
        try {
            $activityManager = $this->container->get(\OCP\Activity\IManager::class);

            $author = $this->resolveSessionUserId();
            $users  = $affectedUserIds;
            if ($author !== null) {
                $users[] = $author;
            }

            $users = array_values(array_unique(array_filter($users, static fn ($uid) => is_string($uid) === true && $uid !== '')));
            if ($users === []) {
                $this->logger->debug(
                    'Decidesk: activity event has no resolvable audience, skipped',
                    ['subject' => $subject, 'objectUuid' => $objectUuid]
                );
                return 0;
            }

            $published = 0;
            foreach ($users as $uid) {
                try {
                    $event = $activityManager->generateEvent();
                    $event->setApp(Application::APP_ID)
                        ->setType(GovernanceSetting::TYPE_GOVERNANCE)
                        ->setAffectedUser($uid)
                        ->setTimestamp(time())
                        ->setSubject(
                            $subject,
                            [
                                'title'   => $title,
                                'status'  => $status,
                                'uuid'    => $objectUuid,
                                'segment' => $segment,
                            ]
                        )
                        // IEvent::setObject() requires an int id; OR uuids are
                        // strings, so the uuid travels in the subject parameters
                        // and crc32 provides a stable numeric grouping key.
                        ->setObject($objectType, (int) sprintf('%u', crc32($objectUuid)));

                    if ($author !== null) {
                        $event->setAuthor($author);
                    }

                    $activityManager->publish($event);
                    $published++;
                } catch (\Throwable $e) {
                    $this->logger->debug(
                        'Decidesk: failed to publish activity entry for user',
                        ['uid' => $uid, 'subject' => $subject, 'error' => $e->getMessage()]
                    );
                }//end try
            }//end foreach

            return $published;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk: activity publication skipped',
                ['subject' => $subject, 'objectUuid' => $objectUuid, 'error' => $e->getMessage()]
            );
            return 0;
        }//end try

    }//end publishGovernanceEvent()

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
    public function resolveMeetingAudience(string $meetingId): array
    {
        try {
            $resolver     = $this->container->get(ParticipantResolver::class);
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
                'Decidesk: meeting audience resolution failed',
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
    private function resolveSessionUserId(): ?string
    {
        try {
            $userSession = $this->container->get(\OCP\IUserSession::class);
            $user        = $userSession->getUser();
            if ($user !== null) {
                return (string) $user->getUID();
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Decidesk: could not resolve session user for activity',
                ['error' => $e->getMessage()]
            );
        }

        return null;

    }//end resolveSessionUserId()
}//end class
