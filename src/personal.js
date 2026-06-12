// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

// Personal settings entry (user-settings spec) — mounted by
// templates/settings/personal.php at /settings/user/decidesk.

import Vue from 'vue'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import PersonalRoot from './views/settings/PersonalRoot.vue'

Vue.mixin({ methods: { t, n } })

loadTranslations('decidesk', () => {
	new Vue({
		render: h => h(PersonalRoot),
	}).$mount('#decidesk-personal-settings')
})
