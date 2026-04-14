<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.
@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5
-->
<template>
	<div class="decidesk-minutes-detail">
		<div class="decidesk-minutes-detail__header">
			<NcButton @click="$router.push({ name: 'Minutes' })">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				{{ t('decidesk', 'Terug naar notulen') }}
			</NcButton>
			<div class="decidesk-minutes-detail__actions" v-if="!isNew && minutes">
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

		<NcLoadingIcon v-if="loading" :size="48" class="decidesk-minutes-detail__loading" />

		<!-- Edit / New form -->
		<div v-else-if="editing || isNew" class="decidesk-minutes-detail__form">
			<h2>{{ isNew ? t('decidesk', 'Nieuwe notulen') : t('decidesk', 'Notulen bewerken') }}</h2>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Titel') }}</label>
				<NcTextField v-model="form.title" :label="t('decidesk', 'Titel')" />
			</div>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Status') }}</label>
				<NcSelect v-model="form.lifecycle" :options="lifecycleOptions" />
			</div>
			<div class="decidesk-form-field">
				<label>{{ t('decidesk', 'Inhoud') }}</label>
				<textarea v-model="form.content" class="decidesk-textarea" rows="10" />
			</div>
			<div class="decidesk-form-actions">
				<NcButton type="primary" @click="save">{{ t('decidesk', 'Opslaan') }}</NcButton>
				<NcButton @click="cancelEdit">{{ t('decidesk', 'Annuleren') }}</NcButton>
			</div>
		</div>

		<!-- Detail view -->
		<div v-else-if="minutes" class="decidesk-minutes-detail__view">
			<h2>{{ minutes.title }}</h2>

			<!-- Timeline stages -->
			<div class="decidesk-timeline">
				<div
					v-for="stage in stages"
					:key="stage.key"
					:class="['decidesk-timeline__stage', { 'decidesk-timeline__stage--active': minutes.lifecycle === stage.key, 'decidesk-timeline__stage--done': stageIndex(minutes.lifecycle) > stageIndex(stage.key) }]">
					{{ stage.label }}
				</div>
			</div>

			<!-- Lifecycle transition buttons -->
			<div class="decidesk-minutes-detail__transitions">
				<NcButton
					v-if="minutes.lifecycle === 'draft'"
					type="primary"
					@click="transition('review')">
					{{ t('decidesk', 'Ter beoordeling indienen') }}
				</NcButton>
				<NcButton
					v-if="minutes.lifecycle === 'review'"
					type="primary"
					@click="transition('approved')">
					{{ t('decidesk', 'Goedkeuren') }}
				</NcButton>
				<NcButton
					v-if="minutes.lifecycle === 'approved'"
					type="primary"
					@click="transition('signed')">
					{{ t('decidesk', 'Ondertekenen') }}
				</NcButton>
				<NcButton
					v-if="minutes.lifecycle === 'signed'"
					type="primary"
					@click="transition('published')">
					{{ t('decidesk', 'Publiceren') }}
				</NcButton>
				<NcButton
					v-if="minutes.lifecycle === 'draft'"
					@click="generateDraft">
					{{ t('decidesk', 'Concept genereren') }}
				</NcButton>
			</div>

			<div class="decidesk-detail-card">
				<div class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Status') }}</span>
					<span :class="['decidesk-status-badge', 'decidesk-status-badge--' + minutes.lifecycle]">
						{{ t('decidesk', minutes.lifecycle || 'draft') }}
					</span>
				</div>
				<div class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Versie') }}</span>
					<span>{{ minutes.version || 1 }}</span>
				</div>
				<div v-if="minutes.approvedAt" class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Goedgekeurd op') }}</span>
					<span>{{ formatDate(minutes.approvedAt) }}</span>
				</div>
				<div v-if="minutes.signedBy && minutes.signedBy.length" class="decidesk-detail-row">
					<span class="decidesk-detail-row__label">{{ t('decidesk', 'Ondertekend door') }}</span>
					<span>{{ minutes.signedBy.join(', ') }}</span>
				</div>
			</div>

			<div v-if="minutes.content" class="decidesk-detail-card decidesk-detail-card--content">
				<h3>{{ t('decidesk', 'Inhoud') }}</h3>
				<pre class="decidesk-minutes-content">{{ minutes.content }}</pre>
			</div>
		</div>

		<!-- Generate draft preview modal -->
		<NcDialog
			v-if="showDraftModal"
			:name="t('decidesk', 'Concept gegenereerd')"
			:open="showDraftModal"
			@update:open="showDraftModal = false">
			<template #default>
				<p>{{ t('decidesk', 'Bekijk het gegenereerde concept hieronder. Klik op "Toepassen" om de inhoud te overschrijven.') }}</p>
				<pre class="decidesk-draft-preview">{{ draftPreview }}</pre>
			</template>
			<template #actions>
				<NcButton type="primary" @click="applyDraft">{{ t('decidesk', 'Toepassen') }}</NcButton>
				<NcButton @click="showDraftModal = false">{{ t('decidesk', 'Annuleren') }}</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { useMinutesStore } from '../store/modules/minutes.js'

export default {
	name: 'MinutesDetail',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		ArrowLeftIcon,
		DeleteIcon,
		PencilIcon,
	},
	props: {
		minutesId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			editing: false,
			showDraftModal: false,
			draftPreview: '',
			form: {
				title: '',
				lifecycle: 'draft',
				content: '',
			},
			stages: [
				{ key: 'draft', label: t('decidesk', 'Concept') },
				{ key: 'review', label: t('decidesk', 'Ter beoordeling') },
				{ key: 'approved', label: t('decidesk', 'Goedgekeurd') },
				{ key: 'signed', label: t('decidesk', 'Ondertekend') },
				{ key: 'published', label: t('decidesk', 'Gepubliceerd') },
			],
			lifecycleOptions: [
				{ label: t('decidesk', 'Concept'), value: 'draft' },
				{ label: t('decidesk', 'Ter beoordeling'), value: 'review' },
				{ label: t('decidesk', 'Goedgekeurd'), value: 'approved' },
				{ label: t('decidesk', 'Ondertekend'), value: 'signed' },
				{ label: t('decidesk', 'Gepubliceerd'), value: 'published' },
			],
		}
	},
	computed: {
		isNew() {
			return this.minutesId === 'new'
		},
		minutes() {
			return useMinutesStore().currentMinutes
		},
		loading() {
			return useMinutesStore().loading
		},
	},
	created() {
		if (!this.isNew) {
			useMinutesStore().fetchMinutesById(this.minutesId)
		} else {
			this.editing = true
		}
	},
	methods: {
		stageIndex(key) {
			return this.stages.findIndex((s) => s.key === key)
		},
		startEdit() {
			const m = this.minutes
			this.form = {
				title: m.title || '',
				lifecycle: m.lifecycle || 'draft',
				content: m.content || '',
			}
			this.editing = true
		},
		cancelEdit() {
			this.editing = false
			if (this.isNew) this.$router.push({ name: 'Minutes' })
		},
		async save() {
			const store = useMinutesStore()
			const data = this.isNew
				? this.form
				: { ...this.minutes, ...this.form }
			const saved = await store.saveMinutes(data)
			if (saved) {
				this.editing = false
				if (this.isNew) {
					this.$router.push({ name: 'MinutesDetail', params: { id: saved.id } })
				}
			}
		},
		async confirmDelete() {
			if (!confirm(t('decidesk', 'Weet u zeker dat u deze notulen wilt verwijderen?'))) return
			const store = useMinutesStore()
			const ok = await store.deleteMinutes(this.minutesId)
			if (ok) this.$router.push({ name: 'Minutes' })
		},
		async transition(newLifecycle) {
			const store = useMinutesStore()
			const updated = { ...this.minutes, lifecycle: newLifecycle }
			if (newLifecycle === 'approved') {
				updated.approvedAt = new Date().toISOString()
				// Append current user display name to signedBy for approved transition.
				const signers = Array.isArray(this.minutes.signedBy) ? [...this.minutes.signedBy] : []
				if (OC.currentUser) signers.push(OC.currentUser)
				updated.signedBy = signers
			}

			if (newLifecycle === 'signed') {
				const signers = Array.isArray(this.minutes.signedBy) ? [...this.minutes.signedBy] : []
				if (OC.currentUser && !signers.includes(OC.currentUser)) signers.push(OC.currentUser)
				updated.signedBy = signers
			}

			await store.saveMinutes(updated)
		},
		async generateDraft() {
			const store = useMinutesStore()
			const preview = await store.generateDraft(this.minutesId)
			if (preview) {
				this.draftPreview = preview
				this.showDraftModal = true
			}
		},
		async applyDraft() {
			const store = useMinutesStore()
			await store.saveMinutes({ ...this.minutes, content: this.draftPreview })
			this.showDraftModal = false
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-minutes-detail {
	padding: 8px 4px 24px;
	max-width: 900px;
}

.decidesk-minutes-detail__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 20px;
}

.decidesk-minutes-detail__actions {
	display: flex;
	gap: 8px;
}

.decidesk-minutes-detail__loading {
	display: block;
	margin: 40px auto;
}

.decidesk-minutes-detail__transitions {
	display: flex;
	gap: 8px;
	margin-bottom: 20px;
	flex-wrap: wrap;
}

.decidesk-timeline {
	display: flex;
	gap: 4px;
	margin-bottom: 20px;
	overflow-x: auto;
}

.decidesk-timeline__stage {
	padding: 4px 12px;
	border-radius: 12px;
	font-size: 12px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.decidesk-timeline__stage--done {
	background: var(--color-success-light);
	color: var(--color-success-text);
}

.decidesk-timeline__stage--active {
	background: var(--color-primary-element);
	color: var(--color-primary-text);
	font-weight: 600;
}

.decidesk-detail-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 16px;
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
}

.decidesk-status-badge--draft { color: var(--color-text-maxcontrast); }

.decidesk-status-badge--review { background: var(--color-warning-light); color: var(--color-warning-text); }

.decidesk-status-badge--approved { background: var(--color-success-light); color: var(--color-success-text); }

.decidesk-status-badge--signed { background: var(--color-primary-light); color: var(--color-primary-text); }

.decidesk-status-badge--published { background: var(--color-success); color: var(--color-main-background); }

.decidesk-minutes-content,
.decidesk-draft-preview {
	white-space: pre-wrap;
	word-break: break-word;
	font-family: var(--font-face);
	font-size: 14px;
	line-height: 1.6;
	max-height: 400px;
	overflow-y: auto;
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
