<?php

/**
 * Decidiq Substitution Refused Exception
 *
 * A mandate swap the meeting does not allow: a vote of the meeting is open, the
 * outgoing participant is not a member or is already substituted out, the
 * substitute has a voting role or already holds a seat, or the caller does not
 * preside over the meeting. It carries the HTTP status the controller answers.
 *
 * @category Exception
 * @package  OCA\Decidiq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

use RuntimeException;

/**
 * A refused start or end of a mandate substitution, with its HTTP status.
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
 */
class SubstitutionRefusedException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message The reason, shown to the chair or secretary
	 * @param int    $status  The HTTP status: 400, 403, 404 or 409
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
	 */
	public function __construct(
		string $message,
		private readonly int $status,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status the refusal answers with.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-003-a-swap-is-refused-when-it-would-change-a-vote-in-progress-or-break-the-seat-plan
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
