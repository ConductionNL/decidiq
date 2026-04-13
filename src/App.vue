<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-4.1
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-4.3
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-9.2
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-9.3
-->
<template>
	<NcContent app-name="decidesk">
		<!-- Skip navigation link (accessibility) -->
		<a
			href="#main-content"
			class="decidesk-skip-link"
			@click.prevent="skipToContent">
			{{ t('decidesk', 'Sla navigatie over') }}
		</a>

		<template v-if="storesReady && !hasOpenRegisters">
			<NcAppContent>
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
			<NcAppContent id="main-content" role="main">
				<router-view />
			</NcAppContent>
		</template>
		<NcAppContent v-else>
			<div class="decidesk-loading">
				<NcLoadingIcon :size="64" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { NcButton, NcContent, NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl, imagePath } from '@nextcloud/router'
import { initializeStores } from './store/store.js'
import { useSettingsStore } from './store/modules/settings.js'
import MainMenu from './navigation/MainMenu.vue'

/**
 * Root application component with three rendering states.
 *
 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-4.1
 */
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

	provide() {
		return {
			sidebarState: this.sidebarState,
		}
	},

	data() {
		return {
			storesReady: false,
			sidebarState: {
				open: false,
				entityType: null,
				entityId: null,
			},
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

	methods: {
		/**
		 * Move focus to main content area for skip-navigation link.
		 *
		 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-9.2
		 */
		skipToContent() {
			const main = document.getElementById('main-content')
			if (main) {
				main.setAttribute('tabindex', '-1')
				main.focus()
			}
		},
	},
}
</script>

<style>
.decidesk-skip-link {
	position: absolute;
	left: -10000px;
	top: auto;
	width: 1px;
	height: 1px;
	overflow: hidden;
	z-index: 10000;
	padding: 8px 16px;
	background: var(--color-primary);
	color: var(--color-primary-element-text);
	text-decoration: none;
	font-weight: 600;
	border-radius: 0 0 var(--border-radius) 0;
}

.decidesk-skip-link:focus {
	position: fixed;
	left: 0;
	top: 0;
	width: auto;
	height: auto;
	overflow: visible;
}

.decidesk-loading {
	display: flex;
	justify-content: center;
	align-items: center;
	height: 100%;
}
</style>
