// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Registers decidiq's "Parafering" (approval chain) integration leaf on the
// shared OpenRegister integration registry (ADR-019 / ADR-066).
//
// The leaf puts a sign-off route on any consuming object: a tab with the whole
// timeline, and a widget with the step in front of you. A dossiq document is the
// canonical host, but nothing here names it: the leaf reads the host object's
// identity out of the registry-supplied context.
//
// It invokes NOTHING in the consuming app (ADR-066 decision 2). Start, approve
// and reject go to decidiq's own controller, where the engine's refusals live.
//
// It is loaded by decidiq's own init bundle (`decidiq-integration-init.js`, via
// `Util::addInitScript` in Application::boot), which is the `own-script` load
// strategy decidiq declared in #1345. There is no `decidiq-leaves.js` and there
// does not need to be one.

import { translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import CnApprovalChainTab from './CnApprovalChainTab.vue'
import CnApprovalChainWidget from './CnApprovalChainWidget.vue'

/**
 * The integration id a consuming app references to render this leaf.
 *
 * @type {string}
 */
export const APPROVAL_CHAIN_INTEGRATION_ID = 'decidiq-approval-chain'

/**
 * Per-element registry of the Vue 3 app instances this leaf has mounted, so
 * `unmount(el)` finds the right one. Keyed by the host-owned element, NOT by
 * leaf id: the same leaf may be mounted into a sidebar tab AND a detail-page
 * widget on one page at once (openregister#2127).
 *
 * @type {Map<Element, import('vue').App>}
 */
const mountedApps = new Map()

/**
 * Surfaces that render the WIDGET rather than the full timeline.
 *
 * @type {string[]}
 */
const WIDGET_SURFACES = ['detail-page', 'app-dashboard', 'user-dashboard']

/**
 * Every render surface this leaf targets, declared EXPLICITLY and mirrored by
 * `RegisterApprovalChainLeafListener::SURFACES` on the server half.
 *
 * Written out rather than left to the host's default, because that is what
 * gives the cross-layer parity check two sets to compare. A half that declares
 * its surfaces by OMISSION is how hermiq's two halves drifted unnoticed.
 *
 * @type {string[]}
 */
const SURFACES = ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity']

/**
 * Pick the root component off the host-forwarded `surface`.
 *
 * @param {string} [surface] The render surface.
 * @return {object} The component to root at the element.
 */
function componentForSurface(surface) {
	return WIDGET_SURFACES.includes(surface)
		? CnApprovalChainWidget
		: CnApprovalChainTab
}

/**
 * Mount hand-off (renderMode 'mount', ADR-066 / openregister#2127). decidiq is
 * Vue 3 while a consuming host may be Vue 2.7; a Vue-3 SFC handed to the host
 * renders blank under the host's runtime. So the host hands us a bare element
 * and we root decidiq's own app at it. Idempotent per element.
 *
 * @param {Element} el Host-owned container element.
 * @param {object} props Forwarded context.
 * @return {void}
 */
function mount(el, props) {
	if (el === undefined || el === null || mountedApps.has(el) === true) {
		return
	}
	const app = createApp(componentForSurface(props && props.surface), {
		...(props || {}),
	})
	// Global t/n install contract (ADR-066): the SFCs call `this.t(...)`, and
	// the leaf mounts its own app instance, so they are installed here too.
	app.config.globalProperties.t = t
	app.mount(el)
	mountedApps.set(el, app)
}

/**
 * Teardown hand-off. Destroy the app rooted at `el` and release the entry, so a
 * mount/unmount cycle leaks no instance.
 *
 * @param {Element} el The container element.
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
 * The integration descriptor for the "Parafering" leaf.
 *
 * @type {object}
 */
export const approvalChainLeafDescriptor = {
	id: APPROVAL_CHAIN_INTEGRATION_ID,
	label: t('decidiq', 'Parafering'),
	icon: 'Signature',
	accentColor: '#21468B',
	requiredApp: 'decidiq',
	order: 56,
	group: 'workflow',
	surfaces: SURFACES,
	// AD-18 marker: a schema property carrying referenceType 'approval-route'
	// renders this leaf's single-entity surface.
	referenceType: 'approval-route',
	renderMode: 'mount',
	// decidiq loads its own leaf bundle (decidiq#1345). Declared rather than
	// inferred: openregister#3954 read a missing `decidiq-leaves.js` as proof a
	// surface was dark and refused the registration, and it was not dark.
	loadStrategy: 'own-script',
	mount,
	unmount,
	defaultSize: { w: 4, h: 3 },
}

/**
 * Register the leaf on the shared OR integration registry, installing a
 * load-order-safe queue stub when OR's bundle has not yet installed the real
 * registry. Idempotent against the AD-13 collision policy.
 *
 * @param {object} [globalRef] Global to attach to (defaults to `window`).
 * @return {void}
 */
export function registerApprovalChainLeaf(globalRef) {
	const target = globalRef || (typeof window !== 'undefined' ? window : null)
	if (target === null) {
		return
	}

	target.OCA = target.OCA || {}
	target.OCA.OpenRegister = target.OCA.OpenRegister || {}
	const current = target.OCA.OpenRegister.integrations

	if (
		current
		&& typeof current.register === 'function'
		&& current._queue === undefined
	) {
		try {
			current.register(approvalChainLeafDescriptor)
		} catch (e) {
			// AD-13: a duplicate id throws in dev, and that is not fatal to boot.
			// eslint-disable-next-line no-console
			console.warn('[decidiq] approval chain leaf already registered', e)
		}
		return
	}

	if (current === undefined || current === null) {
		target.OCA.OpenRegister.integrations = {
			_queue: [],
			register(entry) {
				this._queue.push(entry)
			},
		}
	}
	target.OCA.OpenRegister.integrations.register(approvalChainLeafDescriptor)
}
