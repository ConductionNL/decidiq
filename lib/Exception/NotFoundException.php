<?php
/**
 * NotFoundException — thrown when a requested resource cannot be found.
 *
 * @category Exception
 * @package  OCA\Decidesk\Exception
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Decidesk\Exception;

use RuntimeException;

/**
 * Thrown when a requested resource cannot be found.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-1.2
 */
class NotFoundException extends RuntimeException
{
}//end class
