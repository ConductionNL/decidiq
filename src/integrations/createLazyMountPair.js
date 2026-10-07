// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The `mount`/`unmount` pair for a decidiq integration leaf, with the leaf's
// Vue components AND Vue itself loaded on demand.
//
// Why lazy: the leaves are registered by `decidiq-integration-init.js`, which
// Nextcloud loads on EVERY page (Util::addInitScript). Importing the tab and
// widget SFCs statically pulled Vue and the whole @conduction/nextcloud-vue
// library into that script, about 27 MB unminified, on pages that never render
// a leaf. Now the init script carries only the descriptors, and the components
// arrive as a separate chunk the first time a host actually mounts one.
//
// The host (CnLeafMountHost) calls `mount(el, props)` synchronously and ignores
// its return value, so the async gap is ours to guard: a host may unmount the
// element, or mount it again, before the chunk has arrived.

import { translate as t } from '@nextcloud/l10n'

/**
 * Build the mount hand-off pair (renderMode 'mount', ADR-066 /
 * openregister#2127) for one leaf.
 *
 * @param {function(string=): Promise<object>} loadComponent Resolves the root
 *     component for a host-forwarded `surface`. Expected to use a dynamic
 *     `import()` so the component lands in its own chunk.
 * @param {string} leafName Name used in console messages.
 * @param {function(): Promise<{createApp: function(object, object): object}>} [loadVue] Resolves the
 *     Vue module. Injectable for tests; defaults to a dynamic `import('vue')`.
 * @return {{mount: function(Element, object): (Promise<void>|undefined), unmount: function(Element): void}} The pair to put on the
 *     descriptor.
 */
export function createLazyMountPair(loadComponent, leafName, loadVue) {
	const vueLoader = loadVue || (() => import('vue'))

	/**
	 * Per-element state, keyed by the host-owned DOM element (NOT by leaf id),
	 * because the same leaf may be mounted into a sidebar tab AND a detail-page
	 * widget on one page at once. An entry is either `{ app: null }` while the
	 * chunk is loading, or `{ app }` once mounted.
	 *
	 * @type {Map<Element, {app: (import('vue').App|null)}>}
	 */
	const mounted = new Map()

	/**
	 * Root decidiq's own Vue 3 app at `el` with the forwarded context as root
	 * props. Idempotent per element: a second mount of an element that is
	 * mounted or still loading is ignored.
	 *
	 * @param {Element} el Host-owned container element.
	 * @param {object} props Forwarded context: { register, schema, objectId, surface, … }.
	 * @return {Promise<void>|undefined} Settles once the app is mounted, or
	 *     skipped. The host ignores it; tests await it.
	 */
	function mount(el, props) {
		if (el === undefined || el === null || mounted.has(el) === true) {
			return undefined
		}
		const entry = { app: null }
		mounted.set(el, entry)

		return Promise.all([vueLoader(), loadComponent(props && props.surface)])
			.then(([vue, component]) => {
				// Unmounted (or unmounted and mounted again) while loading.
				if (mounted.get(el) !== entry) {
					return
				}
				const app = vue.createApp(component, { ...(props || {}) })
				// Global t install contract (ADR-066): the SFCs call `this.t(...)`.
				app.config.globalProperties.t = t
				app.mount(el)
				entry.app = app
			})
			.catch((e) => {
				if (mounted.get(el) === entry) {
					mounted.delete(el)
				}
				// The host's own try/catch cannot see an async failure, so it
				// is reported here rather than swallowed.
				// eslint-disable-next-line no-console
				console.error(`[decidiq] ${leafName} leaf failed to mount`, e)
			})
	}

	/**
	 * Destroy the app rooted at `el` and release the entry, so a mount/unmount
	 * cycle leaks no instance. Unmounting while the chunk is still loading
	 * cancels that mount. Guarded against a double unmount and an unknown
	 * element.
	 *
	 * @param {Element} el The container element previously passed to `mount`.
	 * @return {void}
	 */
	function unmount(el) {
		const entry = mounted.get(el)
		if (entry === undefined) {
			return
		}
		mounted.delete(el)
		if (entry.app !== null) {
			entry.app.unmount()
		}
	}

	return { mount, unmount }
}
