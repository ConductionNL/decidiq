<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings panel: document accessibility check. The administrator picks
 how the publication gate treats a paper that fails the scan (off, warn or
 block) and whether papers are scanned when they are added. The scan is a
 heuristic check, not a full PDF/UA validation.

 Rendered by the Nextcloud settings framework via AdminSettings.php, not the
 in-app router.

 @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
-->
<template>
	<div class="decidiq-document-scan" data-testid="document-scan-settings">
		<h3 class="decidiq-document-scan__title">
			{{ t('decidiq', 'Document accessibility check') }}
		</h3>
		<p>
			{{
				t(
					'decidiq',
					'Papers are checked for a text layer, tags, language, title and bookmarks before they are published. The check is a heuristic, not a full PDF/UA validation.',
				)
			}}
		</p>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'The setting was not saved')">
			{{ error }}
		</CnNoteCard>

		<fieldset v-if="loaded" class="decidiq-document-scan__modes">
			<legend>{{ t('decidiq', 'When a paper fails the check') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="option in modeOptions"
				:key="option.value"
				type="radio"
				name="decidiq-accessibility-mode"
				:value="option.value"
				:modelValue="state.mode"
				:disabled="saving"
				:data-testid="`document-scan-mode-${option.value}`"
				@update:modelValue="save({ mode: option.value })">
				{{ option.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<NcCheckboxRadioSwitch
			v-if="loaded"
			type="switch"
			:modelValue="state.scanOnUpload"
			:disabled="saving"
			data-testid="document-scan-on-upload"
			@update:modelValue="save({ scanOnUpload: $event })">
			{{ t('decidiq', 'Check a paper as soon as it is added') }}
		</NcCheckboxRadioSwitch>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import {
	documentScanPayload,
	documentScanSetting,
} from '../../utils/documentScanSettings.js'

export default {
	name: 'DocumentScanSettings',
	components: { CnNoteCard, NcCheckboxRadioSwitch },
	data() {
		return {
			loaded: false,
			saving: false,
			state: documentScanSetting({}),
			error: '',
		}
	},

	computed: {
		/** The modes with their labels. */
		modeOptions() {
			return [
				{ value: 'off', label: this.t('decidiq', 'Publish without checking') },
				{ value: 'warn', label: this.t('decidiq', 'Warn, and publish once the publisher confirms') },
				{ value: 'block', label: this.t('decidiq', 'Hold the publication back unless a reason is given') },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/** Read the settings. */
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
		 * Show the settings as the server holds them.
		 *
		 * @param {object} settings The settings.
		 */
		apply(settings) {
			this.state = documentScanSetting(settings)
			this.loaded = true
		},

		/**
		 * Write a change to one of the settings.
		 *
		 * @param {object} change `mode` and/or `scanOnUpload`.
		 */
		async save(change) {
			const payload = documentScanPayload({ ...this.state, ...change })
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/decidiq/api/settings'),
					payload,
				)
				this.apply(data?.config || payload)
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
.decidiq-document-scan {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 700px;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.decidiq-document-scan__title {
	font-weight: bold;
}

.decidiq-document-scan__modes {
	border: none;
	padding: 0;
	margin: 0;
}

.decidiq-document-scan__modes legend {
	font-weight: bold;
	margin-block-end: var(--default-grid-baseline);
}
</style>
