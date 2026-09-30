<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings panel: convert Office papers to PDF (agenda-office-files-to-pdf,
 matrix row age-17). The administrator switches automatic conversion on or
 off; without filinq the panel says conversion needs it.

 Rendered by the Nextcloud settings framework via AdminSettings.php, not the
 in-app router.

 @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-004-an-administrator-can-switch-automatic-conversion-off
-->
<template>
	<div class="decidiq-office-papers" data-testid="office-paper-settings">
		<h3 class="decidiq-office-papers__title">
			{{ t('decidiq', 'Office papers as PDF') }}
		</h3>
		<p>
			{{
				t(
					'decidiq',
					'Members read the PDF of each Word, Excel or PowerPoint paper. The original stays next to it for the secretariat.',
				)
			}}
		</p>

		<CnNoteCard
			v-if="loaded && needsFilinq"
			type="warning"
			:title="t('decidiq', 'Conversion needs filinq')"
			data-testid="office-paper-settings-filinq">
			{{
				t(
					'decidiq',
					'Install and enable filinq to convert papers. Until then, papers stay as they were added.',
				)
			}}
		</CnNoteCard>
		<CnNoteCard v-if="error" type="error" :title="t('decidiq', 'The setting was not saved')">
			{{ error }}
		</CnNoteCard>

		<NcCheckboxRadioSwitch
			v-if="loaded"
			type="switch"
			:modelValue="on"
			:disabled="saving"
			data-testid="office-paper-settings-switch"
			@update:modelValue="save">
			{{ t('decidiq', 'Convert Word, Excel and PowerPoint papers to PDF when they are added') }}
		</NcCheckboxRadioSwitch>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { conversionPayload, conversionSetting } from '../../utils/paperRenditions.js'

export default {
	name: 'OfficePaperSettings',
	components: { CnNoteCard, NcCheckboxRadioSwitch },
	data() {
		return {
			loaded: false,
			saving: false,
			on: true,
			needsFilinq: false,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/** @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-004-an-administrator-can-switch-automatic-conversion-off */
		async load() {
			try {
				const { data } = await axios.get(generateUrl('/apps/decidiq/api/settings'))
				this.apply(data)
			} catch {
				this.apply({})
			}
		},

		/** @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-004-an-administrator-can-switch-automatic-conversion-off */
		apply(settings) {
			const state = conversionSetting(settings)
			this.on = state.on
			this.needsFilinq = state.needsFilinq
			this.loaded = true
		},

		/** @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-004-an-administrator-can-switch-automatic-conversion-off */
		async save(on) {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/decidiq/api/settings'),
					conversionPayload(on),
				)
				this.apply(data?.config || conversionPayload(on))
			} catch (e) {
				this.error = e?.response?.data?.message || this.t('decidiq', 'Try again in a moment.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-office-papers {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 700px;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.decidiq-office-papers__title {
	font-weight: bold;
}
</style>
