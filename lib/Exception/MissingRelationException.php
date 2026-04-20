<?php

/**
 * Decidesk MissingRelationException
 *
 * Thrown when a required object relation (e.g. a linked Meeting) cannot be
 * resolved. Maps to HTTP 422 Unprocessable Entity — this is a client data
 * problem, not a server availability issue.
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
 * Thrown when a required object relation cannot be resolved.
 *
 * Distinct from \RuntimeException (service unavailable). Controllers map this to
 * HTTP 422 (Unprocessable Entity) because the object exists but its data is incomplete.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MissingRelationException extends \RuntimeException
{
}//end class
