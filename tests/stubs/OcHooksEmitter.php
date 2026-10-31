<?php

/**
 * Stand-in for Nextcloud's private OC\Hooks\Emitter.
 *
 * OCP\Files\IRootFolder extends this server-private interface, which the
 * nextcloud/ocp package does not ship, so a unit test could not double the
 * root folder at all. The bootstrap loads this only when the real one is
 * absent (an installed server provides it).
 *
 * @category Test
 * @package  OCA\Decidiq\Tests
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

namespace OC\Hooks;

/**
 * The hook emitter contract, as far as a double needs it.
 */
interface Emitter {
	/**
	 * Listen for a hook.
	 *
	 * @param string   $scope    The scope.
	 * @param string   $method   The method.
	 * @param callable $callback The callback.
	 *
	 * @return void
	 */
	public function listen($scope, $method, callable $callback);

	/**
	 * Stop listening.
	 *
	 * @param string|null   $scope    The scope.
	 * @param string|null   $method   The method.
	 * @param callable|null $callback The callback.
	 *
	 * @return void
	 */
	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
