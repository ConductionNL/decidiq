<?php

/**
 * Decidiq RecordedVotesRefusedException: a recorded vote list refused as a whole.
 *
 * @category Exception
 * @package  OCA\Decidiq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-003-the-recorded-list-is-refused-as-a-whole-when-any-entry-is-wrong
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

use RuntimeException;

/**
 * Carries the HTTP status and the participant ids the refusal names.
 *
 * @spec openspec/changes/voting-named-paper-vote-entry/specs/vote-casting/spec.md#requirement-req-npv-003-the-recorded-list-is-refused-as-a-whole-when-any-entry-is-wrong
 */
class RecordedVotesRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string   $message        The refusal in words.
	 * @param int      $status         400 for a wrong list or round, 409 for a member's own vote, 404 for an unknown round.
	 * @param string[] $participantIds The entries the refusal is about.
	 */
	public function __construct(
		string $message,
		public readonly int $status = 400,
		public readonly array $participantIds = [],
	) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
