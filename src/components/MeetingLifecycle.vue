<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-meeting-management/tasks.md#task-4.1
-->
<template>
	<div class="meeting-lifecycle">
		<div class="meeting-lifecycle__state">
			<span class="meeting-lifecycle__label">{{ t('decidesk', 'State') }}:</span>
			<NcBadge :text="currentLifecycleLabel" :type="lifecycleBadgeType" />
		</div>

		<div v-if="availableActions.length > 0" class="meeting-lifecycle__actions">
			<NcButton
				v-for="action in availableActions"
				:key="action.name"
				:type="action.type"
				:disabled="loading"
				@click="applyTransition(action.name)">
				{{ action.label }}
			</NcButton>
		</div>

		<p v-else class="meeting-lifecycle__terminal">
			{{ t('decidesk', 'This meeting has been closed.') }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
// NcButton and NcBadge are not re-exported by @conduction/nextcloud-vue (only Cn* components are);
// imported directly from @nextcloud/vue until the wrapper layer adds them.
import { NcButton, NcBadge } from '@nextcloud/vue'

/**
 * Meeting lifecycle component — renders valid transition buttons for the current state.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-4.1
 */
export default {
	name: 'MeetingLifecycle',
	components: { NcButton, NcBadge },
	props: {
		/**
		 * The meeting object (must contain `id` and `lifecycle` fields).
		 */
		meeting: {
			type: Object,
			required: true,
		},
	},
	emits: ['lifecycle-updated'],
	data() {
		return {
			loading: false,
		}
	},
	computed: {
		currentLifecycle() {
			return this.meeting?.lifecycle ?? 'draft'
		},
		currentLifecycleLabel() {
			const labels = {
				draft: this.t('decidesk', 'Draft'),
				scheduled: this.t('decidesk', 'Scheduled'),
				opened: this.t('decidesk', 'Opened'),
				paused: this.t('decidesk', 'Paused'),
				adjourned: this.t('decidesk', 'Adjourned'),
				closed: this.t('decidesk', 'Closed'),
			}
			return labels[this.currentLifecycle] ?? this.currentLifecycle
		},
		lifecycleBadgeType() {
			const types = {
				draft: 'default',
				scheduled: 'primary',
				opened: 'success',
				paused: 'warning',
				adjourned: 'warning',
				closed: 'error',
			}
			return types[this.currentLifecycle] ?? 'default'
		},
		availableActions() {
			const transitions = {
				draft: [
					{ name: 'schedule', label: this.t('decidesk', 'Schedule'), type: 'primary' },
				],
				scheduled: [
					{ name: 'open', label: this.t('decidesk', 'Open Meeting'), type: 'primary' },
					{ name: 'close', label: this.t('decidesk', 'Cancel'), type: 'error' },
				],
				opened: [
					{ name: 'pause', label: this.t('decidesk', 'Pause'), type: 'secondary' },
					{ name: 'adjourn', label: this.t('decidesk', 'Adjourn'), type: 'secondary' },
					{ name: 'close', label: this.t('decidesk', 'Close Meeting'), type: 'error' },
				],
				paused: [
					{ name: 'resume', label: this.t('decidesk', 'Resume'), type: 'primary' },
					{ name: 'adjourn', label: this.t('decidesk', 'Adjourn'), type: 'secondary' },
					{ name: 'close', label: this.t('decidesk', 'Close Meeting'), type: 'error' },
				],
				adjourned: [
					{ name: 'open', label: this.t('decidesk', 'Re-open'), type: 'primary' },
					{ name: 'close', label: this.t('decidesk', 'Close Meeting'), type: 'error' },
				],
				closed: [],
			}
			return transitions[this.currentLifecycle] ?? []
		},
	},
	methods: {
		async applyTransition(action) {
			this.loading = true
			try {
				const meetingId = this.meeting['@self']?.id ?? this.meeting.id
				const url = generateUrl(`/apps/decidesk/api/meetings/${meetingId}/lifecycle`)
				const { data } = await axios.post(url, { action })
				if (data.success) {
					this.$emit('lifecycle-updated', data.meeting)
				} else {
					showError(data.message ?? t('decidesk', 'Lifecycle transition failed.'))
				}
			} catch (error) {
				showError(error.response?.data?.message ?? t('decidesk', 'Lifecycle transition failed.'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.meeting-lifecycle {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) 0;
}

.meeting-lifecycle__state {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.meeting-lifecycle__label {
	color: var(--color-text-maxcontrast);
	font-weight: bold;
}

.meeting-lifecycle__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.meeting-lifecycle__terminal {
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-style: italic;
}
</style>
