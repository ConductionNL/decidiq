<template>
	<div class="alv-minutes-actions">
		<button
			v-if="isALV"
			class="btn btn-secondary"
			@click="generateALV">
			{{ t('decidesk', 'Generate ALV Minutes') }}
		</button>

		<button
			v-if="canDistribute"
			class="btn btn-secondary"
			@click="distribute">
			{{ t('decidesk', 'Distribute to Members') }}
		</button>

		<div v-if="!isALV && !canDistribute" class="validation-notice">
			{{ t('decidesk', 'This is not an ALV meeting or minutes are not approved yet') }}
		</div>

		<div v-if="error" class="error-message">
			{{ error }}
		</div>
		<div v-if="success" class="success-message">
			{{ success }}
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'

export default {
	name: 'ALVMinutesActions',
	props: {
		minutesId: {
			type: String,
			required: true,
		},
		meetingType: {
			type: String,
			default: '',
		},
		minutesLifecycle: {
			type: String,
			default: 'draft',
		},
	},
	data() {
		return {
			error: '',
			success: '',
		}
	},
	computed: {
		isALV() {
			return this.meetingType && this.meetingType.toLowerCase().includes('alv')
		},
		canDistribute() {
			return this.minutesLifecycle === 'approved' || this.minutesLifecycle === 'signed'
		},
	},
	methods: {
		async generateALV() {
			try {
				await axios.post(
					`/apps/decidesk/api/minutes/${this.minutesId}/generate-alv`,
				)

				alert(t('decidesk', 'ALV minutes generated'))
				this.success = t('decidesk', 'ALV minutes template generated successfully')
			} catch (err) {
				this.error = t('decidesk', 'Failed to generate ALV minutes')
			}
		},
		async distribute() {
			try {
				const response = await axios.post(
					`/apps/decidesk/api/minutes/${this.minutesId}/distribute`,
				)

				const count = response.data.notified || 0
				this.success = t('decidesk', '{count} members notified', { count })
			} catch (err) {
				this.error = t('decidesk', 'Failed to distribute minutes')
			}
		},
	},
}
</script>

<style scoped>
.alv-minutes-actions {
	padding: 15px;
	margin-bottom: 20px;
}

.btn {
	padding: 8px 16px;
	margin-right: 10px;
	margin-bottom: 10px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 14px;
	font-weight: 600;
}

.btn-secondary {
	background-color: var(--color-background-darker);
	color: var(--color-text);
	border: 1px solid var(--color-border);
}

.btn-secondary:hover {
	background-color: var(--color-background-hover);
}

.validation-notice {
	padding: 12px;
	background-color: var(--color-warning);
	color: white;
	border-radius: 4px;
	margin-bottom: 10px;
}

.error-message {
	padding: 12px;
	background-color: var(--color-error);
	color: white;
	border-radius: 4px;
	margin-top: 10px;
}

.success-message {
	padding: 12px;
	background-color: var(--color-success);
	color: white;
	border-radius: 4px;
	margin-top: 10px;
}
</style>
