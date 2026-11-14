<?php

/**
 * Decidiq Paper Stamper
 *
 * Whether a paper must carry a watermark, and the stamped copy for one reader:
 * "<display name>, <Y-m-d H:i>, <watermark text>" across and at the foot of
 * every page, from the day cache when the reader opened it before today.
 * Used by the paper view and by the meeting package, so both stamp alike.
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
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-003-the-meeting-package-is-stamped-for-the-person-who-takes-it
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\PaperStampRefusedException;
use OCA\Decidiq\Support\FilinqStamp;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;

/**
 * Stamps papers for a reader when the meeting's policy asks for it.
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */
class PaperStamper {

	/**
	 * Constructor.
	 *
	 * @param PaperWatermarkPolicy $policy   Whether a paper must be stamped, and the line
	 * @param FilinqStamp          $stamp    filinq's stamping service
	 * @param WatermarkCache       $cache    The day cache
	 * @param ITimeFactory         $time     The clock
	 * @param IDateTimeZone        $timeZone The instance's time zone
	 */
	public function __construct(
		private readonly PaperWatermarkPolicy $policy,
		private readonly FilinqStamp $stamp,
		private readonly WatermarkCache $cache,
		private readonly ITimeFactory $time,
		private readonly IDateTimeZone $timeZone,
	) {
	}//end __construct()

	/**
	 * Whether a paper of this meeting (and agenda item) must be stamped.
	 *
	 * @param array<string, mixed> $meeting      The meeting
	 * @param string|null          $agendaItemId The agenda item that holds the paper, if any
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function mustStamp(array $meeting, ?string $agendaItemId=null): bool {
		return $this->policy->mustStamp(meeting: $meeting, agendaItemId: $agendaItemId);
	}//end mustStamp()

	/**
	 * The paper stamped for one reader.
	 *
	 * @param array<string, mixed>                                      $meeting The meeting
	 * @param array{fileId: int, etag: string, content: string}         $paper   The paper
	 * @param array{uid: string, displayName: string}                   $reader  The reader
	 *
	 * @return string The stamped PDF
	 *
	 * @throws PaperStampRefusedException 503 without a stamping service, 422 when this paper cannot be stamped.
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-003-the-meeting-package-is-stamped-for-the-person-who-takes-it
	 */
	public function stamped(array $meeting, array $paper, array $reader): string {
		$now  = $this->time->now()->setTimezone($this->timeZone->getTimeZone());
		$name = $this->cache->name(fileId: $paper['fileId'], etag: $paper['etag'], uid: $reader['uid'], day: $now->format('Y-m-d'));

		$cached = $this->cache->get(name: $name);
		if ($cached !== null) {
			return $cached;
		}

		$text    = $this->policy->stampText(meeting: $meeting, displayName: $reader['displayName'], openedAt: $now);
		$stamped = $this->stamp->stamp(pdf: $paper['content'], text: $text);
		if ($stamped === null) {
			throw new PaperStampRefusedException(message: PaperStampRefusedException::NO_SERVICE, status: 503);
		}

		$this->cache->put(name: $name, content: $stamped);
		return $stamped;
	}//end stamped()
}//end class
