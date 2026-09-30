<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Case: the case this agenda item belongs to in the organisation's case
 system, a field to link it by case number, and "Fetch documents from the
 case" (platform-case-system-document-exchange, matrix row plt-23). Without a
 connected case system it shows no actions, only why.

 @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
-->
<template>
	<div class="case-tab" data-testid="agenda-item-case">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="!connected" class="case-tab__empty">
			{{ t('decidiq', 'No case system is connected.') }}
		</p>
		<template v-else>
			<p v-if="label" class="case-tab__case" data-testid="agenda-item-case-label">
				{{ label }}
			</p>
			<p v-else class="case-tab__empty">
				{{ t('decidiq', 'This agenda item is not linked to a case.') }}
			</p>
			<form class="case-tab__link" @submit.prevent="link">
				<NcTextField
					v-model="reference"
					:label="t('decidiq', 'Case number or address')"
					data-testid="agenda-item-case-reference" />
				<NcButton
					type="submit"
					:disabled="working || !reference"
					data-testid="agenda-item-case-link">
					{{ t('decidiq', 'Link case') }}
				</NcButton>
			</form>
			<NcButton
				v-if="label"
				variant="primary"
				data-testid="agenda-item-case-fetch"
				@click="dialogOpen = true">
				{{ t('decidiq', 'Fetch documents from the case') }}
			</NcButton>
			<p v-if="error" class="case-tab__error" role="alert">
				{{ error }}
			</p>
		</template>
		<CaseDocumentsModal
			v-if="dialogOpen"
			:itemId="String(objectId)"
			@close="dialogOpen = false" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import CaseDocumentsModal from '../../modals/CaseDocumentsModal.vue'
import { apiUrl, caseLabel } from '../../utils/caseSystem.js'

export default {
	name: 'AgendaItemCaseTab',

	components: { CaseDocumentsModal, NcButton, NcLoadingIcon, NcTextField },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			working: false,
			connected: false,
			caseReference: null,
			reference: '',
			error: '',
			dialogOpen: false,
		}
	},

	computed: {
		/** @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case */
		label() {
			return caseLabel(this.caseReference)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Read whether a case system is connected, and the item's case.
		 *
		 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
		 */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const status = await axios.get(apiUrl('/case-system/status'))
				this.connected = status.data?.connected === true
				if (this.connected) {
					const item = await axios.get(
						generateUrl(
							`/apps/openregister/api/objects/decidiq/agenda-item/${this.objectId}`,
						),
					)
					this.caseReference = item.data?.caseReference || null
				}
			} catch (e) {
				this.error = e.response?.data?.message || this.t('decidiq', 'The case could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Link the item to the case the griffier entered.
		 *
		 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
		 */
		async link() {
			this.working = true
			this.error = ''
			try {
				const response = await axios.post(
					apiUrl(`/agenda-items/${this.objectId}/case`),
					{ reference: this.reference },
				)
				this.caseReference = response.data?.caseReference || null
				this.reference = ''
			} catch (e) {
				this.error = e.response?.data?.message || this.t('decidiq', 'The case could not be linked.')
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.case-tab {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.case-tab__link {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.case-tab__case {
	font-weight: bold;
}

.case-tab__empty {
	color: var(--color-text-maxcontrast);
}

.case-tab__error {
	color: var(--color-error-text);
}
</style>
