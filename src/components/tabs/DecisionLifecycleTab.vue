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
	<div class="decidesk-tab decidesk-tab--lifecycle" data-testid="decision-lifecycle-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Lifecycle') }}
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Lifecycle unavailable')">
			{{ error }}
		</CnNoteCard>

		<ol v-if="!error" class="decidesk-lifecycle__timeline" data-testid="lifecycle-timeline">
			<li v-for="step in timeline"
				:key="step.state"
				class="decidesk-lifecycle__step"
				:class="'decidesk-lifecycle__step--' + step.status"
				:data-testid="'lifecycle-step-' + step.state">
				<span class="decidesk-lifecycle__marker" aria-hidden="true" />
				<span class="decidesk-lifecycle__label">{{ stateLabel(step.state) }}</span>
				<CnStatusBadge
					v-if="step.status === 'current'"
					:label="t('decidesk', 'Current')"
					:color-map="{ [t('decidesk', 'Current')]: 'primary' }" />
			</li>
		</ol>

		<div v-if="!error" class="decidesk-lifecycle__actions">
			<h4 class="decidesk-lifecycle__actions-title">
				{{ t('decidesk', 'Available transitions') }}
			</h4>
			<p v-if="!loading && !actions.length" class="decidesk-lifecycle__none">
				{{ t('decidesk', 'No transitions available from this state.') }}
			</p>
			<div class="decidesk-lifecycle__buttons">
				<NcButton v-for="action in actions"
					:key="action.action"
					:disabled="busy"
					:data-testid="'lifecycle-action-' + action.action"
					variant="secondary"
					@click="applyTransition(action.action)">
					{{ actionLabel(action.action) }}
					<span v-if="action.chairOnly" class="decidesk-lifecycle__chair-hint">
						({{ t('decidesk', 'chair only') }})
					</span>
				</NcButton>
			</div>
			<CnNoteCard
				v-if="transitionError"
				type="error"
				:title="t('decidesk', 'Transition rejected')">
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
import { NcButton } from '@nextcloud/vue'
import { CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { buildTimeline } from './decisionLifecycle.js'
import PublicationPromptModal from '../../modals/PublicationPromptModal.vue'
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
			handler() { this.refresh() },
		},
	},
	methods: {
		/** @spec openspec/specs/decision-management/spec.md */
		stateLabel(state) {
			const labels = {
				draft: this.t('decidesk', 'Draft'),
				proposed: this.t('decidesk', 'Proposed'),
				deliberating: this.t('decidesk', 'Deliberating'),
				voting: this.t('decidesk', 'Voting'),
				decided: this.t('decidesk', 'Decided'),
				enacted: this.t('decidesk', 'Enacted'),
				archived: this.t('decidesk', 'Archived'),
			}
			return labels[state] || state
		},
		/** @spec openspec/specs/decision-management/spec.md */
		actionLabel(action) {
			const labels = {
				propose: this.t('decidesk', 'Propose'),
				deliberate: this.t('decidesk', 'Start deliberation'),
				openVoting: this.t('decidesk', 'Open voting'),
				decide: this.t('decidesk', 'Record decision'),
				enact: this.t('decidesk', 'Enact'),
				archive: this.t('decidesk', 'Archive'),
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
					generateUrl(`/apps/decidesk/api/decisions/${this.objectId}/transitions`),
					{ headers: { Accept: 'application/json', requesttoken: OC.requestToken } },
				)
				const body = await res.json()
				if (!res.ok) {
					this.error = body?.message || this.t('decidesk', 'Failed to load lifecycle state.')
					return
				}
				this.lifecycle = body.lifecycle || 'draft'
				this.actions = Array.isArray(body.actions) ? body.actions : []
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load lifecycle state.')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/specs/decision-management/spec.md */
		async applyTransition(action) {
			this.busy = true
			this.transitionError = ''
			try {
				const res = await fetch(
					generateUrl(`/apps/decidesk/api/decisions/${this.objectId}/transition`),
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
					this.transitionError = body?.message || this.t('decidesk', 'Transition failed.')
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
				this.transitionError = e?.message || this.t('decidesk', 'Transition failed.')
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
				let bodyId = decision?.governanceBody
					|| decision?.relations?.GovernanceBody
					|| decision?.relations?.governanceBody
				if (Array.isArray(bodyId)) bodyId = bodyId[0]
				if (!bodyId) return

				const res = await fetch(
					generateUrl('/apps/decidesk/api/settings/publication-config'),
					{ headers: { Accept: 'application/json', requesttoken: OC.requestToken } },
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
				await fetch(
					generateUrl('/apps/decidesk/api/publications'),
					{
						method: 'POST',
						headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: OC.requestToken },
						body: JSON.stringify({ sourceType: 'decision', sourceId: this.objectId }),
					},
				)
				this.$emit('refresh')
			} catch (e) {
				this.transitionError = e?.message || this.t('decidesk', 'Publication failed.')
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

.decidesk-lifecycle__timeline {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.decidesk-lifecycle__step {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.decidesk-lifecycle__marker {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	border: 2px solid var(--color-border-dark);
	flex-shrink: 0;
}

.decidesk-lifecycle__step--done .decidesk-lifecycle__marker {
	background: var(--color-success);
	border-color: var(--color-success);
}

.decidesk-lifecycle__step--current .decidesk-lifecycle__marker {
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
}

.decidesk-lifecycle__step--upcoming .decidesk-lifecycle__label {
	color: var(--color-text-maxcontrast);
}

.decidesk-lifecycle__actions {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.decidesk-lifecycle__actions-title {
	margin: 8px 0 0;
	font-size: 0.95rem;
	font-weight: bold;
}

.decidesk-lifecycle__none {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.decidesk-lifecycle__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.decidesk-lifecycle__chair-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin-inline-start: 4px;
}
</style>
