<?php

/**
 * Test stub of OpenRegister's LifecycleStatesKeeperInterface (same signature as OpenRegister main).
 *
 * @license EUPL-1.2
 * @copyright Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * Run an operation in which a create keeps the lifecycle state it carries (a historic import).
 */
interface LifecycleStatesKeeperInterface {

	/**
	 * Run the operation; while it runs, a create keeps the lifecycle state it carries.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed What the operation returned.
	 */
	public function keepingStates(callable $operation): mixed;
}//end interface
