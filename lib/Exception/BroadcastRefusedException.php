<?php

/**
 * Decidiq Broadcast Refused Exception
 *
 * A broadcast action that may not go ahead, with the HTTP status the
 * controller answers: 404 for a broadcast that does not exist, 409 without a
 * connected streaming service or for a move the lifecycle does not allow, 422
 * for a meeting that is not public or an unknown test result, 502 when the
 * streaming service refuses.
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
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * A refused broadcast action and its HTTP status.
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
 */
class BroadcastRefusedException extends \RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message The reason, shown to the clerk
	 * @param int $status The HTTP status to answer
	 *
	 * @return void
	 */
	public function __construct(string $message, private readonly int $status=409) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status to answer.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
