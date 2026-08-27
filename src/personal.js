// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

// Personal settings entry (user-settings spec) — mounted by
// templates/settings/personal.php at /settings/user/decidiq.

import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { createApp } from 'vue'
import PersonalRoot from './views/settings/PersonalRoot.vue'

loadTranslations('decidiq', () => {
	const app = createApp(PersonalRoot)
	// Vue 3 (ADR-066): t/n install on globalProperties (was Vue.mixin).
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount('#decidiq-personal-settings')
})
