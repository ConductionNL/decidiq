<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Invite a guest from outside to an ad hoc meeting (meeting-ad-hoc-with-
 guests, pla-20, board DcAdhocOverleg "Gast uitnodigen"). The server adds
 the guest to the meeting and mails the invitation with the agenda and a
 link to the papers.

 @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Invite a guest')"
		size="normal"
		data-testid="guest-invite-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="guest-invite__form">
				<NcTextField
					v-model="name"
					data-testid="guest-invite-name"
					:label="t('decidiq', 'Name')" />
				<NcTextField
					v-model="email"
					type="email"
					data-testid="guest-invite-email"
					:label="t('decidiq', 'Email')"
					:placeholder="t('decidiq', 'name@example.org')" />
				<p class="guest-invite__hint">
					{{
						t(
							'decidiq',
							'Guests from outside get an invitation by email with a link to the agenda and the papers of this meeting, and see nothing else.',
						)
					}}
				</p>
				<p
					v-if="error"
					class="guest-invite__error"
					role="alert"
					data-testid="guest-invite-error">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				:disabled="sending || !email.trim()"
				data-testid="guest-invite-submit"
				@click="send">
				{{
					sending
						? t('decidiq', 'Sending…')
						: t('decidiq', 'Send invitation')
				}}
			</NcButton>
			<NcButton data-testid="guest-invite-cancel" @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { inviteGuest } from '../utils/guestInvitation.js'

export default {
	name: 'GuestInviteDialog',

	components: { NcButton, NcDialog, NcTextField },

	props: {
		/** OR object id of the meeting. */
		meetingId: { type: String, required: true },
	},

	emits: ['close', 'invited'],

	data() {
		return { name: '', email: '', sending: false, error: '' }
	},

	methods: {
		/**
		 * Send the invitation and tell the tab to reload.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		async send() {
			this.sending = true
			this.error = ''
			try {
				const result = await inviteGuest(this.meetingId, {
					name: this.name,
					email: this.email,
				})
				this.$emit('invited', result)
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'The invitation could not be sent.')
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.guest-invite__form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.guest-invite__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 0;
}

.guest-invite__error {
	color: var(--color-error);
	margin: 8px 0 0;
}
</style>
