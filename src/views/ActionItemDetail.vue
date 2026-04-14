<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.
@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7
-->
<template>
	<div class="decidesk-ai-detail">
		<div class="decidesk-ai-detail__header">
			<NcButton @click="$router.push({ name: 'ActionItems' })">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				{{ t('decidesk', 'Terug naar actiepunten') }}
			</NcButton>
			<div class="decidesk-ai-detail__actions" v-if="!isNew && actionItem">
				<NcButton
					v-if="actionItem.taskStatus === 'open'"
					type="secondary"
					@click="startItem">
					{{ t('decidesk', 'In behandeling') }}
				</NcButton>
				<NcButton
					v-if="actionItem.taskStatus === 'in-progress'"
					type="primary"
					@click="completeItem">
					{{ t('decidesk', 'Afgerond') }}
				</NcButton>
				<NcButton @click="startEdit">
					<template #icon>
						<PencilIcon :size="20" />
					</template>
					{{ t('decidesk', 'Bewerken') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
					{{ t('decidesk', 'Verwijderen') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="48" class="decidesk-ai-detail__loading" />

		<!-- Edit / New form -->
		<div v-else-if="editing || isNew" class="decidesk-ai-detail__form">
			<h2>{{ isNew ? t('decidesk', 'Nieuw actiepunt') : t('decidesk', 'Actiepunt bewerken') }}</h2>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Titel') }}</label>
				<NcTextField v-model="form.title" :label="t('decidesk', 'Titel')" />
			</div>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Omschrijving') }}</label>
				<textarea v-model="form.description" class="decidesk-textarea" rows="4" />
			</div>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Verantwoordelijke') }}</label>
				<NcTextField v-model="form.assignee" :label="t('decidesk', 'Verantwoordelijke')" />
			</div>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Deadline') }}</label>
				<NcTextField v-model="form.dueDate" type="datetime-local" :label="t('decidesk', 'Deadline')" />
			</div>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Status') }}</label>
				<NcSelect v-model="form.taskStatus" :options="statusOptions" />
			</div>
			<div class="decidesk-form-actions">
				<NcButton type="primary" @click="save">{{ t('decidesk', 'Opslaan') }}</NcButton>
				<NcButton @click="cancelEdit">{{ t('decidesk', 'Annuleren') }}</NcButton>
			</div>
		</div>

		<!-- Detail view -->
		<div v-else-if="actionItem" class="decidesk-ai-detail__view">
			<h2>{{ actionItem.title }}</h2>

			<div class="decidesk-detail-card">
				<div class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Status') }}</span>
					<span :class="['decidesk-status-badge', statusBadgeClass(actionItem)]">
						{{ statusLabel(actionItem) }}
					</span>
				</div>
				<div class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Verantwoordelijke') }}</span>
					<span>{{ actionItem.assignee || '—' }}</span>
				</div>
				<div class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Deadline') }}</span>
					<span :class="{ 'decidesk-text--error': isOverdue(actionItem) }">
						{{ formatDate(actionItem.dueDate) }}
					</span>
				</div>
				<div v-if="actionItem.completedAt" class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Afgerond op') }}</span>
					<span>{{ formatDate(actionItem.completedAt) }}</span>
				</div>
			</div>

			<div v-if="actionItem.description" class="decidesk-detail-card">
				<h3>{{ t('decidesk', 'Omschrijving') }}</h3>
				<p>{{ actionItem.description }}</p>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { useActionItemStore } from '../store/modules/actionItems.js'

export default {
	name: 'ActionItemDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		ArrowLeftIcon,
		DeleteIcon,
		PencilIcon,
	},
	props: {
		actionItemId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			editing: false,
			form: {
				title: '',
				description: '',
				assignee: '',
				dueDate: '',
				taskStatus: 'open',
			},
			statusOptions: [
				{ label: t('decidesk', 'Open'), value: 'open' },
				{ label: t('decidesk', 'In behandeling'), value: 'in-progress' },
				{ label: t('decidesk', 'Afgerond'), value: 'completed' },
				{ label: t('decidesk', 'Verlopen'), value: 'overdue' },
			],
		}
	},
	computed: {
		isNew() {
			return this.actionItemId === 'new'
		},
		actionItem() {
			return useActionItemStore().currentActionItem
		},
		loading() {
			return useActionItemStore().loading
		},
	},
	created() {
		if (!this.isNew) {
			useActionItemStore().fetchActionItemById(this.actionItemId)
		} else {
			this.editing = true
		}
	},
	methods: {
		startEdit() {
			const ai = this.actionItem
			this.form = {
				title: ai.title || '',
				description: ai.description || '',
				assignee: ai.assignee || '',
				dueDate: ai.dueDate || '',
				taskStatus: ai.taskStatus || 'open',
			}
			this.editing = true
		},
		cancelEdit() {
			this.editing = false
			if (this.isNew) this.$router.push({ name: 'ActionItems' })
		},
		async save() {
			const store = useActionItemStore()
			const data = this.isNew
				? this.form
				: { ...this.actionItem, ...this.form }
			const saved = await store.saveActionItem(data)
			if (saved) {
				this.editing = false
				if (this.isNew) {
					this.$router.push({ name: 'ActionItemDetail', params: { id: saved.id } })
				}
			}
		},
		async confirmDelete() {
			if (!confirm(t('decidesk', 'Weet u zeker dat u dit actiepunt wilt verwijderen?'))) return
			const store = useActionItemStore()
			const ok = await store.deleteActionItem(this.actionItemId)
			if (ok) this.$router.push({ name: 'ActionItems' })
		},
		async startItem() {
			await useActionItemStore().startActionItem(this.actionItemId)
		},
		async completeItem() {
			await useActionItemStore().completeActionItem(this.actionItemId)
		},
		/**
		 * Client-side overdue detection for immediate visual feedback.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7
		 */
		isOverdue(item) {
			if (!item.dueDate || item.taskStatus === 'completed') return false
			return new Date(item.dueDate) < new Date() || item.taskStatus === 'overdue'
		},
		statusBadgeClass(item) {
			if (this.isOverdue(item)) return 'decidesk-status-badge--overdue'
			if (item.taskStatus === 'completed') return 'decidesk-status-badge--completed'
			if (item.taskStatus === 'in-progress') return 'decidesk-status-badge--in-progress'
			return 'decidesk-status-badge--open'
		},
		statusLabel(item) {
			if (this.isOverdue(item) && item.taskStatus !== 'overdue') return t('decidesk', 'Verlopen')
			const labels = {
				open: t('decidesk', 'Open'),
				'in-progress': t('decidesk', 'In behandeling'),
				completed: t('decidesk', 'Afgerond'),
				overdue: t('decidesk', 'Verlopen'),
			}
			return labels[item.taskStatus] || item.taskStatus
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-ai-detail {
	padding: 8px 4px 24px;
	max-width: 900px;
}

.decidesk-ai-detail__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 20px;
}

.decidesk-ai-detail__actions {
	display: flex;
	gap: 8px;
}

.decidesk-ai-detail__loading {
	display: block;
	margin: 40px auto;
}

.decidesk-detail-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 16px;
}

.decidesk-detail-card h3 {
	margin: 0 0 12px;
	font-size: 16px;
	font-weight: 600;
}

.decidesk-detail-row {
	display: flex;
	gap: 12px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.decidesk-detail-row:last-child {
	border-bottom: none;
}

.decidesk-detail-row__label {
	min-width: 160px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.decidesk-text--error {
	color: var(--color-error);
	font-weight: 500;
}

.decidesk-status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 500;
}

.decidesk-status-badge--open {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.decidesk-status-badge--in-progress {
	background: var(--color-warning-light);
	color: var(--color-warning-text);
}

.decidesk-status-badge--completed {
	background: var(--color-success-light);
	color: var(--color-success-text);
}

.decidesk-status-badge--overdue {
	background: var(--color-error-light);
	color: var(--color-error-text);
}

.decidesk-form-field {
	margin-bottom: 16px;
}

.decidesk-form-field label {
	display: block;
	font-weight: 500;
	margin-bottom: 4px;
}

.decidesk-textarea {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	font-family: var(--font-face);
	font-size: 14px;
	resize: vertical;
}

.decidesk-form-actions {
	display: flex;
	gap: 8px;
}
</style>
