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

import { createApp } from 'vue'
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
 * Per-element registry of the Vue 3 app instances this leaf has mounted, so
 * `unmount(el)` can find and destroy the right one. Keyed by the host-owned DOM
 * element — NOT by leaf id — because the same leaf may be mounted into several
 * elements on one page at once (e.g. a sidebar tab AND a detail-page widget),
 * each its own instance (openregister#2127, "keyed by el").
 *
 * @type {Map<Element, import('vue').App>}
 */
const mountedApps = new Map()

/**
 * Surfaces that render the per-object decisions WIDGET rather than the full tab.
 * The host forwards `surface` on the mount props (CnLeafMountHost): the object
 * sidebar tab carries a single-entity/blank surface (→ the tab), while the
 * detail-page and dashboard widget grids carry these.
 *
 * @type {string[]}
 */
const WIDGET_SURFACES = ['detail-page', 'app-dashboard', 'user-dashboard']

/**
 * Pick the root component for a mount off the host-forwarded `surface`.
 *
 * @param {string} [surface] The render surface the host is mounting into.
 * @return {object} The Vue component to root at the element.
 */
function componentForSurface(surface) {
	return WIDGET_SURFACES.includes(surface) ? CnDecisionsWidget : CnDecisionsTab
}

/**
 * Mount hand-off (renderMode 'mount', ADR-066 / openregister#2127). decidesk is
 * Vue 3 while a consuming OpenBuild/OpenRegister host may be Vue 2.7. A Vue-3 SFC
 * handed to the host is interpreted under the host's own (incompatible) runtime
 * and renders blank. Instead the host hands us a bare, host-owned DOM element and
 * we root decidesk's OWN Vue 3 app at it with the forwarded object context as
 * root props, so each side runs its own framework across the neutral DOM
 * boundary. Idempotent per element.
 *
 * @param {Element} el    Host-owned container element to root the app at.
 * @param {object}  props Forwarded context: { register, schema, objectId, surface, integrationContext, … }.
 * @return {void}
 */
function mount(el, props) {
	if (el === undefined || el === null || mountedApps.has(el) === true) {
		return
	}
	const app = createApp(componentForSurface(props && props.surface), { ...(props || {}) })
	// Global t/n install contract (ADR-066): the tab/widget SFCs call
	// `this.t(...)`. In the app bundle main.js installs these; the leaf mounts
	// its own app instance, so install them here too.
	app.config.globalProperties.t = t
	app.mount(el)
	mountedApps.set(el, app)
}

/**
 * Teardown hand-off. Destroy the Vue 3 app instance rooted at `el` and release
 * the map entry so a mount/unmount cycle leaks no instance. Guarded against a
 * double-unmount and an unknown element.
 *
 * @param {Element} el The container element previously passed to `mount`.
 * @return {void}
 */
function unmount(el) {
	const app = mountedApps.get(el)
	if (app === undefined) {
		return
	}
	mountedApps.delete(el)
	app.unmount()
}

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
	// Vue 3 leaf under a possibly-Vue-2.7 host: render via the DOM mount
	// hand-off, not an SFC the host would interpret under its own runtime
	// (openregister#2127). `mount`/`unmount` travel as a pair; no `tab`/`widget`
	// in mount mode — the host routes tab-vs-widget through the surface prop.
	renderMode: 'mount',
	mount,
	unmount,
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
