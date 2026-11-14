<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: send a proposal along an approval route.

 Lists the active approval routes for decisions, the default first and
 chosen, and starts the picked one on the decision. The engine decides
 whether the caller may; its refusal is shown in the dialog.

 @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Send for approval')"
		data-testid="send-for-approval-dialog"
		@closing="$emit('close')">
		<p v-if="loading">
			{{ t('decidiq', 'Loading routes…') }}
		</p>
		<p v-else-if="routes.length === 0" data-testid="send-for-approval-none">
			{{ t('decidiq', 'There is no active approval route for decisions.') }}
		</p>
		<fieldset v-else class="send-for-approval__routes">
			<legend>{{ t('decidiq', 'Route') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="route in routes"
				:key="route.id"
				v-model="chosenId"
				type="radio"
				name="send-for-approval-route"
				:value="route.id">
				{{ route.name }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<NcNoteCard v-if="error" type="error" data-testid="send-for-approval-error">
			{{ error }}
		</NcNoteCard>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="send-for-approval-send"
				:disabled="!chosen || sending"
				@click="send">
				{{ t('decidiq', 'Send for approval') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard } from '@nextcloud/vue'
import { activeRoutesFor, DECISION_SCHEMA, failureMessage, sendAlongRoute } from '../services/approvalRouteStart.js'

export default {
	name: 'SendForApprovalDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard },

	props: {
		/** The decision to send. */
		decisionId: { type: [String, Number], required: true },
	},

	emits: ['sent', 'close'],

	data() {
		return { routes: [], chosenId: null, loading: true, sending: false, error: '' }
	},

	computed: {
		chosen() {
			return this.routes.find((route) => route.id === this.chosenId) || null
		},
	},

	async mounted() {
		try {
			this.routes = await activeRoutesFor(DECISION_SCHEMA)
			this.chosenId = this.routes.length > 0 ? this.routes[0].id : null
		} catch (e) {
			this.error = failureMessage(e, this.t('decidiq', 'The approval routes could not be loaded.'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Start the chosen route and hand the stages to the page.
		 *
		 * @spec openspec/changes/routes-send-proposal-along-route/specs/approval-routes/spec.md#requirement-req-rspr-001-a-proposal-is-sent-along-a-route-from-its-page
		 */
		async send() {
			if (!this.chosen) return
			this.sending = true
			this.error = ''
			try {
				const stages = await sendAlongRoute(this.chosen, this.decisionId)
				this.$emit('sent', stages)
			} catch (e) {
				this.error = failureMessage(e, this.t('decidiq', 'The proposal could not be sent for approval.'))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.send-for-approval__routes {
	border: 0;
	margin: 0;
	padding: 0;
}
</style>
