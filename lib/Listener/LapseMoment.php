<?php

/**
 * Where and when a confidentiality restriction lapses.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use DateTimeImmutable;

/**
 * The meeting, agenda item and moment of a lapse, and whether the meeting just closed.
 *
 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
 */
final class LapseMoment {

	/**
	 * Constructor.
	 *
	 * @param string            $meetingId The meeting, or '' when unknown.
	 * @param DateTimeImmutable $now       When it lapses.
	 * @param bool              $atClose   Whether the meeting just closed (else: the item was just not ratified).
	 * @param string            $itemId    The agenda item that put the restriction to ratification.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $meetingId,
		public readonly DateTimeImmutable $now,
		public readonly bool $atClose,
		public readonly string $itemId = '',
	) {
	}//end __construct()

	/**
	 * The same moment, for one agenda item.
	 *
	 * @param string $itemId The agenda item.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/confidentiality-term-and-lapse/specs/embargo-geheimhouding/spec.md#requirement-req-emb-021-a-restriction-the-ratifying-body-did-not-ratify-lapses
	 */
	public function forItem(string $itemId): self {
		return new self(meetingId: $this->meetingId, now: $this->now, atClose: $this->atClose, itemId: $itemId);
	}//end forItem()
}//end class
