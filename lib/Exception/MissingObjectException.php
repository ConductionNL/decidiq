<?php

/**
 * Decidesk MissingObjectException
 *
 * @category Exception
 * @package  OCA\Decidesk\Exception
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Exception;

/**
 * Thrown when a requested object cannot be found in OpenRegister.
 *
 * Distinct from \InvalidArgumentException (invalid input) and \RuntimeException
 * (service unavailable). Controllers map this to HTTP 404.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MissingObjectException extends \InvalidArgumentException
{
}//end class
