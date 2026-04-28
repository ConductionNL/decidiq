<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Tier 4 of the manifest-renderer adoption pattern (see
  https://github.com/ConductionNL/nextcloud-vue/blob/main/docs/migrating-to-manifest.md):
  the entire app shell is rendered by `CnAppRoot`. The manifest at
  `src/manifest.json` declares menu, pages, and dependencies; the
  router config (`src/router/index.js`) is generated from it.

  Phases handled by `CnAppRoot`:
   - loading           → while `useAppManifest.isLoading` is true
   - dependency-check  → uses `manifest.dependencies` + `useAppStatus`
                         (replaces the old `useSettingsStore.hasOpenRegisters` gate)
   - shell             → renders `CnAppNav` from `manifest.menu[]` and
                         `<router-view>`

  All store initialisation now runs in `main.js` before mount, so
  `App.vue` no longer needs a `created()` hook.
-->
<template>
	<CnAppRoot
		:manifest="manifest"
		app-id="decidesk"
		:is-loading="isLoading"
		:custom-components="customComponents"
		:translate="translate" />
</template>

<script>
import { CnAppRoot, useAppManifest } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import bundledManifest from './manifest.json'
import customComponentsRegistry from './customComponents.js'

// Closure that translates manifest keys ("Dashboard", "Minutes", etc.)
// under the decidesk app namespace. The library never imports `t()`
// from a specific app; instead the consumer passes its own translator
// in. Importing `translate` directly here (rather than going through
// the `this.t` mixin method) keeps the function pure and safe to call
// from contexts where `this` isn't available.
const decideskTranslate = (key) => t('decidesk', key)

export default {
	name: 'App',
	components: { CnAppRoot },

	setup() {
		const { manifest, isLoading } = useAppManifest('decidesk', bundledManifest)
		return { manifest, isLoading }
	},

	computed: {
		customComponents() {
			return customComponentsRegistry
		},
		translate() {
			return decideskTranslate
		},
	},
}
</script>
