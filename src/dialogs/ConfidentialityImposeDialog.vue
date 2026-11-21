<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Dialog: impose a confidentiality restriction on the page's agenda item,
  decision or paper (embargo-geheimhouding REQ-EMB-001). The user picks the
  legal ground, the imposing organ and its body; the server checks who may
  impose and answers the refusal shown here. Opened by the page's
  "Impose confidentiality" header action. The rules live in
  src/utils/confidentialityActions.js.

  @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Impose confidentiality')"
		data-testid="confidentiality-impose-dialog"
		@closing="$emit('close')">
		<NcSelect
			v-model="ground"
			:options="grounds"
			label="label"
			:inputLabel="t('decidiq', 'Ground')"
			:clearable="false"
			data-testid="confidentiality-impose-ground" />
		<NcSelect
			v-model="organ"
			:options="organs"
			label="label"
			:reduce="(option) => option.id"
			:inputLabel="t('decidiq', 'Imposed by')"
			:clearable="false"
			data-testid="confidentiality-impose-organ" />
		<NcSelect
			v-model="body"
			:options="bodies"
			label="label"
			:inputLabel="t('decidiq', 'Governance body')"
			:clearable="false"
			data-testid="confidentiality-impose-body" />
		<p v-if="error" class="confidentiality-dialog__error" role="alert">
			{{ error }}
		</p>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="confidentiality-impose-confirm"
				:disabled="working || payload === null"
				@click="confirm">
				{{ t('decidiq', 'Impose confidentiality') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { dispatchAction } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import {
	defaultBodyOf,
	imposePayload,
	objectOptions,
	optionsUrl,
	refusalOf,
	restrictionUrl,
} from '../utils/confidentialityActions.js'

export default {
	name: 'ConfidentialityImposeDialog',

	components: { NcButton, NcDialog, NcSelect },

	props: {
		/** The page's object (agenda item, decision or paper) */
		object: { type: Object, default: null },
		/** Its id */
		objectId: { type: [String, Number], default: '' },
		/** Its schema slug, when the page passes it; else read off the object */
		schema: { type: String, default: '' },
		/** The body the page proposes, by id */
		defaultBody: { type: String, default: '' },
	},

	emits: ['close'],

	data() {
		return { ground: null, organ: 'body', body: null, grounds: [], bodies: [], working: false, error: '' }
	},

	computed: {
		/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground */
		organs() {
			return [
				{ id: 'body', label: this.t('decidiq', 'Body') },
				{ id: 'chair', label: this.t('decidiq', 'Chair') },
				{ id: 'executive-board', label: this.t('decidiq', 'Executive board') },
			]
		},

		/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground */
		payload() {
			return imposePayload({
				schema: this.schema || this.object?.['@self']?.schema || '',
				target: this.objectId || this.object?.id || '',
				ground: this.ground,
				organ: this.organ,
				body: this.body,
			})
		},
	},

	/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground */
	async mounted() {
		try {
			const [grounds, bodies] = await Promise.all([
				axios.get(optionsUrl('confidentiality-ground')),
				axios.get(optionsUrl('governance-body')),
			])
			this.grounds = objectOptions(grounds.data, 'confidentiality-ground')
			this.bodies = objectOptions(bodies.data, 'governance-body')
			const preset = defaultBodyOf(this.object, this.defaultBody)
			this.body = this.bodies.find((option) => option.id === preset) || null
		} catch (e) {
			this.error = refusalOf(e, this.t('decidiq', 'The confidentiality could not be imposed.'))
		}
	},

	methods: {
		/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground */
		async confirm() {
			this.working = true
			this.error = ''
			try {
				await axios.post(restrictionUrl('impose'), this.payload)
				dispatchAction({ type: 'refresh' })
				this.$emit('close')
			} catch (e) {
				this.error = refusalOf(e, this.t('decidiq', 'The confidentiality could not be imposed.'))
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.confidentiality-dialog__error {
	color: var(--color-error-text);
}
</style>
