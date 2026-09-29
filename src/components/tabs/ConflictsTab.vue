<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Conflicts of interest on a motion or agenda item (bod-10).

 Lists the declarations on this record (on a motion page also those on the
 agenda item it is tabled under) with who declared, the reason and whether the
 member is recused from the vote. Declare a conflict of interest opens
 ConflictDeclareDialog; the declaration is posted for the logged-in member.

 The record id comes from the route: a manifest custom widget does not receive
 objectId (see MotionVotingRoundTab).

 @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
-->
<template>
	<div class="decidiq-tab" data-testid="conflicts-tab">
		<div class="decidiq-tab__actions">
			<NcButton data-testid="conflict-declare-open" @click="dialogOpen = true">
				{{ t('decidiq', 'Declare a conflict of interest') }}
			</NcButton>
		</div>
		<p v-if="loadError" class="decidiq-error" role="alert">
			{{ loadError }}
		</p>
		<p v-else-if="loading" class="decidiq-tab__muted">
			{{ t('decidiq', 'Loading…') }}
		</p>
		<p v-else-if="rows.length === 0" class="decidiq-tab__muted">
			{{ t('decidiq', 'No one has declared a conflict of interest here.') }}
		</p>
		<ul v-else class="decidiq-tab__list">
			<li
				v-for="row in rows"
				:key="row.id || row.declarationTimestamp"
				data-testid="conflict-row">
				<strong>{{ memberName(row) }}</strong>
				<span>{{ typeLabel(row.declarationType) }}</span>
				<span v-if="row.description">{{ row.description }}</span>
				<span
					v-if="isRecusal(row)"
					class="decidiq-conflict-recused"
					data-testid="conflict-recused">
					{{ t('decidiq', 'Does not vote') }}
				</span>
			</li>
		</ul>
		<ConflictDeclareDialog
			v-if="dialogOpen"
			:busy="posting"
			:error="postError"
			@submit="declare"
			@close="closeDialog" />
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import ConflictDeclareDialog from '../../dialogs/ConflictDeclareDialog.vue'
import {
	declarationPayload,
	declarationsFor,
	DECLARE_PATH,
	isRecusal,
	refId,
} from '../../utils/conflicts.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'ConflictsTab',
	components: { ConflictDeclareDialog, NcButton },

	props: {
		objectId: { type: [String, Number], default: '' },
		/** 'motion' or 'agenda-item': which record the page shows */
		subjectType: { type: String, default: 'agenda-item' },
	},

	data() {
		return {
			loading: false,
			loadError: '',
			declarations: [],
			subjectIds: [],
			names: {},
			dialogOpen: false,
			posting: false,
			postError: '',
		}
	},

	computed: {
		/** @return {string} The record UUID */
		subjectId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/** @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page */
		rows() {
			return declarationsFor(this.declarations, this.subjectIds)
		},
	},

	watch: {
		subjectId: {
			immediate: true,
			/** @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		isRecusal,

		/**
		 * Load the declarations on this record and, for a motion, its agenda item.
		 *
		 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
		 */
		async load() {
			if (!this.subjectId) return
			this.loading = true
			this.loadError = ''
			try {
				const ids = [this.subjectId]
				if (this.subjectType === 'motion') {
					const motion = await ensureRelationType('motion').fetchObject(
						'motion',
						this.subjectId,
					)
					const item = refId(motion?.agendaItem)
					if (item) ids.push(item)
				}
				const store = ensureRelationType('conflict-of-interest')
				const lists = await Promise.all(
					ids.map((id) =>
						store.fetchCollection('conflict-of-interest', {
							agendaItem: id,
							_limit: 100,
						}),
					),
				)
				this.subjectIds = ids
				this.declarations = lists.flat().filter(Boolean)
				this.loadNames()
			} catch {
				this.loadError = this.t(
					'decidiq',
					'Could not load the declarations.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Resolve each declaring member's name (Membership, then its Person).
		 *
		 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
		 */
		async loadNames() {
			const memberships = ensureRelationType('membership')
			const persons = ensureRelationType('person')
			for (const id of new Set(
				this.rows.map((r) => refId(r.boardMember)).filter(Boolean),
			)) {
				if (this.names[id]) continue
				try {
					const membership = await memberships.fetchObject(
						'membership',
						id,
					)
					let name = membership?.label || ''
					const personId = refId(membership?.person)
					if (personId) {
						const person = await persons.fetchObject('person', personId)
						name = person?.name || name
					}
					if (name) this.names = { ...this.names, [id]: name }
				} catch {
					// The row still shows; only the name is missing.
				}
			}
		},

		/**
		 * @param {object} row A declaration
		 * @return {string} Who declared
		 */
		memberName(row) {
			return (
				this.names[refId(row.boardMember)] || this.t('decidiq', 'A member')
			)
		},

		/**
		 * @param {string} type The declaration type
		 * @return {string} Its label
		 */
		typeLabel(type) {
			const labels = {
				'financial-interest': this.t('decidiq', 'Financial interest'),
				'personal-relationship': this.t('decidiq', 'Personal relationship'),
				'competing-business': this.t('decidiq', 'Competing business'),
				'prior-involvement': this.t('decidiq', 'Earlier involvement'),
			}
			return labels[type] || ''
		},

		/**
		 * Post the declaration for the logged-in member.
		 *
		 * @param {object} values The dialog values
		 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
		 */
		async declare(values) {
			this.posting = true
			this.postError = ''
			try {
				const response = await fetch(generateUrl(DECLARE_PATH), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: window.OC?.requestToken,
					},
					body: JSON.stringify(
						declarationPayload({ subjectId: this.subjectId, ...values }),
					),
				})
				if (!response.ok) {
					const data = await response.json().catch(() => ({}))
					this.postError =
						data.message
						|| this.t('decidiq', 'Could not record the declaration.')
					return
				}
				this.closeDialog()
				await this.load()
			} catch {
				this.postError = this.t(
					'decidiq',
					'Could not record the declaration.',
				)
			} finally {
				this.posting = false
			}
		},

		/** Close the dialog and forget its error. */
		closeDialog() {
			this.dialogOpen = false
			this.postError = ''
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

.decidiq-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.decidiq-tab__list li {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	padding-bottom: var(--default-grid-baseline);
	border-bottom: 1px solid var(--color-border);
}

.decidiq-tab__muted {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidiq-conflict-recused {
	padding: 0 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-warning);
	color: var(--color-primary-element-text);
}

.decidiq-error {
	color: var(--color-error-text);
	margin: 0;
}
</style>
