<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<template>
	<div class="meeting-lifecycle">
		<div class="meeting-lifecycle__state">
			<span class="meeting-lifecycle__label">{{ t('decidesk', 'State') }}:</span>
			<NcBadge :text="currentLifecycleLabel" :type="lifecycleBadgeType" />
		</div>

		<div v-if="availableActions.length > 0" class="meeting-lifecycle__actions">
			<NcButton
				v-for="action in availableActions"
				:key="action.action"
				:type="actionButtonType(action.action)"
				:disabled="loading"
				@click="applyTransition(action.action)">
				{{ actionLabel(action.action) }}
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

export default {
	name: 'MeetingLifecycle',
	components: { NcButton, NcBadge },
	props: {
		/** Meeting object — must contain `id` (or `@self.id`) and `lifecycle`. */
		meeting: {
			type: Object,
			required: true,
		},
	},
	emits: ['lifecycle-updated'],
	data() {
		return {
			loading: false,
			availableActions: [],
		}
	},
	computed: {
		meetingId() {
			return this.meeting['@self']?.id ?? this.meeting.id
		},
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
	},
	watch: {
		meetingId: {
			immediate: true,
			handler(id) {
				if (id) this.loadAvailableActions()
			},
		},
		'meeting.lifecycle'() {
			this.loadAvailableActions()
		},
	},
	methods: {
		actionLabel(action) {
			const labels = {
				schedule: this.t('decidesk', 'Schedule'),
				open: this.t('decidesk', 'Open Meeting'),
				pause: this.t('decidesk', 'Pause'),
				resume: this.t('decidesk', 'Resume'),
				adjourn: this.t('decidesk', 'Adjourn'),
				close: this.t('decidesk', 'Close Meeting'),
			}
			return labels[action] ?? action
		},
		actionButtonType(action) {
			if (action === 'close') return 'error'
			if (action === 'pause' || action === 'adjourn') return 'secondary'
			return 'primary'
		},
		async loadAvailableActions() {
			if (!this.meetingId) return
			try {
				const url = generateUrl(`/apps/openregister/api/objects/${this.meetingId}/available-actions`)
				const { data } = await axios.get(url)
				this.availableActions = data?.actions ?? []
			} catch (error) {
				this.availableActions = []
			}
		},
		async applyTransition(action) {
			if (!this.meetingId) return
			this.loading = true
			try {
				const url = generateUrl(`/apps/openregister/api/objects/${this.meetingId}/transition`)
				const { data } = await axios.post(url, { action })
				this.$emit('lifecycle-updated', data)
				await this.loadAvailableActions()
			} catch (error) {
				showError(error.response?.data?.error ?? this.t('decidesk', 'Lifecycle transition failed.'))
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
