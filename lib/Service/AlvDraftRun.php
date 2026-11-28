<?php

/**
 * Decidiq AlvDraftRun
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
 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

/**
 * One ALV minutes draft being rendered: the minutes and their meeting, the
 * meeting's agenda items, and how many members there are and are counted as
 * present. Built by ALVMinutesService::generateALVDraft and never handed out.
 *
 * @spec openspec/changes/archive/2026-05-11-p2-minutes-and-decisions-core-t3/tasks.md#task-3.1
 */
final class AlvDraftRun {

	/**
	 * The meeting's agenda items, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $agendaItems = [];

	/**
	 * The current members of the meeting's body.
	 *
	 * @var int
	 */
	public int $memberCount = 0;

	/**
	 * The members counted as present.
	 *
	 * @var int
	 */
	public int $presentCount = 0;

	/**
	 * Start a draft.
	 *
	 * @param array<string, mixed> $minutes The Minutes data.
	 * @param array<string, mixed> $meeting The Meeting data.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $minutes,
		public readonly array $meeting,
	) {
	}//end __construct()
}//end class
