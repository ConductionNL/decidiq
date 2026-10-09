<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings panel: the decision type vocabulary (decision-types-as-configuration
 task 4.2). Every installed app may create a decision of these types; occ was the
 only way to change the list before this panel.

 Rendered by the Nextcloud settings framework via AdminSettings.php, not the
 in-app router.

 @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisiontype-vocabulary-is-configuration-with-one-authority
-->
<template>
	<div class="decidiq-decision-types" data-testid="decision-type-settings">
		<h3 class="decidiq-decision-types__title">
			{{ t('decidiq', 'Decision types') }}
		</h3>
		<p>
			{{
				t(
					'decidiq',
					'Every installed app may create a decision of these types. Put one type on each line, in lowercase letters, digits and hyphens.',
				)
			}}
		</p>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'The decision types were not saved')"
			data-testid="decision-type-settings-error">
			{{ error }}
		</CnNoteCard>
		<CnNoteCard
			v-if="saved"
			type="success"
			:title="t('decidiq', 'The decision types were saved')"
			data-testid="decision-type-settings-saved" />

		<NcTextArea
			v-if="loaded"
			v-model="text"
			:label="t('decidiq', 'Decision types, one on each line')"
			:disabled="saving"
			rows="10"
			data-testid="decision-type-settings-text" />

		<NcButton
			v-if="loaded"
			variant="primary"
			:disabled="saving"
			data-testid="decision-type-settings-save"
			@click="save">
			{{ t('decidiq', 'Save decision types') }}
		</NcButton>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcTextArea } from '@nextcloud/vue'
import {
	loadDecisionTypes,
	saveDecisionTypes,
	textFromTypes,
	typesFromText,
} from '../../utils/decisionTypeSettings.js'

export default {
	name: 'DecisionTypeSettings',
	components: { CnNoteCard, NcButton, NcTextArea },
	data() {
		return {
			loaded: false,
			saving: false,
			saved: false,
			text: '',
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/** @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisiontype-vocabulary-is-configuration-with-one-authority */
		async load() {
			try {
				this.text = textFromTypes(await loadDecisionTypes())
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| this.t('decidiq', 'Try again in a moment.')
			} finally {
				this.loaded = true
			}
		},

		/** @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisiontype-vocabulary-is-configuration-with-one-authority */
		async save() {
			this.saving = true
			this.saved = false
			this.error = ''
			try {
				this.text = textFromTypes(
					await saveDecisionTypes(typesFromText(this.text)),
				)
				this.saved = true
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
.decidiq-decision-types {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 700px;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.decidiq-decision-types__title {
	font-weight: bold;
}
</style>
