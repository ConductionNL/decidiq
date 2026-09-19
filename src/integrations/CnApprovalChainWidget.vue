<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CnApprovalChainWidget — the current step of the sign-off route travelling a
 host object, for the `decidiq-approval-chain` integration leaf (ADR-019 /
 ADR-066).

 It shows one thing well: which step the route is on, who it is waiting on,
 when it is due, and whether that date has passed. For the person whose turn it
 actually is, and only for them, it draws approve and reject.

 A REJECTION NEEDS A REASON. Not as politeness: the next person to open the
 document has to know what to change, and "rejected" on its own sends them back
 to whoever rejected it to ask. The button stays disabled until there is one.

 THE BUTTONS ARE A COURTESY, NOT THE GUARD. Whether they are drawn is decided
 here; whether the action is allowed is decided by decidiq's controller, which
 refuses an action by anybody the live step does not name. Someone who forges
 the request gets the same refusal as someone who never saw a button.

 It invokes nothing in the consuming app (ADR-066 decision 2): every write goes
 to decidiq's own controller.
-->
<template>
	<CnDetailCard :title="cardTitle" :icon="cardIcon" :collapsible="collapsible">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="error" class="cn-approval-chain__error" role="alert">
			{{ error }}
		</div>

		<div v-else-if="stages.length === 0" class="cn-approval-chain__empty">
			{{ emptyLabel }}
		</div>

		<div v-else-if="!current" class="cn-approval-chain__done">
			{{ concludedLabel }}
		</div>

		<template v-else>
			<div class="cn-approval-chain__step">
				<span class="cn-approval-chain__step-number">
					{{ stepLabel }}
				</span>
				<CnStatusBadge
					size="small"
					:variant="overdue ? 'error' : 'info'"
					:label="overdue ? overdueLabel : dueLabel" />
			</div>

			<p class="cn-approval-chain__actor">
				{{ waitingOnLabel }}
			</p>

			<div v-if="mayAct" class="cn-approval-chain__actions">
				<NcTextField
					v-model="reason"
					:label="reasonLabel"
					:placeholder="reasonLabel"
					class="cn-approval-chain__reason" />
				<NcButton
					variant="primary"
					:disabled="busy"
					data-testid="cn-approval-chain-approve"
					@click="act('approved')">
					<template #icon>
						<Check :size="18" />
					</template>
					{{ approveLabel }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="busy || reason.trim() === ''"
					data-testid="cn-approval-chain-reject"
					@click="act('rejected')">
					<template #icon>
						<Close :size="18" />
					</template>
					{{ rejectLabel }}
				</NcButton>
			</div>
		</template>
	</CnDetailCard>
</template>

<script>
import { CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Signature from 'vue-material-design-icons/Signature.vue'
import {
	isCurrentActor,
	isOverdue,
	listStages,
	liveStage,
	recordAction,
} from './approvalChainLink.js'

/**
 * CnApprovalChainWidget — the live step of a host object's sign-off route,
 * with approve and reject for the person it is waiting on.
 */
export default {
	name: 'CnApprovalChainWidget',

	components: {
		CnDetailCard,
		CnStatusBadge,
		NcButton,
		NcLoadingIcon,
		NcTextField,
		Check,
		Close,
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
		surface: { type: String, default: 'detail-page' },
		/**
		 * Whether the card body is collapsible. Defaulted TRUE deliberately,
		 * matching the decisions leaf beside it: a card that cannot be folded
		 * away is a card that pushes the host's own content off the screen.
		 */
		// eslint-disable-next-line vue/no-boolean-default
		collapsible: { type: Boolean, default: true },
	},

	data() {
		return {
			stages: [],
			loading: false,
			busy: false,
			error: '',
			reason: '',
		}
	},

	computed: {
		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		cardTitle() {
			return t('decidiq', 'Parafering')
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		cardIcon() {
			return Signature
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		hostObjectId() {
			return String(this.objectId || this.integrationContext.objectId || '')
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		hostSchema() {
			return String(this.schema || this.integrationContext.schema || '')
		},

		/**
		 * The step the route is on.
		 *
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		current() {
			return liveStage(this.stages)
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009) */
		overdue() {
			return isOverdue(this.current)
		},

		/**
		 * Whether to draw the actions. Not the authorisation: see the file
		 * docblock.
		 *
		 * Spec: openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 *
		 * @return {boolean} True when it is this user's turn.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		mayAct() {
			const user = getCurrentUser()
			return (
				String(this.current?.status || '') === 'active'
				&& isCurrentActor(this.current, user && user.uid)
			)
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		stepLabel() {
			return t('decidiq', 'Step {step} of {total}', {
				step: Number(this.current?.sequence || 0),
				total: this.stages.length,
			})
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		waitingOnLabel() {
			const actor = String(this.current?.assignedPerson || '')
			if (actor === '') {
				return t('decidiq', 'This step has nobody assigned yet.')
			}
			return t('decidiq', 'Waiting on {actor}', { actor })
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009) */
		dueLabel() {
			const dueAt = String(this.current?.dueAt || '')
			if (dueAt === '') {
				return t('decidiq', 'No term set')
			}
			return t('decidiq', 'Due {date}', {
				date: new Date(dueAt).toLocaleDateString(),
			})
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-009) */
		overdueLabel() {
			return t('decidiq', 'Term passed')
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		emptyLabel() {
			return t('decidiq', 'No sign-off route on this object yet.')
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		concludedLabel() {
			return t('decidiq', 'Every step of this route has been taken.')
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		approveLabel() {
			return t('decidiq', 'Approve')
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		rejectLabel() {
			return t('decidiq', 'Reject')
		},

		/** @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010) */
		reasonLabel() {
			return t('decidiq', 'Reason, required to reject')
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
				this.stages = await listStages(this.hostObjectId)
			} catch {
				this.error = t('decidiq', 'The sign-off route could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Record an action on the live step, through decidiq's controller.
		 *
		 * @param {string} verb The action, `approved` or `rejected`.
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
		 */
		async act(verb) {
			if (!this.current || this.busy) return
			this.busy = true
			this.error = ''
			try {
				await recordAction({
					subject: this.hostObjectId,
					subjectSchema: this.hostSchema,
					step: Number(this.current.sequence || 0),
					action: verb,
					comment: this.reason,
				})
				this.reason = ''
				// Re-read rather than patch the local copy: the engine decides
				// which step becomes live next, and guessing it here is how a
				// widget starts disagreeing with the register it is showing.
				await this.load()
			} catch (refusal) {
				// The engine's refusals are the point of the engine, so the
				// signer sees the reason rather than a generic failure.
				this.error = String(
					(refusal
						&& refusal.response
						&& refusal.response.data
						&& refusal.response.data.message)
						|| t('decidiq', 'That action was refused.'),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.cn-approval-chain__step {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.cn-approval-chain__step-number {
	font-weight: bold;
	color: var(--color-main-text);
}

.cn-approval-chain__actor,
.cn-approval-chain__empty,
.cn-approval-chain__done {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px 0;
}

.cn-approval-chain__error {
	color: var(--color-error);
}

.cn-approval-chain__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}

.cn-approval-chain__reason {
	flex: 1 1 200px;
}
</style>
