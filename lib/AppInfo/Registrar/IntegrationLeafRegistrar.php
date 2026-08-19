<?php

/**
 * Decidesk Integration Leaf Registrar
 *
 * Server-side half of decidesk's OpenRegister integration leaves (ADR-066).
 * Today that is one leaf, `decidesk-decisions`.
 *
 * Its own registrar rather than a method on {@see PlatformIntegrationRegistrar},
 * for the reason that file's own docblock gives: a registrar accumulates one
 * class reference per registration it makes, and PlatformIntegrationRegistrar
 * already sat at a PHPMD CouplingBetweenObjects of 12 against a threshold of 13.
 * The leaf's two references would have taken it to 14 and turned a correct
 * registration into a red static-analysis job. Extraction is the move this
 * codebase already makes at that boundary — the same reason the four existing
 * registrars were split out of {@see \OCA\Decidesk\AppInfo\Application}.
 *
 * It is also the honest grouping: a leaf is contributed to a SIBLING APP's
 * registry through a typed collect-event, which is a different thing from the
 * Nextcloud platform bindings (search, dashboard widget, object-write guards)
 * PlatformIntegrationRegistrar owns.
 *
 * @category AppInfo
 * @package  OCA\Decidesk\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidesk\AppInfo\Registrar;

use OCA\Decidesk\Listener\RegisterDecisionsLeafListener;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Subscribes decidesk's leaf descriptors to OpenRegister's collect-event.
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
 */
class IntegrationLeafRegistrar {
	/**
	 * Register the server-side half of every decidesk integration leaf.
	 *
	 * The "Besluitvorming" leaf has always registered its RENDER half from JS
	 * (`src/integrations/registerDecisionsLeaf.js`, loaded on every Nextcloud page
	 * by {@see \OCA\Decidesk\AppInfo\Application::boot()}). This subscribes the
	 * matching SERVER half, so the leaf also reaches OpenRegister's
	 * `openregister.integrations.leaves` capability and a manifest app or admin UI
	 * can enumerate it without loading decidesk's bundle (ADR-066).
	 *
	 * Registered UNCONDITIONALLY and from `register()`, unlike the object-lifecycle
	 * subscriptions in {@see ObjectListenerRegistrar}, and the difference is not an
	 * inconsistency: `RegisterLeafProvidersEvent::class` is resolved to a plain
	 * STRING by the compiler and `registerEventListener()` only stores strings, so
	 * nothing here autoloads an OpenRegister class. A `class_exists()` guard in
	 * `register()` would resolve differently purely by app load order — the very
	 * reason those subscriptions were moved to `boot()` — and would therefore be
	 * the less reliable option, not the safer one. The listener itself is
	 * constructed only if OpenRegister actually dispatches the event.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: RegisterLeafProvidersEvent::class,
			listener: RegisterDecisionsLeafListener::class
		);

	}//end register()
}//end class
