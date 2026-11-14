<?php

/**
 * Decidiq Paper Stamp Refused Exception
 *
 * A paper that must carry a watermark and cannot get one, with the HTTP status
 * the controller answers: 503 when the stamping service is not installed, 422
 * when the paper itself cannot be stamped (not a PDF, encrypted, too large).
 * The paper is then not served at all, never unstamped.
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
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * A paper that cannot be stamped, and the HTTP status to answer.
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */
class PaperStampRefusedException extends \RuntimeException {

	/**
	 * The message when no stamping service is installed.
	 */
	public const NO_SERVICE = 'This paper can only be shown with a watermark, and the watermark service is not installed';

	/**
	 * The message when the paper itself cannot be stamped.
	 */
	public const UNSTAMPABLE = 'This paper can only be shown with a watermark, and a watermark could not be put on it';

	/**
	 * Constructor.
	 *
	 * @param string $message The reason, shown to the reader
	 * @param int    $status  The HTTP status to answer
	 *
	 * @return void
	 */
	public function __construct(string $message, private readonly int $status=503) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status to answer.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
