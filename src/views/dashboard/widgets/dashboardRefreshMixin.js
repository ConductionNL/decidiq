/**
 * dashboardRefreshMixin — shared load/refresh lifecycle for v2 dashboard
 * widgets (pipelinq pattern, REQ-006).
 *
 * A module-level reactive token acts as the refresh signal. The dashboard
 * header's refresh action calls `bumpDashboardRefresh()`; every mounted
 * widget watches the token and re-runs its own `load()`. Each widget supplies
 * its `load()` method (async fetch → assign to data) and the shared `loading`
 * / `error` state lives here so the SFCs stay thin.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import { ref } from 'vue'

/** Module-level refresh token — incremented to fan a refresh out to all widgets. */
const dashboardRefreshToken = ref(0)

/**
 * The reactive refresh signal. The header refresh action holds this ref and
 * bumps it; widgets watch it.
 *
 * @return {import('vue').Ref<number>} The shared refresh token ref.
 */
export function getDashboardRefreshSignal() {
	return dashboardRefreshToken
}

/** Bump the refresh signal, triggering every mounted widget's load(). */
export function bumpDashboardRefresh() {
	dashboardRefreshToken.value++
}

/**
 * Options mixin: calls the host's load() on mount and whenever the shared
 * refresh token changes. Hosts MUST implement an async `load()` method.
 */
export const dashboardRefreshMixin = {
	data() {
		return {
			/** Whether the widget's load() is in flight. */
			loading: false,
			/** Last load() error, if any. */
			error: null,
		}
	},
	computed: {
		/**
		 * Mirror of the module-level refresh token so options `watch` can
		 * observe it reactively.
		 *
		 * @return {number} Current token value.
		 */
		dashboardRefreshToken() {
			return dashboardRefreshToken.value
		},
	},
	watch: {
		dashboardRefreshToken() {
			if (typeof this.load === 'function') {
				this.load()
			}
		},
	},
	mounted() {
		if (typeof this.load === 'function') {
			this.load()
		}
	},
}

export default dashboardRefreshMixin
