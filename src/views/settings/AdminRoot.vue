<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAdminSettingsShell
		appId="decidiq"
		appName="Decidiq"
		look="board"
		versionCardPosition="end"
		:title="t('decidiq', 'Administration')"
		:breadcrumbs="breadcrumbs"
		:breadcrumbNote="
			t('decidiq', 'For administrators of decidiq. Changes apply to everyone.')
		"
		data-testid="admin-root"
		:showSetup="true"
		:setupSteps="setupSteps"
		@reimported="onReimported">
		<Settings v-if="storesReady" />
		<MotionForwardingSettings v-if="storesReady" />
		<CaseSystemSettings v-if="storesReady" />

		<PublicationSettings v-if="storesReady" />
		<FullExportSettings v-if="storesReady" />
		<RoleRightsSettings v-if="storesReady" />
		<OfficePaperSettings v-if="storesReady" />
		<DecisionTypeSettings v-if="storesReady" />
		<!-- The menu structure is not on board DcBeheer: it follows the sections the board draws. -->
		<MenuStructureTab />
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import CaseSystemSettings from './CaseSystemSettings.vue'
import DecisionTypeSettings from './DecisionTypeSettings.vue'
import FullExportSettings from './FullExportSettings.vue'
import MotionForwardingSettings from './MotionForwardingSettings.vue'
import OfficePaperSettings from './OfficePaperSettings.vue'
import PublicationSettings from './PublicationSettings.vue'
import RoleRightsSettings from './RoleRightsSettings.vue'
import Settings from './Settings.vue'
import MenuStructureTab from './tabs/MenuStructureTab.vue'
import manifest from '../../manifest.json'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnAdminSettingsShell,
		CaseSystemSettings,
		DecisionTypeSettings,
		MenuStructureTab,
		MotionForwardingSettings,
		Settings,
		FullExportSettings,
		OfficePaperSettings,
		PublicationSettings,
		RoleRightsSettings,
	},

	data() {
		return {
			storesReady: false,
			/*
			 * Board DcBeheer: the path under the title, "Beheerinstellingen /
			 * Decidiq / Beheer". The first crumb goes back to the server's
			 * administration settings.
			 */
			breadcrumbs: [
				{
					label: this.t('decidiq', 'Administration settings'),
					href: generateUrl('/settings/admin'),
				},
				{ label: 'Decidiq' },
				{ label: this.t('decidiq', 'Administration') },
			],

			/*
			 * 🔴 SETUP HAD NO WAY BACK IN. `CnAppRoot` opens the wizard while an
			 * optional step is outstanding and never again once it is settled, so
			 * an administrator who picked "None" on install, or who inherited an
			 * instance somebody else set up, had no way to load example data at
			 * all. The steps are the ones the app bundles, so this button and the
			 * first-run wizard always ask the same question.
			 */
			setupSteps: (manifest.setup && manifest.setup.steps) || [],
		}
	},

	/** @spec exclude lifecycle hook; only boots Pinia stores then flips the storesReady flag, framework setup */
	async created() {
		await initializeStores()
		this.storesReady = true
	},

	methods: {
		/** @spec exclude re-init stores after a configuration re-import; no business logic */
		onReimported() {
			initializeStores()
		},
	},
}
</script>
