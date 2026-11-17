<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings panel: confidential papers on the portal
 (portal-voting-record-and-confidential-papers, REQ-PSH-003). The administrator
 decides which DigiD or eHerkenning level a named reader needs before the
 portal shows the papers of a non-public agenda item. ConfidentialPapers
 reads the setting.

 Rendered by the Nextcloud settings framework via AdminSettings.php, not the
 in-app router.
-->
<template>
	<div class="decidiq-confidential-papers" data-testid="confidential-papers-settings">
		<h3 class="decidiq-confidential-papers__title">
			{{ t('decidiq', 'Confidential papers on the portal') }}
		</h3>
		<p>
			{{
				t(
					'decidiq',
					'A reader the secretariat names on a confidential agenda item reads its papers on the portal after signing in with DigiD or eHerkenning at this level.',
				)
			}}
		</p>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'The setting was not saved')">
			{{ error }}
		</CnNoteCard>

		<template v-if="loaded">
			<NcCheckboxRadioSwitch
				v-for="level in levels"
				:key="level"
				type="radio"
				name="decidiq-confidential-trust"
				:value="level"
				:modelValue="trust"
				:disabled="saving"
				:data-testid="`confidential-papers-settings-${level}`"
				@update:modelValue="save">
				{{ labelOf(level) }}
			</NcCheckboxRadioSwitch>
		</template>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import {
	CONFIDENTIAL_TRUST_LEVELS,
	confidentialTrust,
	confidentialTrustPayload,
} from '../../utils/confidentialPapers.js'

export default {
	name: 'ConfidentialPapersSettings',
	components: { CnNoteCard, NcCheckboxRadioSwitch },
	data() {
		return {
			loaded: false,
			saving: false,
			trust: 'substantial',
			levels: CONFIDENTIAL_TRUST_LEVELS,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * The words for one level.
		 *
		 * @param {string} level `substantial` or `high`.
		 * @return {string}
		 */
		labelOf(level) {
			return level === 'high'
				? this.t('decidiq', 'High (DigiD Hoog, eHerkenning EH4)')
				: this.t('decidiq', 'Substantial (DigiD Substantieel, eHerkenning EH3)')
		},

		/** Read the level from the app settings. */
		async load() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/decidiq/api/settings'),
				)
				this.apply(data)
			} catch {
				this.apply({})
			}
		},

		/**
		 * Show the level as the settings hold it.
		 *
		 * @param {object} settings The settings.
		 */
		apply(settings) {
			this.trust = confidentialTrust(settings)
			this.loaded = true
		},

		/**
		 * Write the level.
		 *
		 * @param {string} level The new level.
		 */
		async save(level) {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/decidiq/api/settings'),
					confidentialTrustPayload(level),
				)
				this.apply(data?.config || confidentialTrustPayload(level))
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| this.t('decidiq', 'Try again in a moment.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-confidential-papers {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 700px;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.decidiq-confidential-papers__title {
	font-weight: bold;
}
</style>
