<?php

/**
 * Decidiq RoomSystemRefusal
 *
 * A room system message decidiq does not apply, with the reason for the clerk.
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
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use RuntimeException;

/**
 * Carries the plain reason and, when the seat was resolved, the member.
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
 */
class RoomSystemRefusal extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string      $message     The reason, in plain words.
	 * @param string|null $participant The member the seat resolved to, when it did.
	 */
	public function __construct(string $message, public readonly ?string $participant) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
