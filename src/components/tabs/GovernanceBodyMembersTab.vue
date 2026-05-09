<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: members of a Governance Body.

 Posture: add-existing / remove. Members are participant records that
 reference this governance body via their `governanceBody` field.
 Adding a member rewrites the participant record's `governanceBody`
 pointer; removal clears it. We don't author participants from this
 tab — the standalone /participants index owns that workflow.
-->
<template>
	<div class="decidesk-tab decidesk-tab--members">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Members') }}
				<span v-if="!loading" class="decidesk-tab__count">({{ rows.length }})</span>
			</h3>
			<NcButton
				type="primary"
				:aria-label="t('decidesk', 'Add member')"
				@click="addDialogOpen = true">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'Add member') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load members')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			row-key="id"
			:empty-text="t('decidesk', 'No members linked to this body yet.')"
			:loading-text="t('decidesk', 'Loading members…')">
			<template #row-actions="{ row }">
				<CnRowActions
					:row="row"
					:actions="rowActions" />
			</template>
		</CnDataTable>

		<NcDialog
			v-if="addDialogOpen"
			:name="t('decidesk', 'Add member')"
			@closing="addDialogOpen = false">
			<template #default>
				<p>{{ t('decidesk', 'Pick a participant to link to this governance body.') }}</p>
				<div v-if="loadingCandidates" class="decidesk-tab__loading">
					{{ t('decidesk', 'Loading participants…') }}
				</div>
				<ul v-else-if="candidates.length" class="decidesk-tab__list">
					<li v-for="cand in candidates" :key="cand.id">
						<NcButton @click="linkParticipant(cand)">
							{{ candidateLabel(cand) }}
						</NcButton>
					</li>
				</ul>
				<p v-else class="decidesk-tab__empty">
					{{ t('decidesk', 'No unassigned participants available.') }}
				</p>
			</template>
		</NcDialog>

		<CnDeleteDialog
			v-if="removeTarget"
			ref="removeDialog"
			:item="removeTarget"
			name-field="displayName"
			:dialog-title="t('decidesk', 'Remove member')"
			@confirm="confirmRemove"
			@close="removeTarget = null" />
	</div>
</template>

<script>
import { CnDataTable, CnDeleteDialog, CnNoteCard, CnRowActions } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'GovernanceBodyMembersTab',
	components: { CnDataTable, CnDeleteDialog, CnNoteCard, CnRowActions, NcButton, NcDialog, Plus },
	props: {
		objectId: { type: [String, Number], default: '' },
		objectType: { type: String, default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
	},
	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			addDialogOpen: false,
			loadingCandidates: false,
			candidates: [],
			removeTarget: null,
		}
	},
	computed: {
		columns() {
			return [
				{ key: 'displayName', label: this.t('decidesk', 'Name') },
				{ key: 'role', label: this.t('decidesk', 'Role') },
				{ key: 'party', label: this.t('decidesk', 'Party') },
			]
		},
		rowActions() {
			return [
				{
					label: this.t('decidesk', 'Remove from body'),
					icon: LinkOff,
					destructive: true,
					handler: (row) => { this.removeTarget = { ...row } },
				},
			]
		},
	},
	watch: {
		objectId: {
			immediate: true,
			handler() { this.refresh() },
		},
		addDialogOpen(open) {
			if (open) this.loadCandidates()
		},
	},
	methods: {
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('participant')
				const items = await store.fetchCollection('participant', {
					governanceBody: this.objectId,
					_limit: 100,
				})
				this.rows = items || []
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load members.')
			} finally {
				this.loading = false
			}
		},
		candidateLabel(p) {
			return p.displayName || p.name || p.id
		},
		async loadCandidates() {
			this.loadingCandidates = true
			try {
				const store = ensureRelationType('participant')
				// Fetch participants without a body assignment OR with a different one;
				// the OpenRegister API doesn't support negation filters here, so we
				// fetch a page of all participants and filter client-side.
				const items = await store.fetchCollection('participant', { _limit: 100 })
				this.candidates = (items || []).filter(p => p.governanceBody !== this.objectId)
			} catch {
				this.candidates = []
			} finally {
				this.loadingCandidates = false
			}
		},
		async linkParticipant(participant) {
			const store = ensureRelationType('participant')
			await store.saveObject('participant', { ...participant, governanceBody: this.objectId })
			this.addDialogOpen = false
			this.refresh()
		},
		async confirmRemove() {
			const store = ensureRelationType('participant')
			const target = this.removeTarget
			try {
				await store.saveObject('participant', { ...target, governanceBody: null })
				this.$refs.removeDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.removeDialog?.setResult({ error: e?.message || this.t('decidesk', 'Remove failed.') })
			}
		},
	},
}
</script>

<style scoped>
.decidesk-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}
.decidesk-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}
.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}
.decidesk-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}
.decidesk-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.decidesk-tab__empty,
.decidesk-tab__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
