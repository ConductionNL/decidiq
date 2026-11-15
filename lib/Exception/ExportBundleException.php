<?php

/**
 * Decidiq ExportBundleException
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
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

use RuntimeException;
use Throwable;

/**
 * A refused export, with the HTTP status the refusal deserves and a sentence
 * the clerk can act on.
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
 */
class ExportBundleException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string         $message  What was refused.
	 * @param int            $status   The HTTP status.
	 * @param Throwable|null $previous The cause.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 */
	public function __construct(string $message, private readonly int $status=422, ?Throwable $previous=null) {
		parent::__construct(message: $message, code: $status, previous: $previous);
	}//end __construct()

	/**
	 * The HTTP status.
	 *
	 * @spec openspec/specs/motion-management/spec.md#requirement-req-mxp-001-motions-export-as-one-pdf-with-their-attachments
	 *
	 * @return int
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
