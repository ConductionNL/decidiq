<?php

/**
 * Decidiq CaseSystemException
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
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

use RuntimeException;
use Throwable;

/**
 * A refused case system exchange, with the HTTP status the refusal deserves
 * and a sentence the griffier can act on.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
 */
class CaseSystemException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string         $message  What was refused.
	 * @param int            $status   The HTTP status.
	 * @param Throwable|null $previous The cause.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 */
	public function __construct(string $message, private readonly int $status=422, ?Throwable $previous=null) {
		parent::__construct(message: $message, code: $status, previous: $previous);
	}//end __construct()

	/**
	 * The HTTP status.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 *
	 * @return int
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
