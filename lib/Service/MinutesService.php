<?php

/**
 * Decidiq Minutes Service
 *
 * Service for Minutes-specific operations including approval notifications.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stateless service for Minutes operations.
 *
 * Handles approval notifications and other minutes-specific workflows.
 *
 * OpenRegister lookups are delegated to MinutesContextResolver, the body's
 * members to ParticipantResolver and delivery to NotificationPreferenceService
 * (the one path that reaches a member's bell or inbox), so what remains here is
 * the approval rule itself: which roles are asked to approve, and what they are
 * told.
 *
 * @spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-6
 */
class MinutesService {

	/**
	 * The governance roles asked to approve minutes.
	 *
	 * @var array<int,string>
	 */
	private const APPROVER_ROLES = [
		'chair',
		'secretary',
	];

	/**
	 * The notification switch an approval request falls under.
	 *
	 * @var string
	 */
	private const EVENT_TYPE = 'taskAssigned';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface               $logger              The logger
	 * @param MinutesContextResolver        $context             Resolves Minutes/Meeting context
	 * @param ParticipantResolver           $participantResolver Reads the members of the meeting's body
	 * @param NotificationPreferenceService $preferences         Delivers the notice per the member's preferences
	 * @param IL10N                         $l10n                Translates the notice
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-ml-003-submit-minutes-for-review
	 */
	public function __construct(
		private LoggerInterface $logger,
		private MinutesContextResolver $context,
		private ParticipantResolver $participantResolver,
		private NotificationPreferenceService $preferences,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Send approval notifications when Minutes are submitted for approval.
	 *
	 * Tells the current chair and secretary of the meeting's own body.
	 *
	 * @param string $minutesId The Minutes ID
	 *
	 * @return int The count of notifications sent
	 *
	 * @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-ml-003-submit-minutes-for-review
	 */
	public function notifyApproversOnSubmit(string $minutesId): int {
		try {
			$minutes = $this->context->findMinutes(minutesId: $minutesId);
			if ($minutes === null) {
				$this->logger->warning("Minutes not found: $minutesId");
				return 0;
			}

			// Minutes with no meeting have no body and so no approver to tell:
			// a no-op, not a failure.
			$meetingId = $this->context->linkedMeetingId(minutes: $minutes);
			if ($meetingId === null) {
				$this->logger->info("No meeting linked to Minutes $minutesId");
				return 0;
			}

			$title = $this->l10n->t('Minutes wait for your approval: %s', [(string)($minutes['title'] ?? $minutesId)]);
			$message = $this->l10n->t('The minutes were submitted for approval.');
			$sent = 0;
			foreach ($this->approverUids(meetingId: $meetingId) as $uid) {
				$sent += $this->preferences->dispatch(
					personId: $uid,
					eventType: self::EVENT_TYPE,
					title: $title,
					message: $message,
					deepLink: '/minutes/' . $minutesId
				);
			}

			return $sent;
		} catch (Throwable $e) {
			$this->logger->error('MinutesService::notifyApproversOnSubmit failed: ' . $e->getMessage());
			return 0;
		}//end try

	}//end notifyApproversOnSubmit()

	/**
	 * The Nextcloud user ids of the current chair and secretary of the
	 * meeting's body.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return array<int, string> The user ids, each once
	 */
	private function approverUids(string $meetingId): array {
		$uids = [];
		foreach ($this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId) as $participant) {
			if (empty($participant['leftAt']) === false
				|| in_array(needle: ($participant['role'] ?? null), haystack: self::APPROVER_ROLES, strict: true) === false
			) {
				continue;
			}

			$uid = (string)($participant['nextcloudUserId'] ?? $participant['owner'] ?? '');
			if ($uid !== '') {
				$uids[$uid] = true;
			}
		}

		return array_map('strval', array_keys($uids));

	}//end approverUids()
}//end class
