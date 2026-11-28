<?php

/**
 * Decidiq RegisterDecisionsLeafListener.
 *
 * Registers Decidiq's `decidesk-decisions` leaf on OpenRegister through the
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
 *   - `render-surface` — Decidiq mounts the "Besluitvorming" surface on a host
 *     object: the sidebar tab (CnDecisionsTab) on `single-entity`, the
 *     per-object widget (CnDecisionsWidget) on the detail-page and dashboard
 *     grids. The components stay in Decidiq's OWN bundle.
 *
 * It does NOT declare `data-provider`: the leaf reads and appends decisions
 * through OpenRegister's own object API from the client (ADR-022), so Decidiq
 * serves no app-local store behind this leaf and passes a null provider.
 * Cross-app COMMANDS remain ADR-041 typed events — Decidiq already exposes
 * `DecisionRequestedEvent` for that, and it does not travel through this leaf.
 *
 * Gated on Decidiq being installed/enabled via `requiredApp`, so on an instance
 * without Decidiq the surface is HIDDEN rather than a broken tab.
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

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\OpenRegister\Service\Integration\LeafAvailability;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCA\OpenRegister\Service\Integration\LeafIdentity;
use OCA\OpenRegister\Service\Integration\LeafRendering;

/**
 * Contributes the `decidesk-decisions` render leaf to OpenRegister's catalogue.
 *
 * Every constant below is the SERVER half of a value the JS half also declares,
 * and `tests/Unit/Listener/DecisionsLeafParityTest.php` compares the two
 * declarations directly. They are written out as constants rather than inlined
 * so that comparison has something to read on this side.
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
 */
class RegisterDecisionsLeafListener extends AbstractRenderLeafListener {

	/**
	 * The shared leaf id, equal to the JS `DECISIONS_INTEGRATION_ID`.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'decidesk-decisions';

	/**
	 * The l10n SOURCE string for the leaf label, equal to the string the JS half
	 * passes to `t('decidiq', …)`.
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
	 * Build the `decidesk-decisions` leaf descriptor.
	 *
	 * @param array<string, string> $optional LeafRendering arguments only some OpenRegister versions accept.
	 *
	 * @return LeafDescriptor The descriptor.
	 *
	 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-008-the-decidesk-decisions-leaf-is-declared-on-both-layers
	 */
	protected function descriptor(array $optional): LeafDescriptor {
		// The optional half is UNPACKED FIRST into the rendering value object,
		// and every agreed field stays a written-out named argument, for two
		// reasons that pull the same way.
		// PHP refuses unpacking after a named argument, and
		// `scripts/check-integration-parity.js` reads this call as SOURCE: it
		// correlates the two halves of the leaf by matching `name: value`
		// arguments here against the JS registration. Building the whole
		// argument list as an array leaves the gate nothing to read: measured,
		// it dropped from 8 field assertions to 0 and reported the leaf as
		// having no id at all.
		return new LeafDescriptor(
			identity: new LeafIdentity(
				id: self::LEAF_ID,
				label: $this->l10n->t(self::LABEL_SOURCE),
				icon: self::ICON,
				group: self::GROUP,
			),
			kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
			availability: new LeafAvailability(requiredApp: AppIdentity::APP_ID),
			rendering: new LeafRendering(
				...$optional,
				surfaces: self::SURFACES,
				referenceType: self::REFERENCE_TYPE,
				// Vue 3 leaf under a possibly-Vue-2.7 host: the JS half renders via a
				// `mount`/`unmount` DOM hand-off (openregister#2127, ADR-066 decision 7),
				// so the server descriptor MUST declare the SAME render mode under the
				// shared id or the surface blanks (gate-24 R3).
				renderMode: LeafDescriptor::RENDER_MODE_MOUNT,
			),
		);

	}//end descriptor()
}//end class
