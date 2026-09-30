<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Fetch documents from the case: the documents of the agenda item's case,
 those fetched before marked and not offered again, and the ticked ones
 copied into the item's documents (platform-case-system-document-exchange,
 matrix row plt-23).

 @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Fetch documents from the case')"
		size="normal"
		data-testid="case-documents-dialog"
		@closing="$emit('close')">
		<template #default>
			<NcLoadingIcon v-if="loading" :size="24" />
			<p v-else-if="documents.length === 0" class="case-documents__empty">
				{{ t('decidiq', 'The case holds no documents.') }}
			</p>
			<ul v-else class="case-documents__list">
				<li
					v-for="document in documents"
					:key="document.url"
					data-testid="case-documents-row">
					<NcCheckboxRadioSwitch
						v-model="chosen"
						:value="document.url"
						:disabled="document.fetched"
						type="checkbox">
						{{ document.name }}
						<span
							v-if="document.fetched"
							class="case-documents__fetched">
							{{ t('decidiq', 'Fetched') }}
						</span>
					</NcCheckboxRadioSwitch>
				</li>
			</ul>
			<p v-if="error" class="case-documents__error" role="alert">
				{{ error }}
			</p>
		</template>
		<template #actions>
			<NcButton data-testid="case-documents-cancel" @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="working || selection.length === 0"
				data-testid="case-documents-fetch"
				@click="fetchChosen">
				{{ t('decidiq', 'Fetch') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
} from '@nextcloud/vue'
import { apiUrl, toFetch } from '../utils/caseSystem.js'

export default {
	name: 'CaseDocumentsModal',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon },

	props: {
		itemId: { type: String, required: true },
	},

	emits: ['close'],

	data() {
		return {
			loading: false,
			working: false,
			documents: [],
			chosen: [],
			error: '',
		}
	},

	computed: {
		/** @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each */
		selection() {
			return toFetch(this.documents, this.chosen)
		},
	},

	/** @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each */
	mounted() {
		this.load()
	},

	methods: {
		/**
		 * List the case's documents.
		 *
		 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					apiUrl(`/agenda-items/${this.itemId}/case-documents`),
				)
				this.documents = response.data?.documents || []
			} catch (e) {
				this.error =
					e.response?.data?.message
					|| this.t('decidiq', 'The case documents could not be listed.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the ticked documents, then list again so they show as fetched.
		 *
		 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
		 */
		async fetchChosen() {
			this.working = true
			this.error = ''
			try {
				await axios.post(
					apiUrl(`/agenda-items/${this.itemId}/case-documents`),
					{ urls: this.selection },
				)
				this.chosen = []
				await this.load()
			} catch (e) {
				this.error =
					e.response?.data?.message
					|| this.t('decidiq', 'The documents could not be fetched.')
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.case-documents__list {
	list-style: none;
	padding: 0;
}

.case-documents__fetched,
.case-documents__empty {
	color: var(--color-text-maxcontrast);
}

.case-documents__error {
	color: var(--color-error-text);
}
</style>
