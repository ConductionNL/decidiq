<?php

/**
 * Decidiq Current Agenda Item Service
 *
 * Saves the agenda item the chair is dealing with now on the meeting, so
 * members' live screens and the room screen follow it.
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
 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-001-everyone-follows-the-current-item
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The live meeting's current agenda item.
 *
 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-001-everyone-follows-the-current-item
 */
class CurrentAgendaItemService {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-001-everyone-follows-the-current-item
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Save the agenda item the chair is dealing with now on the meeting.
	 *
	 * @param string $meetingId UUID of the Meeting
	 * @param string $itemId UUID of an agenda item of that meeting
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the item is not on this meeting.
	 *
	 * @spec openspec/specs/agenda-live-management/spec.md#requirement-req-lsc-001-everyone-follows-the-current-item
	 */
	public function setCurrentItem(string $meetingId, string $itemId): void {
		$entity = $this->objectService->find(id: $itemId, register: 'decidiq', schema: 'agenda-item');
		$item = [];
		if ($entity !== null) {
			$item = $entity->jsonSerialize();
		}

		if ((string)($item['meeting'] ?? '') !== $meetingId) {
			throw new InvalidArgumentException('This agenda item is not on this meeting.');
		}

		$this->objectService->patchObject(
			objectId: $meetingId,
			data: ['currentAgendaItem' => $itemId],
			register: 'decidiq',
			schema: 'meeting',
		);
	}//end setCurrentItem()
}//end class
