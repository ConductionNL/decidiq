// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Registers decidesk's "Besluitvorming" (decisions) integration leaf on
// the shared OpenRegister integration registry (ADR-019 / ADR-022).
//
// This is decidesk's FIRST integration leaf: it surfaces decision-making
// — proposals, advice and final decisions — as a sidebar tab + detail-page
// widget on ANY consuming object's detail page (a procest case being the
// canonical consumer). The leaf is generic; it reads the host object's
// identity from the registry-supplied context and never hard-codes procest.
//
// Bootstrap-order safety: decidesk's bundle may load before OR's main
// bundle installs the real registry. We therefore install a `{ _queue,
// register }` stub before registering, so OR replays the descriptor when
// it later calls installIntegrationRegistry(). When OR is already
// installed (the common case in decidesk's own pages, where main.js calls
// installIntegrationRegistry() first), register() lands live.

import { translate as t } from '@nextcloud/l10n'
import CnDecisionsTab from './CnDecisionsTab.vue'
import CnDecisionsWidget from './CnDecisionsWidget.vue'

/**
 * The integration id consuming apps (e.g. procest) must reference to
 * render this leaf on an object's detail page / sidebar.
 *
 * @type {string}
 */
export const DECISIONS_INTEGRATION_ID = 'decidesk-decisions'

/**
 * The integration descriptor for the "Besluitvorming" leaf.
 *
 * @type {object}
 */
export const decisionsLeafDescriptor = {
	id: DECISIONS_INTEGRATION_ID,
	label: t('decidesk', 'Besluitvorming'),
	icon: 'Gavel',
	// decidesk's brand accent (cobalt) for the integration tab/header tint.
	accentColor: '#21468B',
	requiredApp: 'decidesk',
	order: 55,
	group: 'workflow',
	// AD-18 marker: a schema property carrying referenceType:'decision'
	// renders this leaf's single-entity surface.
	referenceType: 'decision',
	tab: CnDecisionsTab,
	widget: CnDecisionsWidget,
	defaultSize: { w: 4, h: 3 },
}

/**
 * Register the leaf on the shared OR integration registry, installing a
 * load-order-safe queue stub when OR's bundle has not yet installed the
 * real registry. Idempotent against the AD-13 collision policy (the first
 * registration of this id wins; a duplicate warns in prod / throws in dev).
 *
 * @param {object} [globalRef] Global to attach to (defaults to `window`).
 *
 * @return {void}
 */
export function registerDecisionsLeaf(globalRef) {
	const target = globalRef || (typeof window !== 'undefined' ? window : null)
	if (target === null) {
		return
	}

	target.OCA = target.OCA || {}
	target.OCA.OpenRegister = target.OCA.OpenRegister || {}
	const current = target.OCA.OpenRegister.integrations

	// Real registry installed (has register(), no _queue) → register live.
	if (current && typeof current.register === 'function' && current._queue === undefined) {
		try {
			current.register(decisionsLeafDescriptor)
		} catch (e) {
			// AD-13: duplicate id throws in dev — non-fatal for boot.
			// eslint-disable-next-line no-console
			console.warn('[decidesk] decisions leaf already registered', e)
		}
		return
	}

	// Not installed yet — ensure a queue stub exists, then enqueue.
	if (current === undefined || current === null) {
		target.OCA.OpenRegister.integrations = {
			_queue: [],
			register(entry) {
				this._queue.push(entry)
			},
		}
	}
	target.OCA.OpenRegister.integrations.register(decisionsLeafDescriptor)
}
