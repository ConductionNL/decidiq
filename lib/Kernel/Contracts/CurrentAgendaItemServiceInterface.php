<?php

/**
 * Contract of CurrentAgendaItemService
 *
 * The public surface of CurrentAgendaItemService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Decidiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of CurrentAgendaItemService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CurrentAgendaItemServiceInterface {

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
	public function setCurrentItem(string $meetingId, string $itemId): void;
}//end interface
