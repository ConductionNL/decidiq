<?php

/**
 * Decidiq flow-engine registrar.
 *
 * Contributes decidiq's flow nodes to OpenRegister's flow engine and wakes a
 * run when a decision it asked for concludes. Guarded: without OpenRegister's
 * flow engine nothing is registered and decidiq boots as before.
 *
 * @category AppInfo
 * @package  OCA\Decidiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\AppInfo\Registrar;

use OCA\Decidiq\AppInfo\OpenRegisterAutoloader;
use OCA\Decidiq\Event\DecisionConcludedEvent;
use OCA\Decidiq\Flow\DecidiqFlowNodeListener;
use OCA\Decidiq\Listener\FlowDecisionConcludedListener;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the flow node listener and the conclusion wake, when the engine exists.
 *
 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
 */
class FlowNodeRegistrar {

	/**
	 * Constructor.
	 *
	 * @param string $engineEvent The class whose existence is the guard: the
	 *                            engine's node-registration event. A parameter so a test
	 *                            can name an absent class and prove the guard.
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function __construct(
		private readonly string $engineEvent = RegisterFlowNodesEvent::class,
	) {
	}//end __construct()

	/**
	 * Register both listeners when OpenRegister's flow engine is present.
	 *
	 * The conclusion wake is registered under the SAME guard even though its
	 * event is decidiq's own: it resolves OpenRegister's run mapper and signal
	 * seam, and a listener that cannot be built without OpenRegister has no
	 * business in the table of an instance that lacks it.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OpenRegisterAutoloader::register()
	 * is the composition-root prelude AppHostRegistrar uses for the same
	 * reason: decidiq sorts before openregister, so OCA\OpenRegister\ is not on
	 * the autoloader yet during register(), and there is no container here to
	 * inject an adapter from.
	 *
	 * @spec openspec/changes/flow-request-decision-node/specs/flow-request-decision/spec.md#requirement-req-frd-001-decidiq-contributes-a-request-decision-node
	 */
	public function register(IRegistrationContext $context): void {
		OpenRegisterAutoloader::register();

		if (class_exists($this->engineEvent) === false) {
			return;
		}

		$context->registerEventListener(event: RegisterFlowNodesEvent::class, listener: DecidiqFlowNodeListener::class);
		$context->registerEventListener(event: DecisionConcludedEvent::class, listener: FlowDecisionConcludedListener::class);
	}//end register()
}//end class
