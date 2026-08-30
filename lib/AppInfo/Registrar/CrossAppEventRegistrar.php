<?php

/**
 * Decidiq cross-app event registrar.
 *
 * Wires the typed events other fleet apps dispatch to command Decidiq, and the
 * listeners that answer them. Split out of DomainServiceRegistrar because the
 * set grew: what was one event/listener pair for decisions is now three, one
 * per thing another app can ask Decidiq to do, and each pair costs that class
 * two more imports.
 *
 * Per ADR-041 a cross-app COMMAND travels as a typed event. Decidiq's REST
 * controllers are the door for EXTERNAL callers; they refuse a request with no
 * signed-in user, which is exactly the state an in-process migration runs in.
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
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\AppInfo\Registrar;

use OCA\Decidiq\Event\ApprovalActionRequestedEvent;
use OCA\Decidiq\Event\ApprovalRouteRequestedEvent;
use OCA\Decidiq\Event\DecisionRequestedEvent;
use OCA\Decidiq\Event\GovernanceBodyRequestedEvent;
use OCA\Decidiq\Listener\ApprovalActionRequestedListener;
use OCA\Decidiq\Listener\ApprovalRouteRequestedListener;
use OCA\Decidiq\Listener\DecisionRequestedListener;
use OCA\Decidiq\Listener\GovernanceBodyRequestedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers every inbound cross-app command listener.
 *
 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
 */
class CrossAppEventRegistrar {

	/**
	 * The command contract, as event class => listener class.
	 *
	 * A map rather than four call sites, so adding a command is one line and
	 * the whole inbound surface reads as one list.
	 *
	 * @var array<class-string, class-string>
	 */
	private const COMMANDS = [
		// Raise a governance Decision for a consumer's object, and conclude it
		// back through DecisionConcludedEvent.
		DecisionRequestedEvent::class => DecisionRequestedListener::class,

		// Hold a governance body — a committee, a board — with its roster.
		GovernanceBodyRequestedEvent::class => GovernanceBodyRequestedListener::class,

		// Hold a sign-off route, and travel a subject down it. Both delegate to
		// the EXISTING ApprovalRouteService: these add a door, not a second
		// engine, so the event path and the REST path cannot answer differently
		// for the same action.
		ApprovalRouteRequestedEvent::class => ApprovalRouteRequestedListener::class,
		ApprovalActionRequestedEvent::class => ApprovalActionRequestedListener::class,
	];

	/**
	 * Register every command listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/approval-route-events/specs/approval-route-events/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::COMMANDS as $event => $listener) {
			$context->registerEventListener(event: $event, listener: $listener);
		}

	}//end register()

}//end class
