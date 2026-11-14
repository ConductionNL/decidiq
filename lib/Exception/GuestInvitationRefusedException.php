<?php

/**
 * Decidiq Guest Invitation Refused Exception
 *
 * A guest invitation that cannot be sent as asked: the caller did not
 * organise the meeting (403), the meeting does not exist (404), or the
 * meeting belongs to a governing body, the email is not an address, or the
 * papers could not be shared (422). The status travels with the exception.
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
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * A guest invitation refused, carrying the HTTP status to answer with.
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
class GuestInvitationRefusedException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message The reason, shown to the organiser
	 * @param int    $status  The HTTP status (403, 404 or 422)
	 */
	public function __construct(string $message, private readonly int $status=422) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status to answer with.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
