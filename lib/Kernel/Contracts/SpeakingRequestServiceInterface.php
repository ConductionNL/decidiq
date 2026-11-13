<?php

/**
 * Contract of SpeakingRequestService
 *
 * The public surface of SpeakingRequestService that other components call, so they depend
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

use OCA\Decidiq\Exception\SpeakingRequestRefusedException;

/**
 * Contract of SpeakingRequestService.
 *
 * @category Kernel
 * @package  OCA\Decidiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SpeakingRequestServiceInterface {
	/**
	 * The meeting a speaking request is for, or an empty string when it does not exist.
	 *
	 * @param string $requestId The speaking request.
	 *
	 * @return string
	 */
	public function meetingOf(string $requestId): string;

	/**
	 * Register a request to speak, checked against the body's speaking policy.
	 *
	 * @param array<string, mixed> $input    meeting, agendaItem, governanceBody, topic and contactDetails.
	 * @param bool                 $override Whether the secretariat registers past the deadline.
	 *
	 * @return array<string, mixed> The stored request, with its id.
	 *
	 * @throws SpeakingRequestRefusedException When the policy refuses it.
	 */
	public function register(array $input, bool $override=false): array;

	/**
	 * Approve a registered request.
	 *
	 * @param string   $requestId The speaking request.
	 * @param int|null $minutes   The speaking time, or null for the policy's default.
	 * @param int|null $position  The order, or null for the next free place.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws SpeakingRequestRefusedException When it is not registered.
	 */
	public function approve(string $requestId, ?int $minutes=null, ?int $position=null): array;

	/**
	 * Reject a registered request with a reason.
	 *
	 * @param string $requestId The speaking request.
	 * @param string $reason    Why it is rejected.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws SpeakingRequestRefusedException When the reason is empty or it is not registered.
	 */
	public function reject(string $requestId, string $reason): array;

	/**
	 * Move a registered request to another meeting and agenda item.
	 *
	 * @param string      $requestId    The speaking request.
	 * @param string      $meetingId    The new meeting.
	 * @param string|null $agendaItemId The new agenda item, or null for the meeting as a whole.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws SpeakingRequestRefusedException When it is not registered or the target is not usable.
	 */
	public function refer(string $requestId, string $meetingId, ?string $agendaItemId=null): array;
}//end interface
