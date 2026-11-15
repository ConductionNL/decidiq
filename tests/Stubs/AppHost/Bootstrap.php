<?php

/**
 * Recording stand-in for OpenRegister's AppHost\Bootstrap.
 *
 * Declares only aliasStoreController(), with the signature of openregister
 * #9078 (it takes a LeafAdoption), and records each call instead of binding
 * a controller. OpenRegister is a sibling app, not a composer dependency;
 * the real class wins whenever OpenRegister is loaded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Records the store-controller bindings a leaf asks for.
 */
final class Bootstrap {

	/**
	 * The adoptions passed to aliasStoreController(), in call order.
	 *
	 * @var list<LeafAdoption>
	 */
	public static array $storeAdoptions = [];

	/**
	 * Record a store-controller binding.
	 *
	 * @param IRegistrationContext $context  The registration context.
	 * @param LeafAdoption         $adoption The leaf's app id and options.
	 *
	 * @return void
	 */
	public static function aliasStoreController(IRegistrationContext $context, LeafAdoption $adoption): void {
		self::$storeAdoptions[] = $adoption;
	}//end aliasStoreController()
}//end class
