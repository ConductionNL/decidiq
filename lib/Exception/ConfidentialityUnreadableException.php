<?php

/**
 * Decidiq ConfidentialityUnreadableException
 *
 * @category Exception
 * @package  OCA\Decidiq\Exception
 *
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-002-a-confidential-item-never-reaches-the-public
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Exception;

/**
 * Thrown when the confidentiality restrictions of an agenda cannot be read,
 * so publishing it could put a confidential item in front of the public.
 * Controllers map this to HTTP 503 with the message.
 *
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-002-a-confidential-item-never-reaches-the-public
 */
class ConfidentialityUnreadableException extends \RuntimeException {
}//end class
