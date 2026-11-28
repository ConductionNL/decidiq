<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Dialog: declare a decision urgent. The chair (or a role the body's urgency
  policy allows) states why; the parent posts it and shows the server's
  answer, so a refusal reads exactly as the server gave it.

  @spec openspec/changes/archive/2026-11-20-urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
  @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-020-the-declaration-records-the-declaring-body-or-office
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Declare urgent')"
		data-testid="declare-urgency-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{ t('decidiq', 'An urgent decision takes effect now and is put to the ratifying body afterwards. The reason is stored with the decision and in its audit trail.') }}
			</p>
			<NcTextArea
				v-model="reason"
				:label="t('decidiq', 'Reason for urgency')"
				:maxlength="4000"
				data-testid="declare-urgency-reason"
				resize="vertical" />
			<NcSelect
				v-model="body"
				:options="bodies"
				label="label"
				:inputLabel="t('decidiq', 'Declared on behalf of')"
				:clearable="false"
				data-testid="declare-urgency-body" />
			<NcTextField
				v-model="office"
				:label="t('decidiq', 'Office, when the power belongs to one person')"
				:maxlength="255"
				data-testid="declare-urgency-office" />
			<CnNoteCard v-if="error" type="error">
				{{ error }}
			</CnNoteCard>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="declare-urgency-confirm"
				:disabled="!ready || saving"
				@click="$emit('submit', payload)">
				{{ t('decidiq', 'Declare urgent') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { canDeclare, declarationBody } from '../services/urgency.js'

export default {
	name: 'DeclareUrgencyDialog',

	components: { CnNoteCard, NcButton, NcDialog, NcSelect, NcTextArea, NcTextField },

	props: {
		/** True while the parent saves */
		saving: { type: Boolean, default: false },
		/** The server's refusal, shown as given */
		error: { type: String, default: '' },
		/** The governance bodies to declare for, as `{ id, label }` */
		bodies: { type: Array, default: () => [] },
		/** The body picked when the dialog opens, by id */
		defaultBody: { type: String, default: '' },
	},

	emits: ['submit', 'close'],

	data() {
		return {
			reason: '',
			body: this.bodies.find((option) => option.id === this.defaultBody) || null,
			office: '',
		}
	},

	computed: {
		/** @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-020-the-declaration-records-the-declaring-body-or-office */
		payload() {
			return declarationBody({ reason: this.reason, body: this.body, office: this.office })
		},

		/** @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-020-the-declaration-records-the-declaring-body-or-office */
		ready() {
			return canDeclare({ reason: this.reason, body: this.body })
		},
	},

	watch: {
		/**
		 * The bodies arrive after the dialog opens: pick the default once they do.
		 *
		 * @param {Array<{id: string, label: string}>} options The bodies.
		 * @spec openspec/changes/urgent-decisions-register/specs/urgent-decision-procedure/spec.md#requirement-req-020-the-declaration-records-the-declaring-body-or-office
		 */
		bodies(options) {
			if (this.body === null) {
				this.body = options.find((option) => option.id === this.defaultBody) || null
			}
		},
	},
}
</script>
