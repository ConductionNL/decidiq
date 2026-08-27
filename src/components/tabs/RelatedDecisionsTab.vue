<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: typed peer relations between decisions (decision-detail-fullpicture
 C6, Part B / REQ-RTU-002).

 Posture: outgoing typed relations are full CRUD (add an existing decision via
 the add modal; remove via CnDeleteDialog); derived incoming relations are
 read-only (removable only from their source). The five relation types are
 supersedes / repeals / amends / implements / refersTo (design D1 — amends is
 the existing relation, not a second copy). Effect-bearing for effective-status
 is supersedes + repeals only; the banner itself lives on DecisionRouteTab.

 Relation CRUD stays on the OpenRegister object API via useObjectStore (ADR-022,
 no pass-through controller). Server validation (self-reference, cycle,
 authority) is surfaced inline in the add modal.

 @spec openspec/specs/relation-tab-ui/spec.md
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--related"
		data-testid="related-decisions-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Related decisions') }}
			</h3>
			<NcButton
				variant="primary"
				data-testid="related-decisions-add"
				:aria-label="t('decidiq', 'Add related decision')"
				@click="openAdd">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidiq', 'Add relation') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load related decisions')">
			{{ error }}
		</CnNoteCard>

		<p v-else-if="loading" class="decidiq-tab__loading">
			{{ t('decidiq', 'Loading related decisions…') }}
		</p>

		<template v-else>
			<CnNoteCard
				v-if="!hasAnyRelation"
				type="info"
				data-testid="related-decisions-empty"
				:title="t('decidiq', 'No related decisions')">
				{{
					t(
						'decidiq',
						'This decision has no typed links to other decisions yet.',
					)
				}}
			</CnNoteCard>

			<!-- Outgoing groups (removable). -->
			<section
				v-for="group in outgoingGroups"
				v-show="group.rows.length"
				:key="'out-' + group.type"
				class="decidiq-related__group"
				:data-testid="'related-out-' + group.type">
				<h4 class="decidiq-related__group-title">{{ group.label }}</h4>
				<ul class="decidiq-related__list">
					<li
						v-for="row in group.rows"
						:key="group.type + '-' + (row.id || row.uuid)"
						class="decidiq-related__row"
						:data-testid="'related-row-' + (row.id || row.uuid)">
						<button
							class="decidiq-related__link"
							type="button"
							@click="openDecision(row)">
							{{ row.title || row.id || row.uuid }}
						</button>
						<CnStatusBadge
							v-if="row.lifecycle"
							:label="row.lifecycle"
							:colorMap="{}" />
						<NcButton
							variant="tertiary"
							:aria-label="t('decidiq', 'Remove relation')"
							:data-testid="'related-remove-' + (row.id || row.uuid)"
							@click="askRemove(group.type, row)">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
						</NcButton>
					</li>
				</ul>
			</section>

			<!-- Incoming groups (read-only). -->
			<section
				v-for="group in incomingGroups"
				v-show="group.rows.length"
				:key="'in-' + group.type"
				class="decidiq-related__group"
				:data-testid="'related-in-' + group.type">
				<h4 class="decidiq-related__group-title">{{ group.label }}</h4>
				<ul class="decidiq-related__list">
					<li
						v-for="row in group.rows"
						:key="'in-' + group.type + '-' + (row.id || row.uuid)"
						class="decidiq-related__row decidiq-related__row--incoming"
						:data-testid="'related-incoming-' + (row.id || row.uuid)">
						<button
							class="decidiq-related__link"
							type="button"
							@click="openDecision(row)">
							{{ row.title || row.id || row.uuid }}
						</button>
						<CnStatusBadge
							v-if="row.lifecycle"
							:label="row.lifecycle"
							:colorMap="{}" />
					</li>
				</ul>
			</section>
		</template>

		<RelatedDecisionAddModal
			v-if="addOpen"
			ref="addModal"
			:typeOptions="typeOptions"
			:searchFn="searchDecisions"
			@confirm="onAddConfirm"
			@close="addOpen = false" />

		<CnDeleteDialog
			v-if="removeTarget"
			ref="removeDialog"
			:item="removeTarget.row"
			nameField="title"
			:dialogTitle="t('decidiq', 'Remove relation')"
			@confirm="confirmRemove"
			@close="removeTarget = null" />
	</div>
</template>

<script>
import { CnDeleteDialog, CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import RelatedDecisionAddModal from '../../modals/RelatedDecisionAddModal.vue'
import { ensureRelationType } from './useRelationStore.js'

// The five typed peer-relation fields on Decision (design D1). amends is the
// existing relation (decision modifies decision); supersedes/repeals are
// effect-bearing for effective-status; implements/refersTo are informational.
const RELATION_TYPES = ['supersedes', 'repeals', 'amends', 'implements', 'refersTo']

export default {
	name: 'RelatedDecisionsTab',
	components: {
		CnDeleteDialog,
		CnNoteCard,
		CnStatusBadge,
		NcButton,
		Plus,
		TrashCanOutline,
		RelatedDecisionAddModal,
	},

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			decision: null,
			outgoing: {},
			incoming: {},
			addOpen: false,
			removeTarget: null,
		}
	},

	computed: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		typeOptions() {
			return RELATION_TYPES.map((type) => ({
				value: type,
				label: this.outgoingLabel(type),
			}))
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		outgoingGroups() {
			return RELATION_TYPES.map((type) => ({
				type,
				label: this.outgoingLabel(type),
				rows: this.outgoing[type] || [],
			}))
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		incomingGroups() {
			return RELATION_TYPES.map((type) => ({
				type,
				label: this.incomingLabel(type),
				rows: this.incoming[type] || [],
			}))
		},

		hasAnyRelation() {
			return (
				this.outgoingGroups.some((g) => g.rows.length)
				|| this.incomingGroups.some((g) => g.rows.length)
			)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/relation-tab-ui/spec.md */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * @spec openspec/specs/decision-evolution-and-cascade/spec.md
		 */
		outgoingLabel(type) {
			const labels = {
				supersedes: this.t('decidiq', 'Supersedes'),
				repeals: this.t('decidiq', 'Repeals'),
				amends: this.t('decidiq', 'Amends'),
				implements: this.t('decidiq', 'Implements'),
				refersTo: this.t('decidiq', 'Refers to'),
			}
			return labels[type] || type
		},

		/**
		 * @spec openspec/specs/decision-evolution-and-cascade/spec.md
		 */
		incomingLabel(type) {
			const labels = {
				supersedes: this.t('decidiq', 'Superseded by'),
				repeals: this.t('decidiq', 'Repealed by'),
				amends: this.t('decidiq', 'Amended by'),
				implements: this.t('decidiq', 'Implemented by'),
				refersTo: this.t('decidiq', 'Referenced by'),
			}
			return labels[type] || type
		},

		refId(ref) {
			if (!ref) return ''
			return typeof ref === 'object' ? ref.id || ref.uuid || '' : ref
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async refresh() {
			// Empty parent short-circuits without fetching (REQ-RTU-002).
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			this.outgoing = {}
			this.incoming = {}
			try {
				const store = ensureRelationType('decision')
				this.decision = await store.fetchObject('decision', this.objectId)
				const selfId =
					this.decision?.id || this.decision?.uuid || String(this.objectId)

				// Outgoing: resolve each id in the relation arrays to its decision.
				const allDecisions = await store.fetchCollection('decision', {
					_limit: 500,
				})
				const byId = new Map()
				for (const d of allDecisions || []) byId.set(d.id || d.uuid, d)

				const out = {}
				const inc = {}
				for (const type of RELATION_TYPES) {
					const refs = Array.isArray(this.decision?.[type])
						? this.decision[type]
						: []
					out[type] = refs
						.map((r) => byId.get(this.refId(r)))
						.filter(Boolean)
					// Incoming: any decision whose `type` array contains selfId.
					inc[type] = (allDecisions || []).filter((d) => {
						const arr = Array.isArray(d[type]) ? d[type] : []
						return arr.some((r) => this.refId(r) === selfId)
					})
				}
				this.outgoing = out
				this.incoming = inc
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Failed to load related decisions.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param query
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async searchDecisions(query) {
			const store = ensureRelationType('decision')
			const params = { _limit: 25 }
			if (query) params._search = query
			const results = await store.fetchCollection('decision', params)
			const selfId =
				this.decision?.id || this.decision?.uuid || String(this.objectId)
			// Exclude self so the obvious self-reference cannot be picked.
			return (results || []).filter((d) => (d.id || d.uuid) !== selfId)
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		openAdd() {
			this.addOpen = true
		},

		/**
		 * @param root0
		 * @param root0.type
		 * @param root0.target
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async onAddConfirm({ type, target }) {
			const targetId = this.refId(target)
			if (!targetId || !type) {
				this.$refs.addModal?.setError(
					this.t(
						'decidiq',
						'Select a relation type and a target decision.',
					),
				)
				return
			}
			try {
				const store = ensureRelationType('decision')
				const existing = Array.isArray(this.decision?.[type])
					? this.decision[type].map((r) => this.refId(r))
					: []
				if (existing.includes(targetId)) {
					this.$refs.addModal?.setError(
						this.t('decidiq', 'That relation already exists.'),
					)
					return
				}
				const next = [...existing, targetId]
				await store.saveObject('decision', {
					id: this.objectId,
					[type]: next,
				})
				this.addOpen = false
				await this.refresh()
			} catch (e) {
				// Surface the server's validation (self-reference, cycle, authority) inline.
				this.$refs.addModal?.setError(
					e?.message
						|| this.t('decidiq', 'The server rejected this relation.'),
				)
			}
		},

		/**
		 * @param type
		 * @param row
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		askRemove(type, row) {
			this.removeTarget = { type, row }
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async confirmRemove() {
			const { type, row } = this.removeTarget
			const rowId = row.id || row.uuid
			try {
				const store = ensureRelationType('decision')
				const next = (
					Array.isArray(this.decision?.[type]) ? this.decision[type] : []
				)
					.map((r) => this.refId(r))
					.filter((id) => id !== rowId)
				await store.saveObject('decision', {
					id: this.objectId,
					[type]: next,
				})
				this.$refs.removeDialog?.setResult({ success: true })
				this.removeTarget = null
				await this.refresh()
			} catch (e) {
				this.$refs.removeDialog?.setResult({
					error: e?.message || this.t('decidiq', 'Remove failed.'),
				})
			}
		},

		/**
		 * @param row
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		openDecision(row) {
			const id = row.id || row.uuid
			if (!id) return
			this.$router.push({ name: 'DecisionDetail', params: { id } })
		},
	},
}
</script>

<style scoped>
.decidiq-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidiq-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidiq-tab__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidiq-related__group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.decidiq-related__group-title {
	margin: 4px 0 0;
	font-size: 0.9rem;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.decidiq-related__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.decidiq-related__row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.decidiq-related__row--incoming .decidiq-related__link {
	color: var(--color-text-maxcontrast);
}

.decidiq-related__link {
	background: none;
	border: none;
	padding: 0;
	color: var(--color-primary-element);
	cursor: pointer;
	text-align: start;
	flex: 1;
}

.decidiq-related__link:hover {
	text-decoration: underline;
}
</style>
