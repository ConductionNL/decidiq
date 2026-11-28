<?php

/**
 * Test stub of filinq's ConversionFailedException (filinq development,
 * lib/Exception/ConversionFailedException.php). decidiq reaches filinq by
 * string class name through FleetAppId, so it stays installable without
 * filinq; this stub loads only when filinq's real class is absent.
 *
 * @category Test
 * @package  OCA\Filinq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Filinq\Exception;

/**
 * Thrown by filinq when no backend in the cascade could convert a file.
 */
class ConversionFailedException extends \RuntimeException {
}
