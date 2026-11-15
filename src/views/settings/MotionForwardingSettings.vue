<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings panel: forward motions (board DcBeheer, Moties doorsturen).
 The administrator decides whether a motion forwarded to another body waits
 for the chair of that body. MotionForwardingService reads the switch.

 Rendered by the Nextcloud settings framework via AdminSettings.php, not the
 in-app router.
-->
<template>
	<div class="decidiq-motion-forwarding" data-testid="motion-forwarding-settings">
		<h3 class="decidiq-motion-forwarding__title">
			{{ t('decidiq', 'Forward motions') }}
		</h3>
		<p>
			{{
				t(
					'decidiq',
					'A motion can be forwarded to another body, which then deals with it.',
				)
			}}
		</p>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'The setting was not saved')">
			{{ error }}
		</CnNoteCard>

		<NcCheckboxRadioSwitch
			v-if="loaded"
			type="switch"
			:modelValue="requiresApproval"
			:disabled="saving"
			data-testid="motion-forwarding-settings-switch"
			@update:modelValue="save">
			{{ t('decidiq', 'The chair of the receiving body has to approve') }}
		</NcCheckboxRadioSwitch>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import {
	forwardingPayload,
	forwardingSetting,
} from '../../utils/motionForwarding.js'

export default {
	name: 'MotionForwardingSettings',
	components: { CnNoteCard, NcCheckboxRadioSwitch },
	data() {
		return {
			loaded: false,
			saving: false,
			requiresApproval: false,
			error: '',
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/** Read the switch from the app settings. */
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
		 * Show the switch as the settings hold it.
		 *
		 * @param {object} settings The settings.
		 */
		apply(settings) {
			this.requiresApproval = forwardingSetting(settings).requiresApproval
			this.loaded = true
		},

		/**
		 * Write the switch.
		 *
		 * @param {boolean} on The new state.
		 */
		async save(on) {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/decidiq/api/settings'),
					forwardingPayload(on),
				)
				this.apply(data?.config || forwardingPayload(on))
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
.decidiq-motion-forwarding {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 700px;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.decidiq-motion-forwarding__title {
	font-weight: bold;
}
</style>
