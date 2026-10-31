<?php

/**
 * Decidiq Publication Digest Schedule
 *
 * When a subscription falls due and which events it hears of: immediately
 * after a 15 minute wait, daily at 07:00, weekly on Monday at 07:00.
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
 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;

/**
 * Pure rules of the publication digest, without I/O.
 *
 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
 */
class PublicationDigestSchedule {

	/**
	 * An immediate subscriber waits this long, so an editing session arrives as one message.
	 *
	 * @var int
	 */
	public const IMMEDIATE_WAIT_SECONDS = 900;

	/**
	 * The hour daily and weekly digests go out.
	 *
	 * @var int
	 */
	private const DIGEST_HOUR = 7;

	/**
	 * The last moment a subscription with this frequency was due, at or before now.
	 *
	 * Immediate is always due; daily is due from 07:00; weekly from Monday 07:00.
	 *
	 * @param string            $frequency immediate, daily or weekly
	 * @param DateTimeImmutable $now       The run, in the instance time zone
	 *
	 * @return DateTimeImmutable
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	public function lastDueMoment(string $frequency, DateTimeImmutable $now): DateTimeImmutable {
		if ($frequency === 'immediate') {
			return $now;
		}

		$moment = $now;
		if ($frequency === 'weekly') {
			// A modify() with a day name resets the time, so the hour is set after it.
			$moment = $moment->modify('monday this week');
		}

		$moment = $moment->setTime(self::DIGEST_HOUR, 0);

		if ($moment > $now) {
			$step = 'day';
			if ($frequency === 'weekly') {
				$step = 'week';
			}

			$moment = $moment->modify('-1 ' . $step);
		}

		return $moment;
	}//end lastDueMoment()

	/**
	 * The moment a due subscription's message covers events up to, or null when it is not due.
	 *
	 * Immediate: up to 15 minutes ago, so an editing session arrives as one
	 * message. Daily and weekly: up to the last 07:00 moment, once.
	 *
	 * @param array<string,mixed> $subscription The subscription
	 * @param DateTimeImmutable   $now          Now, in the instance's time zone
	 *
	 * @return int|null Unix time
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	public function coversUntil(array $subscription, DateTimeImmutable $now): ?int {
		$frequency = (string)($subscription['frequency'] ?? 'daily');
		if ($frequency === 'immediate') {
			return ($now->getTimestamp() - self::IMMEDIATE_WAIT_SECONDS);
		}

		$due  = $this->lastDueMoment(frequency: $frequency, now: $now)->getTimestamp();
		$last = $this->time(value: ($subscription['lastSentAt'] ?? null));
		if ($last !== null && $last >= $due) {
			return null;
		}

		return $due;
	}//end coversUntil()

	/**
	 * The events of one subscription since its last message, up to a moment.
	 *
	 * @param array<string,mixed>            $subscription The subscription
	 * @param array<int,array<string,mixed>> $events       All retained events
	 * @param int                            $until        Unix time; later events wait for the next run
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	public function matching(array $subscription, array $events, int $until): array {
		$since  = $this->time(value: ($subscription['lastSentAt'] ?? null));
		$kinds  = (array)($subscription['kinds'] ?? []);
		$bodies = (array)($subscription['governanceBodies'] ?? []);

		$matched = [];
		foreach ($events as $event) {
			$when = $this->time(value: ($event['occurredAt'] ?? null));
			if ($when === null || $when > $until || ($since !== null && $when <= $since)) {
				continue;
			}

			if (in_array(($event['kind'] ?? ''), $kinds, true) === false) {
				continue;
			}

			if ($bodies !== [] && in_array(($event['governanceBody'] ?? ''), $bodies, true) === false) {
				continue;
			}

			$matched[] = $event;
		}

		usort($matched, static fn(array $a, array $b): int => strcmp((string)$a['occurredAt'], (string)$b['occurredAt']));

		return $matched;
	}//end matching()

	/**
	 * A stored date-time as Unix time, or null.
	 *
	 * @param mixed $value The stored value
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/public-publication/spec.md#requirement-req-psd-003-subscribers-receive-matching-events-immediately-daily-or-weekly
	 */
	public function time(mixed $value): ?int {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		$time = strtotime($value);
		if ($time === false) {
			return null;
		}

		return $time;
	}//end time()
}//end class
