<?php

/**
 * Decidiq Date Value Parser
 *
 * Parses a stored date value into a DateTimeImmutable, or null when the value
 * is not a non-empty string PHP can parse.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use Throwable;

/**
 * Lenient parsing of stored date values.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
 */
final class DateValueParser {
	/**
	 * Parse a stored date value.
	 *
	 * @param mixed $value The stored value
	 *
	 * @return DateTimeImmutable|null The parsed value, or null when absent or unparseable
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-backend-service-access
	 */
	public function parse(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}

	}//end parse()
}//end class
