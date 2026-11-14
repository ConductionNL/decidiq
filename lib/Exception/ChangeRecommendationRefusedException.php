<?php

/**
 * Decidiq Change Recommendation Refused Exception
 *
 * An accept or reject of a change recommendation that may not go ahead, with
 * the HTTP status the controller answers: 404 for a recommendation or motion
 * that does not exist, 409 when it is already decided, the motion can no
 * longer change, or the passage no longer occurs in the motion text, 422 for
 * a recommendation that names no passage.
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
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-005-a-recommendation-whose-passage-has-changed-cannot-be-accepted
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * A refused recommendation decision and its HTTP status.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-005-a-recommendation-whose-passage-has-changed-cannot-be-accepted
 */
class ChangeRecommendationRefusedException extends \RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message The reason, shown to the chair or secretary
	 * @param int    $status  The HTTP status to answer
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
	 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-005-a-recommendation-whose-passage-has-changed-cannot-be-accepted
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
