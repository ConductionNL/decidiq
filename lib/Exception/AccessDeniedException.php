<?php

/**
 * Decidesk AccessDeniedException
 *
 * @category Exception
 * @package  OCA\Decidesk\Exception
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidesk\Exception;

/**
 * Thrown when an authenticated user attempts to access an object they do not own.
 *
 * Controllers map this to HTTP 403 Forbidden.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class AccessDeniedException extends \RuntimeException
{
}//end class
