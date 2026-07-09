<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Decidesk app shell. Mounts CnAppRoot with the bundled manifest and the
 v2 kind-tagged registry prop (ADR-036); provides the `objectSidebarState`
 channel so detail pages (CnDetailPage) can drive a single host-rendered
 CnObjectSidebar through the #sidebar slot.

 @spec openspec/changes/decidesk-manifest-v1/tasks.md#task-7.2
-->
<template>
	<CnAppRoot
		:ai-companion="true"
		:manifest="manifest"
		:registry="registry"
		:page-types="pageTypes"
		app-id="decidesk"
		data-testid="app-root"
		:translate="translateForApp"
		:permissions="permissions">
		<template #sidebar>
			<CnObjectSidebar
				v-if="objectSidebarState.active"
				:title="objectSidebarState.title"
				:subtitle="objectSidebarState.subtitle"
				:object-type="objectSidebarState.objectType"
				:object-id="objectSidebarState.objectId"
				:register="objectSidebarState.register"
				:schema="objectSidebarState.schema"
				:hidden-tabs="objectSidebarState.hiddenTabs"
				:tabs="objectSidebarState.tabs"
				:use-registry="objectSidebarState.useRegistry"
				:exclude-integrations="objectSidebarState.excludeIntegrations"
				:registry="registry"
				:open="objectSidebarState.open"
				@update:open="objectSidebarState.open = $event" />
		</template>
	</CnAppRoot>
</template>

<script>
import Vue from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot, CnObjectSidebar } from '@conduction/nextcloud-vue'
import { initializeStores, useSettingsStore } from './store/store.js'
import { MODE_LABELS, DEFAULT_MODE } from './config/modeLabels.js'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		CnObjectSidebar,
	},

	/** @spec exclude Vue provide() wiring only; exposes the objectSidebarState channel, no domain logic */
	provide() {
		return {
			// Channel for CnDetailPage → host-rendered CnObjectSidebar.
			// Vue.observable makes the plain object reactive for Vue 2.
			objectSidebarState: this.objectSidebarState,
		}
	},

	props: {
		/**
		 * Manifest object — passed from main.js bootstrap. CnAppRoot reads
		 * `manifest.dependencies` for the dependency-check phase and
		 * `manifest.menu` for the default CnAppNav.
		 */
		manifest: {
			type: Object,
			required: true,
		},
		/**
		 * v2 kind-tagged component registry (ADR-036). Passed as the `registry`
		 * prop to CnAppRoot and CnObjectSidebar. Each entry is shaped as
		 * `{ kind: "page", component }` so CnPageRenderer can dispatch
		 * `type: "custom"` pages and sidebar tabs by name.
		 *
		 * Replaces the deprecated `customComponents` prop.
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Page-type registry — `{ index, detail, dashboard, settings, ... }`.
		 * Wired through to descendant `CnPageRenderer` instances via
		 * provide/inject.
		 */
		pageTypes: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			objectSidebarState: Vue.observable({
				active: false,
				open: true,
				objectType: '',
				objectId: '',
				title: '',
				subtitle: '',
				register: '',
				schema: '',
				hiddenTabs: [],
				tabs: undefined,
				// Pluggable integration registry (ADR-019). Set by
				// CnDetailPage when its manifest `config.sidebar.useRegistry`
				// is true; the host CnObjectSidebar then renders one tab per
				// registered integration provider.
				useRegistry: false,
				excludeIntegrations: [],
			}),
		}
	},

	computed: {
		/** @spec exclude trivial framework-state passthrough of window.OC currentUser permissions */
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},

		/**
		 * Active organisatie_modus from the settings store.
		 * Defaults to DEFAULT_MODE ('gov') when not yet configured.
		 *
		 * @spec openspec/changes/ia-six-item-nav/specs/app-navigation/spec.md#requirement-req-nav-006-mode-aware-label-resolution-at-the-translate-chokepoint
		 * @return {string}
		 */
		organisatieModus() {
			const settingsStore = useSettingsStore()
			return (settingsStore.getSettings?.organisatie_modus) || DEFAULT_MODE
		},
	},

	/** @spec exclude lifecycle hook; only boots Pinia stores via initializeStores(), framework setup */
	async created() {
		// Pinia stores still need to come up so legacy custom components
		// (LiveMeeting, settings store, etc.) keep working through the
		// transition. CnAppRoot itself doesn't depend on them.
		await initializeStores()
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import so
		 * the lib never has to know our app id.
		 *
		 * Mode-aware label resolution: consults MODE_LABELS for the active
		 * organisatie_modus to redirect a canonical label to its mode-specific
		 * i18n key before calling t(). Falls back to the canonical key when
		 * no mode-specific mapping exists (pass-through to standard l10n).
		 *
		 * @spec openspec/changes/ia-six-item-nav/specs/app-navigation/spec.md#requirement-req-nav-006-mode-aware-label-resolution-at-the-translate-chokepoint
		 * @param {string} key Canonical translation key.
		 * @return {string} Translated string (or the key on miss).
		 */
		translateForApp(key) {
			const mode = this.organisatieModus
			const modeMap = MODE_LABELS[mode] || {}
			const resolved = modeMap[key] || key
			return ncT('decidesk', resolved)
		},
	},
}
</script>
