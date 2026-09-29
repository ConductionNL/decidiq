<?php

/**
 * Decidiq Agenda Invitation
 *
 * The invitation text and calendar file members get when an agenda is
 * published (change agenda-publish-and-invite-members).
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
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCP\IL10N;
use Throwable;

/**
 * Builds the invitation: the date, the place and the agenda items as text,
 * and the same meeting as an RFC 5545 calendar file.
 *
 * Pure: no I/O. The meeting's own time offset is kept in the text; the
 * calendar file carries UTC.
 *
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
 */
final class AgendaInvitation {

	/**
	 * The item titles in agenda order.
	 *
	 * @param iterable<mixed> $items Agenda items (arrays or entities)
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
	 */
	public function orderedTitles(iterable $items): array {
		$rows = [];
		foreach ($items as $item) {
			if (is_object($item) === true && method_exists($item, 'getObject') === true) {
				$item = $item->getObject();
			}

			if (is_array($item) === true) {
				$rows[] = $item;
			}
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => ((int)($left['orderNumber'] ?? PHP_INT_MAX)) <=> ((int)($right['orderNumber'] ?? PHP_INT_MAX))
		);

		return array_map(static fn (array $row): string => (string)($row['title'] ?? ''), $rows);

	}//end orderedTitles()

	/**
	 * The invitation text: when, where, and the numbered agenda.
	 *
	 * @param IL10N                $l10n       Translations
	 * @param array<string, mixed> $meeting    The meeting
	 * @param array<int, string>   $itemTitles The items in agenda order
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
	 */
	public function message(IL10N $l10n, array $meeting, array $itemTitles): string {
		$lines = [];
		$start = $this->parse(value: ($meeting['scheduledDate'] ?? null));
		if ($start !== null) {
			$lines[] = $l10n->t('When: %s', [$start->format('Y-m-d H:i')]);
		}

		$place = trim((string)($meeting['location'] ?? ''));
		if ($place !== '') {
			$lines[] = $l10n->t('Where: %s', [$place]);
		}

		$lines[] = '';
		$lines[] = $l10n->t('Agenda:');
		foreach (array_values($itemTitles) as $index => $title) {
			$lines[] = ($index + 1) . '. ' . $title;
		}

		$lines[] = '';
		$lines[] = $l10n->t('Open the meeting in Decidiq to see the agenda.');

		return implode("\n", $lines);

	}//end message()

	/**
	 * The meeting as a calendar file with one event.
	 *
	 * @param string               $meetingId  The meeting uuid
	 * @param array<string, mixed> $meeting    The meeting
	 * @param array<int, string>   $itemTitles The items in agenda order
	 *
	 * @return string RFC 5545 text, CRLF line ends, folded at 75 octets
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members
	 */
	public function calendarFile(string $meetingId, array $meeting, array $itemTitles): string {
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Conduction//Decidiq//NL',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:' . $meetingId . '@decidiq',
			'DTSTAMP:' . $this->utc(time: new DateTimeImmutable('now')),
		];

		$start = $this->parse(value: ($meeting['scheduledDate'] ?? null));
		if ($start !== null) {
			$lines[] = 'DTSTART:' . $this->utc(time: $start);
			$end = ($this->parse(value: ($meeting['endDate'] ?? null)) ?? $start->modify('+1 hour'));
			$lines[] = 'DTEND:' . $this->utc(time: $end);
		}

		$lines[] = 'SUMMARY:' . $this->text(value: (string)($meeting['title'] ?? ''));
		$place = trim((string)($meeting['location'] ?? ''));
		if ($place !== '') {
			$lines[] = 'LOCATION:' . $this->text(value: $place);
		}

		$numbered = [];
		foreach (array_values($itemTitles) as $index => $title) {
			$numbered[] = ($index + 1) . '. ' . $title;
		}

		$lines[] = 'DESCRIPTION:' . $this->text(value: implode("\n", $numbered));
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode('', array_map(fn (string $line): string => $this->fold(line: $line) . "\r\n", $lines));

	}//end calendarFile()

	/**
	 * Parse a date-time, or null.
	 *
	 * @param mixed $value The stored value
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parse(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}

	}//end parse()

	/**
	 * A time in UTC basic format.
	 *
	 * @param DateTimeImmutable $time The time
	 *
	 * @return string
	 */
	private function utc(DateTimeImmutable $time): string {
		return $time->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

	}//end utc()

	/**
	 * Escape a TEXT value (RFC 5545 section 3.3.11).
	 *
	 * @param string $value The text
	 *
	 * @return string
	 */
	private function text(string $value): string {
		return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $value);

	}//end text()

	/**
	 * Fold a content line at 75 octets without splitting a UTF-8 character.
	 *
	 * @param string $line The line
	 *
	 * @return string
	 */
	private function fold(string $line): string {
		$out = '';
		$current = '';
		$chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
		if (is_array($chars) === false) {
			$chars = str_split($line);
		}

		foreach ($chars as $char) {
			if (strlen($current . $char) > 75) {
				$out .= $current . "\r\n";
				$current = ' ';
			}

			$current .= $char;
		}

		return $out . $current;

	}//end fold()
}//end class
