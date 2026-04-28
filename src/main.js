// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { registerIcons, registerTranslations } from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import router from './router/index.js'
import App from './App.vue'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// NL Design System token mapping (ADR-010)
import './assets/nl-design.css'

// Global (unscoped) app styles
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)

// Required @conduction/nextcloud-vue bootstrap calls. registerTranslations()
// makes library-rendered strings respect the current Nextcloud language;
// registerIcons() lets CnIcon resolve the names referenced in components.
registerIcons({})
registerTranslations()

// Mount the app regardless of whether the per-locale translations file
// exists — a missing l10n/<lang>.json should not block the entire app
// from booting. The original `loadTranslations(app, callback)` form
// silently dropped the callback on rejection, leaving #content empty
// for users on locales without a shipped translation file.
const mount = () => {
	const app = new Vue({
		pinia,
		router,
		render: h => h(App),
	})
	// NC32 needs #content to be taken over.
	app.$mount('#content')
}

loadTranslations('decidesk').catch(() => { /* no translations for this locale */ }).then(mount)
