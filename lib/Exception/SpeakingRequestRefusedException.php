<?php

/**
 * A speaking request, or a moderation of one, that the policy or the state refuses.
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
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-004-registration-api-enforces-policy-and-closes-at-the-deadline
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * Carries the message the caller sees and the HTTP status that goes with it.
 */
class SpeakingRequestRefusedException extends \RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message The reason, shown to the caller.
	 * @param int    $status  The HTTP status.
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-004-registration-api-enforces-policy-and-closes-at-the-deadline
	 */
	public function __construct(string $message, private readonly int $status=422) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status of the refusal.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-004-registration-api-enforces-policy-and-closes-at-the-deadline
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
