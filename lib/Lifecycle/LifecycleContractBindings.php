<?php

/**
 * Bindings of the Kernel contracts to their Lifecycle implementations
 *
 * @category  Lifecycle
 * @package   OCA\Decidiq\Lifecycle
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec exclude Structural composition, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Lifecycle;

use OCA\Decidiq\Kernel\Contracts\DecisionTransitionGuardInterface;
use OCA\Decidiq\Kernel\Contracts\MeetingTransitionGuardInterface;
use OCA\Decidiq\Kernel\Contracts\ProcessTemplatePolicyResolverInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Decidiq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Lifecycle
 * @package  OCA\Decidiq\Lifecycle
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural composition, no functional requirement.
 */
final class LifecycleContractBindings {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural composition, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(DecisionTransitionGuardInterface::class, DecisionTransitionGuard::class);
		$context->registerServiceAlias(MeetingTransitionGuardInterface::class, MeetingTransitionGuard::class);
		$context->registerServiceAlias(ProcessTemplatePolicyResolverInterface::class, ProcessTemplatePolicyResolver::class);
	}//end register()
}//end class
