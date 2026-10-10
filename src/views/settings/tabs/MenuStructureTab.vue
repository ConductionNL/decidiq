<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

Which structure the app shows: the simple one (the default) or the full one.

The choice is read at page load by the app's boot code, before it builds the
navigation, so a change shows the next time somebody opens decidiq. The tab says
so, because a setting that seems to do nothing gets changed back.

@visual exclude A section of two radios on the Nextcloud admin settings page, drawn by the settings framework and not by the app's own pages. What it does to the app, the menu, is covered in a browser by tests/e2e/simple-structure-menu.spec.ts.

@spec openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
-->
<template>
	<CnSettingsSection
		id="section-menu-structure"
		:name="t('decidiq', 'Menu structure')"
		:description="
			t('decidiq', 'Choose how much the menu shows. Simple is the default.')
		">
		<div class="menu-structure" data-testid="menu-structure">
			<fieldset class="menu-structure__choices" :disabled="saving">
				<legend class="hidden-visually">
					{{ t('decidiq', 'Menu structure') }}
				</legend>
				<NcCheckboxRadioSwitch
					v-model="structure"
					value="simple"
					name="menu_structure"
					type="radio"
					data-testid="menu-structure-simple"
					@update:modelValue="save">
					{{ t('decidiq', 'Simple') }}
				</NcCheckboxRadioSwitch>
				<p class="menu-structure__help">
					{{
						t(
							'decidiq',
							'Eight menu entries for daily work. Everything else is one step further, in settings or on a page.',
						)
					}}
				</p>
				<NcCheckboxRadioSwitch
					v-model="structure"
					value="full"
					name="menu_structure"
					type="radio"
					data-testid="menu-structure-full"
					@update:modelValue="save">
					{{ t('decidiq', 'Full') }}
				</NcCheckboxRadioSwitch>
				<p class="menu-structure__help">
					{{ t('decidiq', 'Every entry in the menu, as it was before.') }}
				</p>
			</fieldset>
			<p class="menu-structure__note" role="status">
				{{
					saved
						? t(
								'decidiq',
								'Saved. People see the change the next time they open Decidiq.',
							)
						: t(
								'decidiq',
								'No page is removed. Both menus open the same pages.',
							)
				}}
			</p>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import { saveMenuStructure } from '../../../services/menuStructureSetting.js'
import {
	resolveStructureProfile,
	STRUCTURE_SETTING,
} from '../../../utils/structureProfile.js'

/**
 * The menu structure choice on the admin settings page.
 *
 * @spec openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
 */
export default {
	name: 'MenuStructureTab',
	components: { CnSettingsSection, NcCheckboxRadioSwitch, NcNoteCard },
	data() {
		const stored = resolveStructureProfile(
			loadState('decidiq', STRUCTURE_SETTING, ''),
		)
		return {
			structure: stored,
			stored,
			saving: false,
			saved: false,
			error: null,
		}
	},

	methods: {
		t,
		/**
		 * Store the chosen structure, and put the radio back when that fails.
		 *
		 * @spec openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
		 */
		async save() {
			if (this.structure === this.stored) {
				return
			}
			this.saving = true
			this.saved = false
			this.error = null
			try {
				this.stored = await saveMenuStructure(this.structure, {
					url: generateUrl('/apps/decidiq/api/settings'),
					requestToken: OC.requestToken,
				})
				this.saved = true
			} catch {
				this.structure = this.stored
				this.error = t('decidiq', 'The menu could not be saved. Try again.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.menu-structure__choices {
	border: 0;
	margin: 0;
	padding: 0;
}

.menu-structure__help {
	color: var(--color-text-maxcontrast);
	margin: 0 0 calc(var(--default-grid-baseline) * 2)
		calc(var(--default-grid-baseline) * 9);
}

.menu-structure__note {
	color: var(--color-text-maxcontrast);
	margin-top: calc(var(--default-grid-baseline) * 2);
}
</style>
