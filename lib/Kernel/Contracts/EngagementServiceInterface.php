<?php

/**
 * Contract of EngagementService
 *
 * The public surface of EngagementService that other components call, so they depend
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
 * Contract of EngagementService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EngagementServiceInterface {

	/**
	 * Capture an engagement event (speech, question, or topic suggestion).
	 *
	 * If an EngagementRecord exists for the (meeting, participant) tuple,
	 * the new event is appended. Otherwise a new record is created.
	 *
	 * @param string $meetingId UUID of the Meeting object
	 * @param string $participant Participant UUID
	 * @param string $eventType One of 'speech', 'question', 'topic'
	 * @param array<string, mixed> $eventData Event payload
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When event type is unknown
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	public function captureEngagement(
		string $meetingId,
		string $participant,
		string $eventType,
		array $eventData,
	): array;

	/**
	 * List EngagementRecords for a meeting.
	 *
	 * @param string $meetingId Meeting UUID
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-05-11-p4-collaboration/tasks.md#task-8.1
	 */
	public function findEngagementForMeeting(string $meetingId): array;
}//end interface
