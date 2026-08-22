<?php

/**
 * Decidiq RegisterDecisionsLeafListener.
 *
 * Registers decidesk's `decidesk-decisions` leaf on OpenRegister through the
 * sibling-app leaf-registration hook (`RegisterLeafProvidersEvent`, openregister
 * app-leaf-provider-registration / ADR-066). This is the SERVER-SIDE half of a
 * registration whose client-side half has shipped since the leaf was built:
 * `src/integrations/registerDecisionsLeaf.js` mounts the tab/widget on
 * `window.OCA.OpenRegister.integrations` under the SAME id.
 *
 * WHY BOTH HALVES, when the leaf already renders. ADR-066 decision 1 makes the
 * JS `registerIntegration()` path the render-surface HALF of the leaf contract,
 * "bound to the server descriptor by shared `id`", and its Consequences name the
 * job this half does: registered descriptors surface through OpenRegister's OCS
 * capabilities so an admin UI or a manifest app "can enumerate leaves without
 * loading any app's JS bundle". Without this listener the leaf renders but is
 * invisible to every server-side consumer — an orphan registration under ADR-066
 * decision 4 (gate-24 R2). hermiq's `RegisterAgentLeafListener` is the fleet's
 * reference shape for the same situation and this class follows it.
 *
 * RENDER-AND-READ ONLY (ADR-066 decision 2). The descriptor carries NO Vue
 * components, no verb, and no run authority. It declares one kind:
 *   - `render-surface` — decidesk mounts the "Besluitvorming" surface on a host
 *     object: the sidebar tab (CnDecisionsTab) on `single-entity`, the
 *     per-object widget (CnDecisionsWidget) on the detail-page and dashboard
 *     grids. The components stay in decidesk's OWN bundle.
 *
 * It does NOT declare `data-provider`: the leaf reads and appends decisions
 * through OpenRegister's own object API from the client (ADR-022), so decidesk
 * serves no app-local store behind this leaf and passes a null provider.
 * Cross-app COMMANDS remain ADR-041 typed events — decidesk already exposes
 * `DecisionRequestedEvent` for that, and it does not travel through this leaf.
 *
 * Gated on decidesk being installed/enabled via `requiredApp`, so on an instance
 * without decidesk the surface is HIDDEN rather than a broken tab.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\AppInfo\Application;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contributes the `decidesk-decisions` render leaf to OpenRegister's catalogue.
 *
 * Every constant below is the SERVER half of a value the JS half also declares,
 * and `tests/Unit/Listener/DecisionsLeafParityTest.php` compares the two
 * declarations directly. They are written out as constants rather than inlined
 * so that comparison has something to read on this side.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
 */
class RegisterDecisionsLeafListener implements IEventListener {

	/**
	 * The shared leaf id, equal to the JS `DECISIONS_INTEGRATION_ID`.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'decidesk-decisions';

	/**
	 * The l10n SOURCE string for the leaf label, equal to the string the JS half
	 * passes to `t('decidesk', …)`.
	 *
	 * The app's translation catalogue is keyed on Dutch source strings
	 * (`l10n/en.json` maps this one to "Decision making"), so the server half has
	 * to use the SAME key or the two halves would render different labels on the
	 * same leaf even though both are "translated". Re-keying the catalogue is an
	 * app-wide i18n change, not this leaf's to make.
	 *
	 * @var string
	 */
	public const LABEL_SOURCE = 'Besluitvorming';

	/**
	 * Material Design Icons name, equal to the JS half's `icon`.
	 *
	 * @var string
	 */
	public const ICON = 'Gavel';

	/**
	 * Admin-UI grouping, equal to the JS half's `group`.
	 *
	 * @var string
	 */
	public const GROUP = 'workflow';

	/**
	 * ADR-019 AD-18 marker: a schema property carrying `referenceType: 'decision'`
	 * renders this leaf's single-entity surface. Equal to the JS half's value.
	 *
	 * @var string
	 */
	public const REFERENCE_TYPE = 'decision';

	/**
	 * The render surfaces this leaf targets — the SAME set, in the same order, as
	 * `src/integrations/registerDecisionsLeaf.js` declares to the registry.
	 *
	 * Every member is drawn from OpenRegister's authoritative
	 * `LeafDescriptor::VALID_SURFACES` vocabulary. All four are included because
	 * the JS half's `componentForSurface()` roots `CnDecisionsWidget` on
	 * `detail-page` / `app-dashboard` / `user-dashboard` and `CnDecisionsTab`
	 * everywhere else, i.e. the leaf really does render on all four. Declaring the
	 * set EXPLICITLY on both halves is deliberate: hermiq's two halves drifted
	 * precisely because one of them declared its surfaces by omission, and a half
	 * that declares nothing gives the cross-layer parity check (gate-24 R4)
	 * nothing to compare.
	 *
	 * @var array<int, string>
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public const SURFACES = [
		'user-dashboard',
		'app-dashboard',
		'detail-page',
		'single-entity',
	];

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Localisation for the human-readable label.
	 * @param LoggerInterface $logger PSR-3 logger (a throwing listener costs only its own leaf).
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Contribute the `decidesk-decisions` leaf descriptor.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	public function handle(Event $event): void {
		if ($event instanceof RegisterLeafProvidersEvent === false) {
			return;
		}

		try {
			$descriptor = new LeafDescriptor(
				id: self::LEAF_ID,
				label: $this->l10n->t(self::LABEL_SOURCE),
				icon: self::ICON,
				kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
				requiredApp: Application::APP_ID,
				group: self::GROUP,
				surfaces: self::SURFACES,
				referenceType: self::REFERENCE_TYPE,
				// Vue 3 leaf under a possibly-Vue-2.7 host: the JS half renders via a
				// `mount`/`unmount` DOM hand-off (openregister#2127, ADR-066 decision 7),
				// so the server descriptor MUST declare the SAME render mode under the
				// shared id or the surface blanks (gate-24 R3).
				renderMode: LeafDescriptor::RENDER_MODE_MOUNT,
			);

			// Render-only leaf: no IntegrationProvider (null). The tab and widget read
			// and append decisions through OpenRegister's own object API in the
			// browser, so decidesk holds no app-local store to serve behind this leaf.
			$event->registerLeaf($descriptor, null);
		} catch (Throwable $e) {
			// Never take the leaf catalogue down: log and skip our own leaf only.
			$this->logger->warning(
				'Decidiq could not register the decidesk-decisions leaf: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

	}//end handle()
}//end class
