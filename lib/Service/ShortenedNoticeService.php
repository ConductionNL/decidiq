<?php

/**
 * Shortened Notice Service
 *
 * The deviation an emergency convocation records when it is sent below the
 * body's regular notice period: only for an `extraordinary` meeting, only
 * for a body with an urgency policy, never below that policy's
 * minimumNoticeFloorHours, and always with a reason (by default the urgency
 * reason of the urgent decision on that meeting).
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
 * @spec openspec/changes/urgent-decision-procedure/specs/meeting-management/spec.md#requirement-shortened-notice-deviation-recording-for-emergency-convocations
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Decidiq\Kernel\Contracts\ProcessTemplateServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Checks and builds the shortened-notice deviation of an emergency convocation.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/meeting-management/spec.md#requirement-shortened-notice-deviation-recording-for-emergency-convocations
 */
class ShortenedNoticeService {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService The urgent decisions on the meeting.
	 * @param ProcessTemplateServiceInterface $templates     The body's urgency policy.
	 * @param ITimeFactory                    $timeFactory   The send time.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ProcessTemplateServiceInterface $templates,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * The deviation fields to store with the convocation.
	 *
	 * @param string               $meetingId The meeting.
	 * @param array<string, mixed> $meeting   The meeting as stored.
	 * @param string               $reason    The deviation reason given; empty for the urgency reason.
	 *
	 * @return array{shortenedNotice: true, actualNoticeHours: int, noticeDeviationReason: string}
	 *
	 * @throws InvalidArgumentException When the deviation is not allowed; nothing is sent then.
	 *
	 * @spec openspec/changes/urgent-decision-procedure/specs/meeting-management/spec.md#requirement-shortened-notice-deviation-recording-for-emergency-convocations
	 */
	public function deviationFor(string $meetingId, array $meeting, string $reason): array {
		if (($meeting['meetingType'] ?? '') !== 'extraordinary') {
			throw new InvalidArgumentException('Only an extraordinary meeting can be convened at shortened notice.');
		}

		$body   = $meeting['governanceBody'] ?? null;
		$policy = null;
		if (is_string($body) === true && $body !== '') {
			$policy = $this->templates->resolveUrgencyPolicyForBody(governanceBodyId: $body);
		}

		if ($policy === null) {
			throw new InvalidArgumentException('The urgent procedure is not configured for this body, so its notice cannot be shortened.');
		}

		$hours = $this->hoursUntil(meeting: $meeting);
		$floor = (int)($policy['minimumNoticeFloorHours'] ?? 0);
		if ($hours === null || $hours < $floor) {
			throw new InvalidArgumentException(sprintf('The convocation was not sent: the notice may not be shorter than %d hours.', $floor));
		}

		$reason = trim($reason);
		if ($reason === '') {
			$reason = $this->urgencyReasonOn(meetingId: $meetingId);
		}

		if ($reason === '') {
			throw new InvalidArgumentException('noticeDeviationReason is required for a shortened notice.');
		}

		return ['shortenedNotice' => true, 'actualNoticeHours' => $hours, 'noticeDeviationReason' => $reason];
	}//end deviationFor()

	/**
	 * Whole hours from now until the meeting starts, or null without a start.
	 *
	 * @param array<string, mixed> $meeting The meeting.
	 *
	 * @return int|null
	 */
	private function hoursUntil(array $meeting): ?int {
		$start = (string)($meeting['scheduledDate'] ?? '');
		if ($start === '') {
			return null;
		}

		try {
			$at = new DateTimeImmutable($start);
		} catch (Throwable) {
			return null;
		}

		return intdiv(($at->getTimestamp() - $this->timeFactory->getDateTime()->getTimestamp()), 3600);
	}//end hoursUntil()

	/**
	 * The urgency reason of the first urgent decision on the meeting, or ''.
	 *
	 * @param string $meetingId The meeting.
	 *
	 * @return string
	 */
	private function urgencyReasonOn(string $meetingId): string {
		try {
			$decisions = $this->objectService->findAll(
				['filters' => ['register' => 'decidiq', 'schema' => 'decision', 'meeting' => $meetingId, 'isUrgent' => true]]
			);
		} catch (Throwable) {
			return '';
		}

		foreach ($decisions as $decision) {
			$row = $decision;
			if (is_object($decision) === true && method_exists($decision, 'jsonSerialize') === true) {
				$row = $decision->jsonSerialize();
			}

			$reason = trim((string)(((array)$row)['urgencyReason'] ?? ''));
			if ($reason !== '') {
				return $reason;
			}
		}

		return '';
	}//end urgencyReasonOn()
}//end class
