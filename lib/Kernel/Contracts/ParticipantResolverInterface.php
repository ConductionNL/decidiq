<?php

/**
 * Contract of ParticipantResolver
 *
 * The public surface of ParticipantResolver that other components call, so they depend
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

/**
 * Contract of ParticipantResolver.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ParticipantResolverInterface {

	/**
	 * Check whether a Nextcloud UID holds one of the given roles in a meeting.
	 *
	 * Useful for access-control checks that require chair, secretary, or other
	 * role membership (e.g. requireChairOrAdmin in controllers).
	 *
	 * @param string $meetingId Meeting UUID
	 * @param string $nextcloudUid Nextcloud user ID to check
	 * @param array<string> $roles Accepted roles (e.g. ['chair', 'secretary'])
	 *
	 * @return bool True when the user holds at least one of the given roles
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2
	 */
	public function hasRole(string $meetingId, string $nextcloudUid, array $roles): bool;

	/**
	 * Check whether a Nextcloud UID is a participant in a meeting.
	 *
	 * Uses nextcloudUserId field on participant (canonical, set by PR #323).
	 * Falls back to legacy `owner` field for pre-migration records.
	 *
	 * @param string $meetingId Meeting UUID
	 * @param string $nextcloudUid Nextcloud user ID to check
	 *
	 * @return bool True when the user is a participant in this meeting
	 *
	 * @spec openspec/changes/archive/2026-05-11-p2-motion-and-voting/tasks.md#task-2
	 */
	public function isParticipant(string $meetingId, string $nextcloudUid): bool;
}//end interface
