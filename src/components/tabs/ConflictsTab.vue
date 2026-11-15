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

 A declaring member who registered other positions carries a link to them on
 her profile, and the declare dialog lists the logged-in member's own positions
 for reference (interests-and-integrity, REQ-INT-007, display only).

 @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
 @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
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
				<router-link
					v-if="positionLinks[refId(row.boardMember)]"
					:to="{
						path: profilePath(positionLinks[refId(row.boardMember)]),
					}"
					data-testid="conflict-member-positions">
					{{ t('decidiq', 'Registered other positions') }}
				</router-link>
			</li>
		</ul>
		<ConflictDeclareDialog
			v-if="dialogOpen"
			:busy="posting"
			:error="postError"
			:positions="ownPositions"
			:subjectTitle="subjectTitle"
			@submit="declare"
			@close="closeDialog" />
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import ConflictDeclareDialog from '../../dialogs/ConflictDeclareDialog.vue'
import { hasActivePositions } from '../../utils/ancillaryContext.js'
import {
	declarationPayload,
	declarationsFor,
	DECLARE_PATH,
	isRecusal,
	refId,
} from '../../utils/conflicts.js'
import { profilePath } from '../../utils/memberProfile.js'
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
			// interests-and-integrity (REQ-INT-007): assistive context only.
			subjectTitle: '',
			ownPositions: [],
			positionLinks: {},
		}
	},

	computed: {
		/**
		 * @return {string} The record UUID
		 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
		 */
		subjectId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/** @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page */
		rows() {
			return declarationsFor(this.declarations, this.subjectIds)
		},
	},

	watch: {
		/**
		 * @param {boolean} open Whether the declare dialog just opened
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
		 */
		dialogOpen(open) {
			if (open) this.loadOwnPositions()
		},

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
					this.subjectTitle = motion?.title || ''
					const item = refId(motion?.agendaItem)
					if (item) ids.push(item)
				} else {
					this.loadSubjectTitle()
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
						this.linkPositions(id, personId)
					}
					if (name) this.names = { ...this.names, [id]: name }
				} catch {
					// The row still shows; only the name is missing.
				}
			}
		},

		refId,

		/**
		 * The profile route of a person (REQ-MPR-003), where her other
		 * positions are listed.
		 *
		 * @param {string} personId The person id
		 * @return {string}
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
		 */
		profilePath(personId) {
			return profilePath(personId)
		},

		/**
		 * The title of the agenda item declared on; empty when unreadable.
		 *
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
		 */
		async loadSubjectTitle() {
			try {
				const item = await ensureRelationType('agenda-item').fetchObject(
					'agenda-item',
					this.subjectId,
				)
				this.subjectTitle = item?.title || ''
			} catch {
				this.subjectTitle = ''
			}
		},

		/**
		 * The logged-in member's own ancillary positions, found through her
		 * person record. None when she has no person record or they cannot
		 * be read: the dialog then shows no reference block.
		 *
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
		 */
		async loadOwnPositions() {
			const uid = getCurrentUser()?.uid || ''
			if (!uid) return
			try {
				const people = await ensureRelationType('person').fetchCollection(
					'person',
					{ nextcloudUserId: uid, _limit: 2 },
				)
				const own = (people || []).filter((p) => p?.nextcloudUserId === uid)
				if (own.length !== 1) {
					this.ownPositions = []
					return
				}
				this.ownPositions = await this.positionsOf(own[0].id)
			} catch {
				this.ownPositions = []
			}
		},

		/**
		 * Link a declaring member to her registered other positions when she
		 * has any that have not ended.
		 *
		 * @param {string} memberId The membership that declared
		 * @param {string} personId Its person
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
		 */
		async linkPositions(memberId, personId) {
			try {
				if (hasActivePositions(await this.positionsOf(personId))) {
					this.positionLinks = {
						...this.positionLinks,
						[memberId]: personId,
					}
				}
			} catch {
				// No link; the declaration still shows.
			}
		},

		/**
		 * A person's ancillary positions.
		 *
		 * @param {string} personId The person
		 * @return {Promise<Array<object>>}
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
		 */
		async positionsOf(personId) {
			const positions = await ensureRelationType(
				'ancillary-position',
			).fetchCollection('ancillary-position', {
				person: personId,
				_limit: 50,
			})
			return (positions || []).filter(
				(position) => refId(position?.person) === String(personId),
			)
		},

		/**
		 * @param {object} row A declaration
		 * @return {string} Who declared
		 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
		 */
		memberName(row) {
			return (
				this.names[refId(row.boardMember)] || this.t('decidiq', 'A member')
			)
		},

		/**
		 * @param {string} type The declaration type
		 * @return {string} Its label
		 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
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

		/**
		 * Close the dialog and forget its error.
		 *
		 * @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
		 */
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
