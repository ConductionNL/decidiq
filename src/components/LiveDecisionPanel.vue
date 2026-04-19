<template>
	<div class="live-decision-panel">
		<h3>{{ t('decidesk', 'Decisions') }}</h3>

		<!-- Live entry form (only when meeting is opened) -->
		<div v-if="isLiveEntry" class="live-entry-form">
			<div class="form-group">
				<label>{{ t('decidesk', 'Decision Title') }}</label>
				<input v-model="formData.title" type="text" :placeholder="t('decidesk', 'Decision title')">
				<span v-if="validationErrors.title" class="error">{{ validationErrors.title }}</span>
			</div>

			<div class="form-group">
				<label>{{ t('decidesk', 'Decision Text') }}</label>
				<textarea v-model="formData.text" :placeholder="t('decidesk', 'Decision text')"></textarea>
			</div>

			<div class="form-group">
				<label>{{ t('decidesk', 'Outcome') }}</label>
				<select v-model="formData.outcome">
					<option value="adopted">{{ t('decidesk', 'Adopted') }}</option>
					<option value="rejected">{{ t('decidesk', 'Rejected') }}</option>
				</select>
			</div>

			<div class="form-group">
				<label>{{ t('decidesk', 'Legal Basis (optional)') }}</label>
				<input v-model="formData.legalBasis" type="text" :placeholder="t('decidesk', 'Legal basis')">
			</div>

			<button @click="submitDecision" class="btn btn-primary">
				{{ t('decidesk', 'Save Decision') }}
			</button>
		</div>

		<!-- Read-only list (when meeting is not opened) -->
		<div v-if="!isLiveEntry" class="status-notice">
			{{ t('decidesk', 'Live entry available when the meeting is opened') }}
		</div>

		<!-- Decisions list -->
		<div class="decisions-list">
			<div v-for="decision in decisions" :key="decision.id" class="decision-item">
				<div class="decision-title">{{ decision.title }}</div>
				<div class="decision-outcome">{{ decision.outcome }}</div>
				<div class="decision-date">{{ formatDate(decision.decisionDate) }}</div>
			</div>

			<div v-if="decisions.length === 0" class="no-decisions">
				{{ t('decidesk', 'No decisions recorded yet') }}
			</div>
		</div>

		<div v-if="error" class="error-message">{{ error }}</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'

export default {
	name: 'LiveDecisionPanel',
	props: {
		meetingId: {
			type: String,
			required: true,
		},
		meetingLifecycle: {
			type: String,
			default: 'draft',
		},
	},
	data() {
		return {
			formData: {
				title: '',
				text: '',
				outcome: 'adopted',
				legalBasis: '',
			},
			validationErrors: {},
			decisions: [],
			error: '',
		}
	},
	computed: {
		isLiveEntry() {
			return this.meetingLifecycle === 'opened'
		},
	},
	created() {
		this.loadDecisions()
	},
	methods: {
		async loadDecisions() {
			try {
				// Placeholder - would load decisions from API
				this.decisions = []
			} catch (err) {
				this.error = t('decidesk', 'Failed to load decisions')
			}
		},
		async submitDecision() {
			this.validationErrors = {}

			if (!this.formData.title.trim()) {
				this.validationErrors.title = t('decidesk', 'Title is required')
				return
			}

			try {
				await axios.post(
					`/apps/decidesk/api/meetings/${this.meetingId}/live-decisions`,
					this.formData
				)

				// Clear form
				this.formData = {
					title: '',
					text: '',
					outcome: 'adopted',
					legalBasis: '',
				}

				// Reload decisions
				this.loadDecisions()
			} catch (err) {
				this.error = t('decidesk', 'Failed to save decision')
			}
		},
		formatDate(date) {
			return new Date(date).toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.live-decision-panel {
	padding: 15px;
	background: var(--color-background-secondary);
	border-radius: 4px;
	margin-bottom: 20px;
}

h3 {
	margin-top: 0;
	margin-bottom: 15px;
}

.form-group {
	margin-bottom: 15px;
}

.form-group label {
	display: block;
	margin-bottom: 5px;
	font-weight: 600;
	font-size: 14px;
}

.form-group input,
.form-group textarea,
.form-group select {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 14px;
}

.form-group textarea {
	min-height: 80px;
	resize: vertical;
}

.btn {
	padding: 8px 16px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 14px;
	font-weight: 600;
}

.btn-primary {
	background-color: var(--color-primary);
	color: white;
}

.btn-primary:hover {
	opacity: 0.8;
}

.error {
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
	display: block;
}

.status-notice {
	padding: 12px;
	background-color: var(--color-warning);
	color: white;
	border-radius: 4px;
	margin-bottom: 15px;
}

.decisions-list {
	margin-top: 20px;
}

.decision-item {
	padding: 12px;
	background: white;
	border-radius: 4px;
	margin-bottom: 8px;
	border-left: 4px solid var(--color-primary);
}

.decision-title {
	font-weight: 600;
	margin-bottom: 5px;
}

.decision-outcome {
	display: inline-block;
	padding: 2px 6px;
	background: var(--color-success);
	color: white;
	border-radius: 3px;
	font-size: 12px;
	margin-right: 10px;
}

.decision-date {
	font-size: 12px;
	color: var(--color-text-secondary);
}

.no-decisions {
	text-align: center;
	padding: 20px;
	color: var(--color-text-secondary);
}

.error-message {
	padding: 12px;
	background-color: var(--color-error);
	color: white;
	border-radius: 4px;
	margin-top: 15px;
}
</style>
