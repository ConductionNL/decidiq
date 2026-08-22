<?php

/**
 * Decidiq Voting-Opened Notifier
 *
 * The announcement side effects of opening a voting round: the governance
 * activity-feed entry and the preference-aware "pending vote" notifications.
 * Both are entirely fail-soft — an announcement never breaks the round that
 * was just opened.
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
 * @spec openspec/specs/user-settings/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fail-soft announcements for a freshly opened voting round.
 *
 * Notifications are routed through the preference-aware dispatcher
 * (NotificationPreferenceService::dispatch), which honours the per-event
 * toggle, the delivery channels and the absence-delegation fan-out.
 *
 * @spec openspec/specs/user-settings/spec.md
 * @spec openspec/specs/nextcloud-integration/spec.md
 */
class VotingOpenedNotifier {
	/**
	 * Constructor for VotingOpenedNotifier.
	 *
	 * @param ContainerInterface $container The DI container (lazy service lookups)
	 * @param LoggerInterface $logger The logger
	 * @param ParticipantResolver $participantResolver Participant resolver for the meeting roster
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ParticipantResolver $participantResolver,
	) {
	}//end __construct()

	/**
	 * Announce a freshly opened voting round: activity feed first, then the
	 * preference-aware participant notifications.
	 *
	 * @param array<string,mixed> $round The persisted voting round
	 * @param string $motionId The motion UUID (amendment UUID for amendment rounds)
	 * @param string $meetingId The meeting UUID
	 * @param string|null $closedAt The voting deadline (ATOM), when preset
	 * @param string $subjectType 'motion' or 'amendment'
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	public function announce(array $round, string $motionId, string $meetingId, ?string $closedAt, string $subjectType): void {
		$this->publishActivity(round: $round);
		$this->notifyParticipants(
			motionId: $motionId,
			meetingId: $meetingId,
			closedAt: $closedAt,
			subjectType: $subjectType
		);

	}//end announce()

	/**
	 * Activity feed (fail-soft): a voting round opened.
	 *
	 * @param array<string,mixed> $round The persisted voting round
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-integration/spec.md
	 */
	private function publishActivity(array $round): void {
		try {
			$this->container->get(\OCA\Decidiq\Service\ActivityPublisherService::class)->publishGovernanceEvent(
				subject: \OCA\Decidiq\Activity\DecidiqProvider::SUBJECT_VOTE_INITIATED,
				title: (string)($round['votingMethod'] ?? 'voting round'),
				status: 'open',
				objectType: 'voting-round',
				objectUuid: (string)($round['id'] ?? ($round['uuid'] ?? '')),
				segment: 'voting-rounds'
			);
		} catch (\Throwable $activityError) {
			$this->logger->debug('Decidiq: activity publish skipped', ['error' => $activityError->getMessage()]);
		}

	}//end publishActivity()

	/**
	 * Dispatch "pending vote" notifications to the meeting's active roster.
	 *
	 * @param string $motionId The motion UUID (amendment UUID for amendment rounds)
	 * @param string $meetingId The meeting UUID
	 * @param string|null $closedAt The voting deadline (ATOM), when preset
	 * @param string $subjectType 'motion' or 'amendment'
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function notifyParticipants(string $motionId, string $meetingId, ?string $closedAt, string $subjectType): void {
		try {
			$prefService = $this->container->get(NotificationPreferenceService::class);
			if ($prefService instanceof NotificationPreferenceService === false) {
				return;
			}

			$title = $this->subjectTitle(motionId: $motionId, subjectType: $subjectType);
			$message = sprintf(
				'A new vote is open in your body (meeting %s). Voting deadline: %s.',
				$meetingId,
				$this->deadlineLabel(closedAt: $closedAt)
			);

			$participants = $this->participantResolver->resolveMeetingParticipants(meetingId: $meetingId);
			foreach ($participants as $participant) {
				$ncUid = (string)($participant['nextcloudUserId'] ?? '');
				if (($participant['leftAt'] ?? null) !== null || $ncUid === '') {
					continue;
				}

				$prefService->dispatch(
					personId: $ncUid,
					eventType: 'votingOpened',
					title: 'Pending vote: ' . $title,
					message: $message,
					deepLink: '/motions/' . $motionId
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: votingOpened notification dispatch failed', ['error' => $e->getMessage()]);
		}//end try

	}//end notifyParticipants()

	/**
	 * Title of the motion or amendment being voted on, with a safe fallback.
	 *
	 * @param string $motionId The subject UUID
	 * @param string $subjectType 'motion' or 'amendment' — selects the lookup schema
	 *
	 * @return string The subject title
	 *
	 * @spec openspec/specs/motion-amendment/spec.md
	 */
	private function subjectTitle(string $motionId, string $subjectType): string {
		$lookupSchema = 'motion';
		$fallback = 'Motion';
		if ($subjectType === 'amendment') {
			$lookupSchema = 'amendment';
			$fallback = 'Amendment';
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$motionEntity = $objectService->find(id: $motionId, register: 'decidesk', schema: $lookupSchema);
			if ($motionEntity === null) {
				return $fallback;
			}

			$motion = $motionEntity->jsonSerialize();

			return (string)($motion['title'] ?? $fallback);
		} catch (\Throwable $e) {
			$this->logger->debug('Decidiq: motion lookup for vote notification failed', ['error' => $e->getMessage()]);
			return $fallback;
		}//end try

	}//end subjectTitle()

	/**
	 * Human-readable voting deadline.
	 *
	 * @param string|null $closedAt The voting deadline (ATOM), when preset
	 *
	 * @return string The deadline, or the no-deadline label
	 *
	 * @spec openspec/specs/user-settings/spec.md
	 */
	private function deadlineLabel(?string $closedAt): string {
		if ($closedAt !== null && $closedAt !== '') {
			return $closedAt;
		}

		return 'no deadline set';
	}//end deadlineLabel()
}//end class
