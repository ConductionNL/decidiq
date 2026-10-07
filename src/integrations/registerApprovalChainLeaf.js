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
import { createLazyMountPair } from './createLazyMountPair.js'

/**
 * The integration id a consuming app references to render this leaf.
 *
 * @type {string}
 */
export const APPROVAL_CHAIN_INTEGRATION_ID = 'decidiq-approval-chain'

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
 * Load the root component for a mount off the host-forwarded `surface`: the
 * widget on the three WIDGET_SURFACES, the full tab everywhere else.
 *
 * Dynamic on purpose. This module is part of `decidiq-integration-init.js`,
 * which Nextcloud loads on every page; a static import would pull Vue and the
 * component library into that script. The chunk loads only when a host mounts.
 *
 * @param {string} [surface] The render surface the host is mounting into.
 * @return {Promise<object>} The Vue component to root at the element.
 */
function loadComponentForSurface(surface) {
	const loader = WIDGET_SURFACES.includes(surface)
		? import(
				/* webpackChunkName: "leaf-approval-chain" */ './CnApprovalChainWidget.vue'
			)
		: import(
				/* webpackChunkName: "leaf-approval-chain" */ './CnApprovalChainTab.vue'
			)
	return loader.then((module) => module.default)
}

/**
 * Mount hand-off (renderMode 'mount', ADR-066 / openregister#2127). decidiq is
 * Vue 3 while a consuming host may be Vue 2.7; a Vue-3 SFC handed to the host
 * renders blank under the host's runtime. So the host hands us a bare element
 * and we root decidiq's own app at it, once the component chunk has loaded.
 * Idempotent per element, and an unmount during the load cancels the mount.
 */
const { mount, unmount } = createLazyMountPair(
	loadComponentForSurface,
	'approval chain',
)

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
