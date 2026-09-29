<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Minutes page widget: draft the minutes from the meeting while they are a
 draft, and send them to the members once approved (minutes-draft-and-send,
 min-01 and min-07). Both run through the guarded minutes endpoints; a caller
 who is not chair, secretary or admin gets the server's refusal.
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--minutes-actions"
		data-testid="minutes-actions-tab">
		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'That did not work')">
			{{ error }}
		</CnNoteCard>
		<CnNoteCard v-if="notice" type="success" :title="notice" />

		<div v-if="canDraft" class="decidiq-minutes-actions__block">
			<p class="decidiq-minutes-actions__hint">
				{{
					t(
						'decidiq',
						'Start from a draft with the attendance, the agenda items, the votes and the decisions of the meeting.',
					)
				}}
			</p>
			<CnNoteCard
				v-if="confirmReplace"
				type="warning"
				:title="t('decidiq', 'The minutes already have text')">
				{{ t('decidiq', 'The draft replaces it.') }}
			</CnNoteCard>
			<NcButton
				variant="primary"
				data-testid="minutes-draft-from-meeting"
				:disabled="busy"
				@click="draftFromMeeting">
				{{
					confirmReplace
						? t('decidiq', 'Replace the text with the draft')
						: t('decidiq', 'Draft from the meeting')
				}}
			</NcButton>
		</div>

		<div v-if="canSend" class="decidiq-minutes-actions__block">
			<p class="decidiq-minutes-actions__hint">
				{{
					t(
						'decidiq',
						'Tell every member of the body that the minutes are available, with a link to them.',
					)
				}}
			</p>
			<NcButton
				variant="primary"
				data-testid="minutes-send-to-members"
				:disabled="busy"
				@click="sendToMembers">
				{{ t('decidiq', 'Send to members') }}
			</NcButton>
		</div>

		<p v-if="!canDraft && !canSend" class="decidiq-minutes-actions__hint">
			{{
				t(
					'decidiq',
					'Once the minutes are approved you can send them to the members from here.',
				)
			}}
		</p>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import {
	canDraft,
	canSend,
	distributePath,
	draftPath,
	withDraft,
} from '../../utils/minutesDraft.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MinutesActionsTab',
	components: { CnNoteCard, NcButton },

	inject: {
		cnObjectContext: { default: null },
	},

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			minutes: null,
			busy: false,
			error: '',
			notice: '',
			confirmReplace: false,
		}
	},

	computed: {
		/**
		 * The minutes id: the prop, or the one the detail page provides.
		 *
		 * @return {string} The minutes UUID
		 * @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting
		 */
		minutesId() {
			if (this.objectId) return String(this.objectId)
			const context = this.cnObjectContext
			const holder = context && 'value' in context ? context.value : context
			return String(holder?.objectId || '')
		},

		/** @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting */
		canDraft() {
			return canDraft(this.minutes)
		},

		/** @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-003-send-approved-minutes-to-the-members */
		canSend() {
			return canSend(this.minutes)
		},
	},

	watch: {
		minutesId: {
			immediate: true,
			/** @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/** @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting */
		async load() {
			if (!this.minutesId) return
			try {
				this.minutes = await ensureRelationType('minutes').fetchObject(
					'minutes',
					this.minutesId,
				)
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Could not load the minutes.')
			}
		},

		/**
		 * POST to a guarded minutes endpoint.
		 *
		 * @param {string} path The app-relative path
		 * @return {Promise<object>} The answer
		 * @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting
		 */
		async post(path) {
			const response = await fetch(generateUrl(path), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					requesttoken: OC.requestToken,
				},
				body: '{}',
			})
			const payload = await response.json().catch(() => ({}))
			if (!response.ok) {
				throw new Error(
					payload?.message || payload?.error || response.statusText,
				)
			}
			return payload
		},

		/** @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting */
		async draftFromMeeting() {
			if (
				String(this.minutes?.content || '').trim() !== ''
				&& !this.confirmReplace
			) {
				this.confirmReplace = true
				return
			}
			this.busy = true
			this.error = ''
			this.notice = ''
			try {
				const answer = await this.post(draftPath(this.minutesId))
				const saved = await ensureRelationType('minutes').saveObject(
					'minutes',
					withDraft(this.minutes, answer?.preview),
				)
				this.minutes = saved || withDraft(this.minutes, answer?.preview)
				this.confirmReplace = false
				this.notice = this.t(
					'decidiq',
					'The draft is in the minutes. Review it before you submit the minutes for approval.',
				)
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'The draft could not be made.')
			} finally {
				this.busy = false
			}
		},

		/** @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-003-send-approved-minutes-to-the-members */
		async sendToMembers() {
			this.busy = true
			this.error = ''
			this.notice = ''
			try {
				const answer = await this.post(distributePath(this.minutesId))
				const count = Number(answer?.notified || 0)
				this.notice = this.t('decidiq', 'Members told: {count}.', { count })
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'The minutes could not be sent.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-tab--minutes-actions {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline);
}

.decidiq-minutes-actions__block {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.decidiq-minutes-actions__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
