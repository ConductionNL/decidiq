<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<NcContent app-name="decidesk">
		<template v-if="storesReady && !hasOpenRegisters">
			<NcAppContent class="open-register-missing">
				<NcEmptyContent
					:name="t('decidesk', 'OpenRegister is required')"
					:description="t('decidesk', 'This app needs OpenRegister to store and manage data. Please install OpenRegister from the app store to get started.')">
					<template #icon>
						<img :src="appIcon"
							alt=""
							width="64"
							height="64">
					</template>
					<template #action>
						<NcButton
							v-if="isAdmin"
							type="primary"
							:href="appStoreUrl">
							{{ t('decidesk', 'Install OpenRegister') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</NcAppContent>
		</template>
		<template v-else-if="storesReady && hasOpenRegisters">
			<MainMenu />
			<NcAppContent>
				<router-view />
			</NcAppContent>
		</template>
		<NcAppContent v-else>
			<div style="display: flex; justify-content: center; align-items: center; height: 100%;">
				<NcLoadingIcon :size="64" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { NcButton, NcContent, NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl, imagePath } from '@nextcloud/router'
import { useAppManifest } from '@conduction/nextcloud-vue'
import { initializeStores } from './store/store.js'
import { useSettingsStore } from './store/modules/settings.js'
import MainMenu from './navigation/MainMenu.vue'
import bundledManifest from './manifest.json'
import customComponents from './customComponents.js'

export default {
	name: 'App',
	components: {
		NcButton,
		NcContent,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		MainMenu,
	},

	// Provide the manifest, custom-component registry, and translate
	// function so CnPageRenderer (mounted per route in router/index.js
	// for manifest-driven pages) can `inject` them without a full
	// CnAppRoot shell. Tier 2 of the manifest-renderer adoption pattern;
	// the existing App.vue / MainMenu / OpenRegister-installed gate
	// stays untouched.
	provide() {
		return {
			cnManifest: this.manifestRef,
			cnCustomComponents: customComponents,
			cnTranslate: (key) => this.$t('decidesk', key),
		}
	},

	setup() {
		const { manifest, isLoading, validationErrors } = useAppManifest('decidesk', bundledManifest)
		return { manifestRef: manifest, manifestLoading: isLoading, manifestValidationErrors: validationErrors }
	},

	data() {
		return {
			storesReady: false,
		}
	},

	computed: {
		hasOpenRegisters() {
			const settingsStore = useSettingsStore()
			return settingsStore.hasOpenRegisters
		},
		isAdmin() {
			const settingsStore = useSettingsStore()
			return settingsStore.getIsAdmin
		},
		appIcon() {
			return imagePath('decidesk', 'app-dark.svg')
		},
		appStoreUrl() {
			return generateUrl('/settings/apps/integration/openregister')
		},
	},

	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>
