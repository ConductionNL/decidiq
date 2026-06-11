<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Board portal — board meeting scheduling modal (ADR-004 modal isolation).

 Self-contained NcDialog owned by its own .vue file under src/modals/
 (NEVER inlined inside BoardDetail per the hydra-gate-modal-isolation
 rule). POSTs to BoardMeetingController::schedule (which hands off to
 BoardMeetingService::schedule) and emits "created" on success.

 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.1
 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.3
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Schedule board meeting')"
		size="normal"
		data-testid="board-meeting-create-modal"
		@closing="$emit('close')">
		<template #default>
			<form class="meeting-create" @submit.prevent="submit">
				<div class="meeting-create__field">
					<label for="meeting-create-title">{{ t('decidesk', 'Title') }}</label>
					<input id="meeting-create-title"
						v-model="form.title"
						type="text"
						data-testid="board-meeting-create-title">
				</div>
				<div class="meeting-create__field">
					<label for="meeting-create-date">{{ t('decidesk', 'Meeting date') }}</label>
					<input id="meeting-create-date"
						v-model="form.meetingDate"
						type="datetime-local"
						required
						data-testid="board-meeting-create-date">
				</div>
				<div class="meeting-create__field">
					<label for="meeting-create-type">{{ t('decidesk', 'Type') }}</label>
					<select id="meeting-create-type"
						v-model="form.meetingType"
						data-testid="board-meeting-create-type">
						<option v-for="opt in typeOptions" :key="opt" :value="opt">
							{{ opt }}
						</option>
					</select>
				</div>
				<div class="meeting-create__field">
					<label for="meeting-create-format">{{ t('decidesk', 'Format') }}</label>
					<select id="meeting-create-format"
						v-model="form.format"
						data-testid="board-meeting-create-format">
						<option v-for="opt in formatOptions" :key="opt" :value="opt">
							{{ opt }}
						</option>
					</select>
				</div>
				<div class="meeting-create__field">
					<label for="meeting-create-language">{{ t('decidesk', 'Language') }}</label>
					<select id="meeting-create-language"
						v-model="form.language"
						data-testid="board-meeting-create-language">
						<option v-for="opt in languageOptions" :key="opt" :value="opt">
							{{ opt }}
						</option>
					</select>
				</div>
				<div class="meeting-create__field">
					<label for="meeting-create-location">{{ t('decidesk', 'Location') }}</label>
					<input id="meeting-create-location"
						v-model="form.location"
						type="text"
						data-testid="board-meeting-create-location">
				</div>
				<p v-if="errorMessage" class="meeting-create__error" data-testid="board-meeting-create-error">
					{{ errorMessage }}
				</p>
			</form>
		</template>
		<template #actions>
			<NcButton type="primary"
				:loading="submitting"
				:disabled="!form.meetingDate"
				data-testid="board-meeting-create-submit"
				@click="submit">
				{{ t('decidesk', 'Schedule') }}
			</NcButton>
			<NcButton
				data-testid="board-meeting-create-cancel"
				@click="$emit('close')">
				{{ t('decidesk', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'

export default {
	name: 'BoardMeetingCreateModal',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		boardId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'created'],

	data() {
		return {
			submitting: false,
			errorMessage: '',
			form: {
				title: '',
				meetingDate: '',
				meetingType: 'regular',
				format: 'in-person',
				language: 'nl',
				location: '',
			},
			typeOptions: [
				'regular',
				'extraordinary',
				'strategy-day',
				'closed-session',
				'executive-session',
			],
			formatOptions: ['in-person', 'remote', 'hybrid'],
			languageOptions: ['nl', 'en', 'both'],
		}
	},

	methods: {
		/**
		 * POST the form to BoardMeetingController::schedule.
		 *
		 * @return {Promise<void>}
		 */
		async submit() {
			if (!this.form.meetingDate) {
				return
			}
			this.submitting = true
			this.errorMessage = ''
			try {
				const response = await fetch(
					generateUrl(`/apps/decidesk/api/boards/${this.boardId}/meetings`),
					{
						method: 'POST',
						headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: OC.requestToken },
						body: JSON.stringify(this.form),
					},
				)
				const payload = await response.json()
				if (payload?.success === false) {
					this.errorMessage = String(payload?.message || 'Schedule failed')
					return
				}
				const meeting = payload?.meeting || payload?.result || null
				this.$emit('created', meeting)
			} catch (e) {
				this.errorMessage = String(e?.message || 'Schedule failed')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.meeting-create {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.meeting-create__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.meeting-create__field label {
	font-weight: 500;
}

.meeting-create__field input,
.meeting-create__field select {
	padding: 6px 12px;
	border: 1px solid var(--color-border, #d0d0d0);
	border-radius: var(--border-radius, 8px);
	background: var(--color-main-background, #fff);
}

.meeting-create__error {
	color: var(--color-error, #c0392b);
}
</style>
