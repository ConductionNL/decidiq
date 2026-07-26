// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createApp } from 'vue'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import pinia from './pinia.js'
import AdminRoot from './views/settings/AdminRoot.vue'

loadTranslations('decidesk', () => {
	const app = createApp(AdminRoot)
	// Vue 3 (ADR-066): t/n install on globalProperties (was Vue.mixin).
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.use(pinia)
	app.mount('#decidesk-settings')
})
