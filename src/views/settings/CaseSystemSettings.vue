<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings: send the meeting file to the case system automatically when
 the minutes are approved (platform-case-system-document-exchange, matrix
 row plt-24). Linking the case system itself happens in integriq, on the
 case-system connection.

 @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
-->
<template>
	<div class="decidiq-case-settings" data-testid="case-system-settings">
		<h3>{{ t('decidiq', 'Case system') }}</h3>
		<p>
			{{
				t(
					'decidiq',
					'Link the case system in integriq, on the Case system connection. Griffiers can then fetch case documents onto agenda items and send the meeting file after the minutes are approved.',
				)
			}}
		</p>
		<NcCheckboxRadioSwitch
			:modelValue="sendOnApproval"
			:disabled="saving"
			type="switch"
			data-testid="case-system-send-on-approval"
			@update:modelValue="save">
			{{
				t(
					'decidiq',
					'Send the meeting file to the case system when the minutes are approved',
				)
			}}
		</NcCheckboxRadioSwitch>
		<p v-if="error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { apiUrl } from '../../utils/caseSystem.js'

export default {
	name: 'CaseSystemSettings',

	components: { NcCheckboxRadioSwitch },

	data() {
		return { sendOnApproval: false, saving: false, error: '' }
	},

	/** @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval */
	async mounted() {
		try {
			const response = await axios.get(apiUrl('/settings'))
			this.sendOnApproval =
				response.data?.case_system_send_on_approval === 'true'
		} catch {
			this.error = this.t(
				'decidiq',
				'The case system setting could not be read.',
			)
		}
	},

	methods: {
		/**
		 * Store the switch.
		 *
		 * @param {boolean} value On or off.
		 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
		 */
		async save(value) {
			this.saving = true
			this.error = ''
			try {
				await axios.put(apiUrl('/settings'), {
					case_system_send_on_approval: value ? 'true' : 'false',
				})
				this.sendOnApproval = value
			} catch {
				this.error = this.t(
					'decidiq',
					'The case system setting could not be saved.',
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
