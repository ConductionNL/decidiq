<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.
@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
-->
<template>
	<div class="decidesk-decision-detail">
		<div class="decidesk-decision-detail__header">
			<NcButton @click="$router.push({ name: 'Decisions' })">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				{{ t('decidesk', 'Terug naar besluiten') }}
			</NcButton>
			<div class="decidesk-decision-detail__actions" v-if="!isNew && decision">
				<NcButton
					v-if="canPublish"
					type="primary"
					@click="publish">
					{{ t('decidesk', 'Publiceren') }}
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

		<NcLoadingIcon v-if="loading" :size="48" class="decidesk-decision-detail__loading" />

		<!-- Edit / New form -->
		<div v-else-if="editing || isNew" class="decidesk-decision-detail__form">
			<h2>{{ isNew ? t('decidesk', 'Nieuw besluit') : t('decidesk', 'Besluit bewerken') }}</h2>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Titel') }}</label>
				<NcTextField v-model="form.title" :label="t('decidesk', 'Titel')" />
			</div>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Tekst') }}</label>
				<textarea v-model="form.text" class="decidesk-textarea" rows="6" />
			</div>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Besluitdatum') }}</label>
				<NcTextField v-model="form.decisionDate" type="datetime-local" :label="t('decidesk', 'Besluitdatum')" />
			</div>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Uitkomst') }}</label>
				<NcSelect v-model="form.outcome" :options="outcomeOptions" />
			</div>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Juridische grondslag') }}</label>
				<NcTextField v-model="form.legalBasis" :label="t('decidesk', 'Juridische grondslag')" />
			</div>
			<div class="decidesk-form-actions">
				<NcButton type="primary" @click="save">{{ t('decidesk', 'Opslaan') }}</NcButton>
				<NcButton @click="cancelEdit">{{ t('decidesk', 'Annuleren') }}</NcButton>
			</div>
		</div>

		<!-- Detail view -->
		<div v-else-if="decision" class="decidesk-decision-detail__view">
			<h2>{{ decision.title }}</h2>

			<div class="decidesk-detail-card">
				<div class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Uitkomst') }}</span>
					<span :class="['decidesk-status-badge', 'decidesk-status-badge--' + decision.outcome]">
						{{ decision.outcome === 'adopted' ? t('decidesk', 'Aangenomen') : t('decidesk', 'Afgewezen') }}
					</span>
				</div>
				<div class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Besluitdatum') }}</span>
					<span>{{ formatDate(decision.decisionDate) }}</span>
				</div>
				<div v-if="decision.legalBasis" class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Juridische grondslag') }}</span>
					<span>{{ decision.legalBasis }}</span>
				</div>
				<div class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Publicatiestatus') }}</span>
					<span v-if="decision.isPublished">
						{{ t('decidesk', 'Gepubliceerd op') }} {{ formatDate(decision.publishedAt) }}
					</span>
					<span v-else class="decidesk-status-badge decidesk-status-badge--unpublished">
						{{ t('decidesk', 'Niet gepubliceerd') }}
					</span>
				</div>
			</div>

			<div class="decidesk-detail-card">
				<h3>{{ t('decidesk', 'Besluit') }}</h3>
				<p>{{ decision.text }}</p>
			</div>

			<!-- Related ActionItems -->
			<div v-if="relatedActionItems.length" class="decidesk-detail-card">
				<h3>{{ t('decidesk', 'Gerelateerde actiepunten') }}</h3>
				<table class="decidesk-related-table">
					<thead>
						<tr>
							<th>{{ t('decidesk', 'Titel') }}</th>
							<th>{{ t('decidesk', 'Verantwoordelijke') }}</th>
							<th>{{ t('decidesk', 'Deadline') }}</th>
							<th>{{ t('decidesk', 'Status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="ai in relatedActionItems"
							:key="ai.id"
							class="decidesk-related-row"
							@click="$router.push({ name: 'ActionItemDetail', params: { id: ai.id } })">
							<td>{{ ai.title }}</td>
							<td>{{ ai.assignee || '—' }}</td>
							<td>{{ formatDate(ai.dueDate) }}</td>
							<td>
								<span :class="['decidesk-status-badge', taskStatusClass(ai)]">
									{{ t('decidesk', ai.taskStatus) }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { useDecisionStore } from '../store/modules/decisions.js'
import { useActionItemStore } from '../store/modules/actionItems.js'

export default {
	name: 'DecisionDetail',
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
		decisionId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			editing: false,
			form: {
				title: '',
				text: '',
				decisionDate: '',
				outcome: 'adopted',
				legalBasis: '',
			},
			outcomeOptions: [
				{ label: t('decidesk', 'Aangenomen'), value: 'adopted' },
				{ label: t('decidesk', 'Afgewezen'), value: 'rejected' },
			],
		}
	},
	computed: {
		isNew() {
			return this.decisionId === 'new'
		},
		decision() {
			return useDecisionStore().currentDecision
		},
		loading() {
			return useDecisionStore().loading
		},
		canPublish() {
			return this.decision?.outcome === 'adopted' && !this.decision?.isPublished
		},
		relatedActionItems() {
			if (!this.decision) return []
			const decisionId = this.decision.id
			return useActionItemStore().actionItems.filter((ai) => {
				return (ai.relations || []).some(
					(r) => r.schema === 'decision' && (r.objectId || r.id) === decisionId
				)
			})
		},
	},
	created() {
		if (!this.isNew) {
			useDecisionStore().fetchDecisionById(this.decisionId)
			useActionItemStore().fetchActionItems()
		} else {
			this.editing = true
		}
	},
	methods: {
		startEdit() {
			const d = this.decision
			this.form = {
				title: d.title || '',
				text: d.text || '',
				decisionDate: d.decisionDate || '',
				outcome: d.outcome || 'adopted',
				legalBasis: d.legalBasis || '',
			}
			this.editing = true
		},
		cancelEdit() {
			this.editing = false
			if (this.isNew) this.$router.push({ name: 'Decisions' })
		},
		async save() {
			const store = useDecisionStore()
			const data = this.isNew
				? this.form
				: { ...this.decision, ...this.form }
			const saved = await store.saveDecision(data)
			if (saved) {
				this.editing = false
				if (this.isNew) {
					this.$router.push({ name: 'DecisionDetail', params: { id: saved.id } })
				}
			}
		},
		async confirmDelete() {
			if (!confirm(t('decidesk', 'Weet u zeker dat u dit besluit wilt verwijderen?'))) return
			const store = useDecisionStore()
			const ok = await store.deleteDecision(this.decisionId)
			if (ok) this.$router.push({ name: 'Decisions' })
		},
		async publish() {
			await useDecisionStore().publishDecision(this.decisionId)
		},
		taskStatusClass(item) {
			const today = new Date()
			const isOverdue = item.dueDate && new Date(item.dueDate) < today && item.taskStatus !== 'completed'
			if (isOverdue || item.taskStatus === 'overdue') return 'decidesk-status-badge--overdue'
			if (item.taskStatus === 'completed') return 'decidesk-status-badge--completed'
			if (item.taskStatus === 'in-progress') return 'decidesk-status-badge--in-progress'
			return 'decidesk-status-badge--open'
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-decision-detail {
	padding: 8px 4px 24px;
	max-width: 900px;
}

.decidesk-decision-detail__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 20px;
}

.decidesk-decision-detail__actions {
	display: flex;
	gap: 8px;
}

.decidesk-decision-detail__loading {
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

.decidesk-status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 500;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.decidesk-status-badge--adopted { background: var(--color-success-light); color: var(--color-success-text); }
.decidesk-status-badge--rejected { background: var(--color-error-light); color: var(--color-error-text); }
.decidesk-status-badge--published { background: var(--color-success); color: var(--color-main-background); }
.decidesk-status-badge--unpublished { background: var(--color-background-dark); color: var(--color-text-maxcontrast); }
.decidesk-status-badge--open { background: var(--color-background-dark); color: var(--color-text-maxcontrast); }
.decidesk-status-badge--in-progress { background: var(--color-warning-light); color: var(--color-warning-text); }
.decidesk-status-badge--completed { background: var(--color-success-light); color: var(--color-success-text); }
.decidesk-status-badge--overdue { background: var(--color-error-light); color: var(--color-error-text); }

.decidesk-related-table {
	width: 100%;
	border-collapse: collapse;
}

.decidesk-related-table th {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.decidesk-related-row {
	cursor: pointer;
}

.decidesk-related-row:hover {
	background: var(--color-background-hover);
}

.decidesk-related-row td {
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
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
