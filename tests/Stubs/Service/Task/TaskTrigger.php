<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Test stub for OCA\OpenRegister\Service\Task\TaskTrigger.
 *
 * Stands in for the real value object only when the OpenRegister app is not
 * installed. Constructor copied verbatim (names included: decidiq builds it
 * with named arguments) from ConductionNL/openregister@main,
 * lib/Service/Task/TaskTrigger.php.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * A system source acting on a task, and why.
 */
final class TaskTrigger {
	/**
	 * @param string $source The identity recorded as actor.
	 * @param string $reason The reason recorded on the audit.
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
