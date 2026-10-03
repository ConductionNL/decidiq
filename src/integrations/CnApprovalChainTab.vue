<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CnApprovalChainTab — the whole sign-off route travelling a host object, for
 the `decidiq-approval-chain` integration leaf (ADR-019 / ADR-066).

 Where the widget shows the step in front of you, the tab shows the route: every
 step, who it was asked of, what they did and why, and when each step was due.
 It is read-only on purpose. Acting belongs on the step that is live, which the
 widget already draws; a second set of buttons here would be a second way to do
 the same thing, drifting from the first.

 A REASON IS PART OF THE RECORD, not a tooltip. The reason somebody returned a
 document is the most useful line on this surface, so it is rendered under the
 step rather than hidden behind a hover nobody on a keyboard can reach.
-->
<template>
	<div class="cn-approval-chain-tab">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="error" class="cn-approval-chain-tab__error" role="alert">
			{{ error }}
		</div>

		<NcEmptyContent
			v-else-if="stages.length === 0"
			:name="emptyLabel"
			:description="emptyDescription">
			<template #icon>
				<Signature :size="20" />
			</template>
		</NcEmptyContent>

		<ol v-else class="cn-approval-chain-tab__list">
			<li
				v-for="stage in stages"
				:key="rowKey(stage)"
				class="cn-approval-chain-tab__row"
				:data-testid="`cn-approval-chain-step-${stage.sequence}`">
				<div class="cn-approval-chain-tab__head">
					<span class="cn-approval-chain-tab__label">
						{{ stage.label || stepFallback(stage) }}
					</span>
					<CnStatusBadge
						size="small"
						:variant="variantOf(stage)"
						:label="statusLabel(stage)" />
				</div>

				<p class="cn-approval-chain-tab__meta">
					{{ actorLabel(stage) }}
					<span v-if="stage.dueAt"> · {{ dueLabel(stage) }}</span>
				</p>

				<ul
					v-if="actionsFor(stage).length > 0"
					class="cn-approval-chain-tab__actions">
					<li
						v-for="(action, idx) in actionsFor(stage)"
						:key="`${rowKey(stage)}-${idx}`"
						class="cn-approval-chain-tab__action">
						<span class="cn-approval-chain-tab__verb">{{
							verbLabel(action)
						}}</span>
						<span
							v-if="reasonOf(action)"
							class="cn-approval-chain-tab__reason">
							{{ reasonOf(action) }}
						</span>
					</li>
				</ul>
			</li>
		</ol>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import Signature from 'vue-material-design-icons/Signature.vue'
import {
	actionsByStep,
	isOverdue,
	listActions,
	listStages,
	objId,
} from './approvalChainLink.js'

/**
 * CnApprovalChainTab — the full timeline of a host object's sign-off route.
 */
export default {
	name: 'CnApprovalChainTab',

	components: {
		CnStatusBadge,
		NcEmptyContent,
		NcLoadingIcon,
		Signature,
	},

	props: {
		/** Stable integration id (forwarded from the registry). */
		integrationId: { type: String, default: 'decidiq-approval-chain' },
		/** OpenRegister register id of the HOST object. */
		register: { type: String, default: '' },
		/** OpenRegister schema id of the HOST object. */
		schema: { type: String, default: '' },
		/** UUID of the HOST object the route travels. */
		objectId: { type: [String, Number], default: '' },
		/** Whole integration context, as a fallback when discrete props are absent. */
		integrationContext: { type: Object, default: () => ({}) },
		/** Rendering surface (AD-19). */
		surface: { type: String, default: 'single-entity' },
	},

	data() {
		return {
			stages: [],
			byStep: {},
			loading: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		hostObjectId() {
			return String(this.objectId || this.integrationContext.objectId || '')
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		emptyLabel() {
			return t('decidiq', 'No sign-off route on this object yet.')
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		emptyDescription() {
			return t(
				'decidiq',
				'Once someone asks colleagues to sign off on this, every step appears here with what they did and why.',
			)
		},
	},

	watch: {
		hostObjectId: 'load',
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the route travelling the host object.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		async load() {
			if (!this.hostObjectId) {
				this.stages = []
				return
			}
			this.loading = true
			this.error = ''
			try {
				const [stages, actions] = await Promise.all([
					listStages(this.hostObjectId),
					listActions(this.hostObjectId),
				])
				this.stages = stages
				this.byStep = actionsByStep(actions)
			} catch {
				this.error = t('decidiq', 'The sign-off route could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Everything recorded against a step, oldest first.
		 *
		 * @param {object} stage The stage.
		 * @return {object[]} The actions.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		actionsFor(stage) {
			return this.byStep[String(Number(stage.sequence || 0))] || []
		},

		/**
		 * What was done, and by whom.
		 *
		 * A lapse is named as one: `system` in an actor column reads like a user
		 * account nobody can find, where "no answer within the term" says what
		 * actually happened.
		 *
		 * @param {object} action The action.
		 * @return {string} The line.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		verbLabel(action) {
			const verb = String(action.action || '')
			if (verb === 'lapsed') {
				return t('decidiq', 'No answer within the term')
			}
			const actor = String(action.actor || '')
			const onBehalfOf = String(action.onBehalfOf || '')
			if (onBehalfOf !== '') {
				return t('decidiq', '{verb} by {actor}, on behalf of {onBehalfOf}', {
					verb,
					actor,
					onBehalfOf,
				})
			}
			return t('decidiq', '{verb} by {actor}', { verb, actor })
		},

		/**
		 * A stable key for a stage row.
		 *
		 * @param {object} stage The stage.
		 * @return {string} The key.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		rowKey(stage) {
			return objId(stage) || `step-${stage.sequence}`
		},

		/**
		 * A label for a stage the route gave none.
		 *
		 * @param {object} stage The stage.
		 * @return {string} The label.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		stepFallback(stage) {
			return t('decidiq', 'Step {step}', { step: Number(stage.sequence || 0) })
		},

		/**
		 * Who the step was asked of.
		 *
		 * @param {object} stage The stage.
		 * @return {string} The line.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		actorLabel(stage) {
			const actor = String(stage.assignedPerson || stage.assignedBody || '')
			if (actor === '') {
				return t('decidiq', 'Nobody assigned')
			}
			if (String(stage.substituteActor || '') !== '') {
				return t('decidiq', '{actor}, with {substitute} standing in', {
					actor,
					substitute: String(stage.substituteActor),
				})
			}
			return actor
		},

		/**
		 * When the step was due, and whether that has passed.
		 *
		 * @param {object} stage The stage.
		 * @return {string} The line.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		dueLabel(stage) {
			const date = new Date(String(stage.dueAt)).toLocaleDateString()
			if (isOverdue(stage)) {
				return t('decidiq', 'was due {date}', { date })
			}
			return t('decidiq', 'due {date}', { date })
		},

		/**
		 * What was said when the action was taken.
		 *
		 * Read off the ACTION and never off the stage: a stage's `note` holds
		 * the subject's schema slug, and rendering it here would print
		 * `decision` under every step as though somebody had written it.
		 *
		 * @param {object} action The action.
		 * @return {string} The reason, or ''.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		reasonOf(action) {
			return String(action.comment || action.advice || '')
		},

		/**
		 * The badge label for a stage.
		 *
		 * @param {object} stage The stage.
		 * @return {string} The label.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		statusLabel(stage) {
			const status = String(stage.status || '')
			const outcome = String(stage.outcome || '')
			if (status === 'active' && isOverdue(stage)) {
				return t('decidiq', 'Term passed')
			}
			if (status === 'active') {
				return t('decidiq', 'Waiting')
			}
			if (status === 'pending') {
				return t('decidiq', 'Not yet asked')
			}
			if (outcome !== '') {
				return outcome
			}
			return status
		},

		/**
		 * The badge variant for a stage.
		 *
		 * @param {object} stage The stage.
		 * @return {string} The variant.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		variantOf(stage) {
			const status = String(stage.status || '')
			const outcome = String(stage.outcome || '')
			if (status === 'active') {
				return isOverdue(stage) ? 'error' : 'info'
			}
			if (outcome === 'rejected' || outcome === 'returned') {
				return 'error'
			}
			if (outcome !== '') {
				return 'success'
			}
			return 'neutral'
		},
	},
}
</script>

<style scoped>
.cn-approval-chain-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.cn-approval-chain-tab__row {
	border-inline-start: 2px solid var(--color-border);
	padding: 8px 0 8px 12px;
	margin-bottom: 4px;
}

.cn-approval-chain-tab__head {
	display: flex;
	align-items: center;
	gap: 8px;
}

.cn-approval-chain-tab__label {
	font-weight: bold;
	color: var(--color-main-text);
}

.cn-approval-chain-tab__meta {
	margin: 2px 0 0 0;
	color: var(--color-text-maxcontrast);
}

.cn-approval-chain-tab__reason {
	margin: 4px 0 0 0;
	color: var(--color-main-text);
	font-style: italic;
}

.cn-approval-chain-tab__error {
	color: var(--color-error);
}
</style>
