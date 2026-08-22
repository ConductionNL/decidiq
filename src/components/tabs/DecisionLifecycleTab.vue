<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: decision lifecycle state machine.

 Renders the 7-state timeline (done/current/upcoming chips) and the
 transitions the server allows from the current state. Transition
 buttons POST to the guarded endpoint; the server (transition map +
 domain policy + chair/quorum/outcome gates) is authoritative — this
 tab never decides permissibility client-side.
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--lifecycle"
		data-testid="decision-lifecycle-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Lifecycle') }}
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Lifecycle unavailable')">
			{{ error }}
		</CnNoteCard>

		<ol
			v-if="!error"
			class="decidiq-lifecycle__timeline"
			data-testid="lifecycle-timeline">
			<li
				v-for="step in timeline"
				:key="step.state"
				class="decidiq-lifecycle__step"
				:class="'decidiq-lifecycle__step--' + step.status"
				:data-testid="'lifecycle-step-' + step.state">
				<span class="decidiq-lifecycle__marker" aria-hidden="true" />
				<span class="decidiq-lifecycle__label">{{
					stateLabel(step.state)
				}}</span>
				<CnStatusBadge
					v-if="step.status === 'current'"
					:label="t('decidiq', 'Current')"
					:colorMap="{ [t('decidiq', 'Current')]: 'primary' }" />
			</li>
		</ol>

		<div v-if="!error" class="decidiq-lifecycle__actions">
			<h4 class="decidiq-lifecycle__actions-title">
				{{ t('decidiq', 'Available transitions') }}
			</h4>
			<p v-if="!loading && !actions.length" class="decidiq-lifecycle__none">
				{{ t('decidiq', 'No transitions available from this state.') }}
			</p>
			<div class="decidiq-lifecycle__buttons">
				<NcButton
					v-for="action in actions"
					:key="action.action"
					:disabled="busy"
					:data-testid="'lifecycle-action-' + action.action"
					variant="secondary"
					@click="applyTransition(action.action)">
					{{ actionLabel(action.action) }}
					<span
						v-if="action.chairOnly"
						class="decidiq-lifecycle__chair-hint">
						({{ t('decidiq', 'chair only') }})
					</span>
				</NcButton>
			</div>
			<CnNoteCard
				v-if="transitionError"
				type="error"
				:title="t('decidiq', 'Transition rejected')">
				{{ transitionError }}
			</CnNoteCard>
		</div>

		<PublicationPromptModal
			v-if="publishPromptOpen"
			@publish="promptPublish"
			@dismiss="publishPromptOpen = false" />
	</div>
</template>

<script>
import { CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import PublicationPromptModal from '../../modals/PublicationPromptModal.vue'
import { buildTimeline } from './decisionLifecycle.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'DecisionLifecycleTab',
	components: { NcButton, CnNoteCard, CnStatusBadge, PublicationPromptModal },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			transitionError: '',
			lifecycle: 'draft',
			actions: [],
			publishPromptOpen: false,
		}
	},

	computed: {
		/** @spec openspec/specs/decision-management/spec.md */
		timeline() {
			return buildTimeline(this.lifecycle)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/decision-management/spec.md */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * @param state
		 * @spec openspec/specs/decision-management/spec.md
		 */
		stateLabel(state) {
			const labels = {
				draft: this.t('decidiq', 'Draft'),
				proposed: this.t('decidiq', 'Proposed'),
				deliberating: this.t('decidiq', 'Deliberating'),
				voting: this.t('decidiq', 'Voting'),
				decided: this.t('decidiq', 'Decided'),
				enacted: this.t('decidiq', 'Enacted'),
				archived: this.t('decidiq', 'Archived'),
			}
			return labels[state] || state
		},

		/**
		 * @param action
		 * @spec openspec/specs/decision-management/spec.md
		 */
		actionLabel(action) {
			const labels = {
				propose: this.t('decidiq', 'Propose'),
				deliberate: this.t('decidiq', 'Start deliberation'),
				openVoting: this.t('decidiq', 'Open voting'),
				decide: this.t('decidiq', 'Record decision'),
				enact: this.t('decidiq', 'Enact'),
				archive: this.t('decidiq', 'Archive'),
			}
			return labels[action] || action
		},

		/** @spec openspec/specs/decision-management/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const res = await fetch(
					generateUrl(
						`/apps/decidiq/api/decisions/${this.objectId}/transitions`,
					),
					{
						headers: {
							Accept: 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				const body = await res.json()
				if (!res.ok) {
					this.error =
						body?.message
						|| this.t('decidiq', 'Failed to load lifecycle state.')
					return
				}
				this.lifecycle = body.lifecycle || 'draft'
				this.actions = Array.isArray(body.actions) ? body.actions : []
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Failed to load lifecycle state.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param action
		 * @spec openspec/specs/decision-management/spec.md
		 */
		async applyTransition(action) {
			this.busy = true
			this.transitionError = ''
			try {
				const res = await fetch(
					generateUrl(
						`/apps/decidiq/api/decisions/${this.objectId}/transition`,
					),
					{
						method: 'POST',
						headers: {
							Accept: 'application/json',
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ action }),
					},
				)
				const body = await res.json()
				if (!res.ok) {
					this.transitionError =
						body?.message || this.t('decidiq', 'Transition failed.')
					return
				}
				await this.refresh()
				this.$emit('refresh')
				// prompt-on-transition: when a decision reaches `enacted` for a
				// body configured so, offer a NON-BLOCKING publish prompt.
				// Dismissal never publishes.
				if (action === 'enact') {
					await this.maybePromptPublish()
				}
			} catch (e) {
				this.transitionError =
					e?.message || this.t('decidiq', 'Transition failed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Open the non-blocking publish prompt when the decision's governance
		 * body is configured with the `prompt-on-transition` policy for decisions.
		 *
		 * @spec openspec/specs/public-publication/spec.md
		 */
		async maybePromptPublish() {
			try {
				const store = ensureRelationType('decision')
				const decision = await store.fetchObject('decision', this.objectId)
				let bodyId =
					decision?.governanceBody
					|| decision?.relations?.GovernanceBody
					|| decision?.relations?.governanceBody
				if (Array.isArray(bodyId)) bodyId = bodyId[0]
				if (!bodyId) return

				const res = await fetch(
					generateUrl('/apps/decidiq/api/settings/publication-config'),
					{
						headers: {
							Accept: 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				if (!res.ok) return
				const body = await res.json()
				const policy = body?.config?.[bodyId]?.policy?.decision
				if (policy === 'prompt-on-transition') {
					this.publishPromptOpen = true
				}
			} catch (e) {
				// Prompt is best-effort; never block the transition on it.
			}
		},

		/**
		 * Publish from the prompt — calls the same authoritative publish endpoint
		 * as the Publication tab.
		 *
		 * @spec openspec/specs/public-publication/spec.md
		 */
		async promptPublish() {
			this.publishPromptOpen = false
			try {
				await fetch(generateUrl('/apps/decidiq/api/publications'), {
					method: 'POST',
					headers: {
						Accept: 'application/json',
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						sourceType: 'decision',
						sourceId: this.objectId,
					}),
				})
				this.$emit('refresh')
			} catch (e) {
				this.transitionError =
					e?.message || this.t('decidiq', 'Publication failed.')
			}
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

.decidiq-lifecycle__timeline {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.decidiq-lifecycle__step {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.decidiq-lifecycle__marker {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	border: 2px solid var(--color-border-dark);
	flex-shrink: 0;
}

.decidiq-lifecycle__step--done .decidiq-lifecycle__marker {
	background: var(--color-success);
	border-color: var(--color-success);
}

.decidiq-lifecycle__step--current .decidiq-lifecycle__marker {
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
}

.decidiq-lifecycle__step--upcoming .decidiq-lifecycle__label {
	color: var(--color-text-maxcontrast);
}

.decidiq-lifecycle__actions {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.decidiq-lifecycle__actions-title {
	margin: 8px 0 0;
	font-size: 0.95rem;
	font-weight: bold;
}

.decidiq-lifecycle__none {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.decidiq-lifecycle__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.decidiq-lifecycle__chair-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin-inline-start: 4px;
}
</style>
