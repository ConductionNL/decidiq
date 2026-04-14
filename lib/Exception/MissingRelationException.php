<?php

/**
 * Decidesk MissingRelationException
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
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
 * Thrown when a required relation (e.g., a linked Meeting) is missing from an object.
 *
 * Distinct from \RuntimeException (service unavailable). Controllers map this to
 * HTTP 422 (Unprocessable Entity) because the object exists but its data is incomplete.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-1
 */
class MissingRelationException extends \RuntimeException
{
}//end class
