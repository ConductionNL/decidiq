<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Detail widget: a decision put to a body in writing (voting-written-resolution).

 The chair, vice-chair or secretary of a body puts a decision without a
 meeting to the body; each entitled member answers here; everyone who can
 read the decision sees the progress and, after closing, the full record.
 The server decides who may open and who may answer.
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--written"
		data-testid="written-resolution-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ title }}
			</h3>
			<NcButton
				v-if="canOpen"
				variant="primary"
				data-testid="written-resolution-open"
				@click="showModal = true">
				{{ t('decidiq', 'Decide in writing') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load the written procedure')">
			{{ error }}
		</CnNoteCard>

		<p
			v-if="!loading && !error && !round"
			class="decidiq-tab__none"
			data-testid="written-resolution-none">
			{{ t('decidiq', 'This decision has not been put to a body in writing.') }}
		</p>

		<template v-if="round">
			<p class="decidiq-tab__meta" data-testid="written-resolution-summary">
				{{
					t('decidiq', '{body} · {rule} · deadline {deadline}', {
						body: bodyName,
						rule: ruleLabel,
						deadline: formatDate(round.votingDeadline),
					})
				}}
			</p>
			<p class="decidiq-tab__progress" data-testid="written-resolution-progress">
				{{
					t('decidiq', '{answered} of {total} answered', progress)
				}}
			</p>
			<CnNoteCard
				v-if="waiting"
				type="warning"
				data-testid="written-resolution-waiting">
				{{ t('decidiq', 'Deadline passed, waiting to close') }}
			</CnNoteCard>

			<div
				v-if="canAnswer"
				class="decidiq-tab__answers"
				role="group"
				:aria-label="t('decidiq', 'Your answer')"
				data-testid="written-resolution-answers">
				<NcButton
					v-for="answer in answers"
					:key="answer.id"
					:variant="myAnswer === answer.id ? 'primary' : 'secondary'"
					:pressed="myAnswer === answer.id"
					:disabled="saving"
					:data-testid="'written-answer-' + answer.id"
					@click="answerWith(answer.id)">
					{{ answer.label }}
				</NcButton>
			</div>
			<p v-if="answerError" class="decidiq-tab__error" role="alert">
				{{ answerError }}
			</p>

			<p
				v-if="!open"
				class="decidiq-tab__result"
				data-testid="written-resolution-result">
				<CnStatusBadge
					:label="round.result || ''"
					:colorMap="resultColors" />
				{{ reasonLabel }}
			</p>

			<CnDataTable
				:columns="columns"
				:rows="rows"
				rowKey="membership"
				:emptyText="t('decidiq', 'Nobody was asked to answer.')">
				<template #column-value="{ value }">
					<CnStatusBadge
						v-if="value"
						:label="answerLabel(value)"
						:colorMap="answerColors" />
					<span v-else>{{ t('decidiq', 'No answer') }}</span>
				</template>
				<template #column-castAt="{ value }">
					{{ value ? formatDate(value) : '' }}
				</template>
			</CnDataTable>
		</template>

		<WrittenResolutionModal
			v-if="showModal"
			:decisionId="String(objectId)"
			:bodies="bodies"
			@close="showModal = false"
			@saved="onOpened" />
	</div>
</template>

<script>
import { CnDataTable, CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton } from '@nextcloud/vue'
import WrittenResolutionModal from '../../modals/WrittenResolutionModal.vue'
import {
	answerWrittenRound,
	canPutInWriting,
	isOpen,
	latestWrittenRound,
	presidingBodyIds,
	progressOf,
	recordRows,
	refId,
	refusalOf,
	waitsToClose,
} from '../../utils/writtenRound.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'WrittenResolutionTab',
	components: { CnDataTable, CnNoteCard, CnStatusBadge, NcButton, WrittenResolutionModal },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			saving: false,
			error: '',
			answerError: '',
			decision: null,
			round: null,
			votes: [],
			names: {},
			bodyName: '',
			bodies: [],
			showModal: false,
		}
	},

	computed: {
		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure */
		title() {
			if (this.round && !this.open) return this.t('decidiq', 'Decided in writing')
			return this.t('decidiq', 'Written resolution')
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure */
		open() {
			return isOpen(this.round)
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure */
		waiting() {
			return waitsToClose(this.round, new Date())
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure */
		rows() {
			return this.round ? recordRows(this.round, this.votes, this.names) : []
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page */
		progress() {
			return progressOf(this.rows)
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page */
		uid() {
			return getCurrentUser()?.uid || ''
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page */
		canAnswer() {
			return this.open && (this.round.electorateUserIds || []).includes(this.uid)
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page */
		myAnswer() {
			return this.rows.find((row) => row.userId === this.uid)?.value || null
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing */
		canOpen() {
			return this.bodies.length > 0 && canPutInWriting(this.decision) && !this.open
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page */
		answers() {
			return [
				{ id: 'for', label: this.t('decidiq', 'For') },
				{ id: 'against', label: this.t('decidiq', 'Against') },
				{ id: 'abstain', label: this.t('decidiq', 'Abstain') },
				{ id: 'objection', label: this.t('decidiq', 'Discuss this in a meeting') },
			]
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure */
		columns() {
			return [
				{ key: 'name', label: this.t('decidiq', 'Member') },
				{ key: 'value', label: this.t('decidiq', 'Answer') },
				{ key: 'castAt', label: this.t('decidiq', 'Answered at') },
			]
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure */
		ruleLabel() {
			if (this.round?.writtenRule === 'threshold') {
				return this.t('decidiq', 'threshold {threshold} of the whole body', { threshold: this.round.voteThreshold || 'simple-majority' })
			}
			return this.t('decidiq', 'unanimous')
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure */
		reasonLabel() {
			return {
				'all-answered': this.t('decidiq', 'All answered'),
				'outcome-settled': this.t('decidiq', 'The outcome could no longer change'),
				objection: this.t('decidiq', 'A member asked to discuss it in a meeting'),
				deadline: this.t('decidiq', 'The deadline passed'),
			}[this.round?.closedReason] || ''
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure */
		answerColors() {
			return { [this.answerLabel('for')]: 'success', [this.answerLabel('against')]: 'error', [this.answerLabel('objection')]: 'warning' }
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure */
		resultColors() {
			return { adopted: 'success', rejected: 'error' }
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				this.decision = await ensureRelationType('decision').fetchObject('decision', this.objectId)
				await Promise.all([this.loadRound(), this.loadBodies()])
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'Failed to load the written procedure.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The decision's latest written round, its votes, member names and body.
		 *
		 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure
		 */
		async loadRound() {
			const rounds = await ensureRelationType('voting-round').fetchCollection('voting-round', {
				decision: String(this.objectId),
				_limit: 20,
			})
			this.round = latestWrittenRound(rounds)
			if (!this.round) return
			const [votes] = await Promise.all([
				ensureRelationType('vote').fetchCollection('vote', { votingRound: this.round.id, _limit: 500 }),
				this.loadNames(),
			])
			this.votes = Array.isArray(votes) ? votes : []
		},

		/**
		 * The names of the electorate and the body.
		 *
		 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-005-the-decision-keeps-a-readable-record-of-the-written-procedure
		 */
		async loadNames() {
			const personStore = ensureRelationType('person')
			const names = {}
			for (const member of this.round.electorate || []) {
				if (!member.person || names[member.person]) continue
				const person = await personStore.fetchObject('person', member.person).catch(() => null)
				if (person?.name) names[member.person] = person.name
			}
			this.names = names
			const body = await ensureRelationType('governance-body')
				.fetchObject('governance-body', refId(this.round.governanceBody))
				.catch(() => null)
			this.bodyName = body?.name || refId(this.round.governanceBody)
		},

		/**
		 * The bodies the signed-in user presides over, for the open button.
		 *
		 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
		 */
		async loadBodies() {
			this.bodies = []
			if (!this.uid || !canPutInWriting(this.decision)) return
			const persons = await ensureRelationType('person').fetchCollection('person', { nextcloudUserId: this.uid, _limit: 5 })
			const person = (persons || []).find((p) => p.nextcloudUserId === this.uid)
			if (!person) return
			const memberships = await ensureRelationType('membership').fetchCollection('membership', { person: person.id, _limit: 100 })
			const today = new Date().toISOString().slice(0, 10)
			const bodyStore = ensureRelationType('governance-body')
			const bodies = []
			for (const id of presidingBodyIds(memberships, today)) {
				const body = await bodyStore.fetchObject('governance-body', id).catch(() => null)
				bodies.push({ id, label: body?.name || id })
			}
			this.bodies = bodies
		},

		/**
		 * Answer as the signed-in member; the server refuses anyone outside the electorate.
		 *
		 * @param {string} value The answer.
		 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-002-each-entitled-member-answers-from-the-decision-page
		 */
		async answerWith(value) {
			this.saving = true
			this.answerError = ''
			try {
				await answerWrittenRound(this.round.id, value)
				await this.refresh()
			} catch (e) {
				this.answerError = refusalOf(e, this.t('decidiq', 'Your answer could not be saved.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * After the round opened: close the modal and read it back.
		 *
		 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
		 */
		async onOpened() {
			this.showModal = false
			await this.refresh()
		},

		/**
		 * The label of an answer value.
		 *
		 * @param {string} value The value.
		 * @return {string} The label.
		 */
		answerLabel(value) {
			return this.answers.find((answer) => answer.id === value)?.label || value
		},

		/**
		 * A date-time for reading.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string} The local date and time.
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? String(value || '') : date.toLocaleString()
		},
	},
}
</script>

<style scoped src="./relationTab.css"></style>

<style scoped>
.decidiq-tab__none,
.decidiq-tab__meta,
.decidiq-tab__progress {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.decidiq-tab__answers {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__result {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: 0;
}

.decidiq-tab__error {
	color: var(--color-error-text);
}
</style>
