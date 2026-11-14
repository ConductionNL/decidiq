<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Onboarding or offboarding checklist (bod-08): every step with its status,
 and Mark as done or Skip on an open step. The server decides who may tick a
 step (the secretary, the chair or an administrator), runs the installation
 and end-of-membership effects and moves the record's status; anyone else
 gets its refusal as a message.

 @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
-->
<template>
	<div class="decidiq-tab" data-testid="onboarding-checklist-tab">
		<p v-if="rows.length === 0" class="decidiq-checklist__empty">
			{{ t('decidiq', 'This checklist has no steps.') }}
		</p>
		<ol v-else class="decidiq-checklist__list">
			<li
				v-for="row in rows"
				:key="row.index"
				data-testid="onboarding-checklist-step">
				<span class="decidiq-checklist__title">
					{{ row.title }}
					<span v-if="!row.mandatory" class="decidiq-checklist__optional">
						{{ t('decidiq', '(optional)') }}
					</span>
				</span>
				<span class="decidiq-checklist__status">{{ statusLabel(row.status) }}</span>
				<span class="decidiq-checklist__actions">
					<NcButton
						v-if="row.canComplete"
						variant="secondary"
						data-testid="onboarding-checklist-complete"
						:disabled="busy"
						@click="mark(row.index, 'completed')">
						{{ t('decidiq', 'Mark as done') }}
					</NcButton>
					<NcButton
						v-if="row.canSkip"
						variant="tertiary"
						data-testid="onboarding-checklist-skip"
						:disabled="busy"
						@click="mark(row.index, 'skipped')">
						{{ t('decidiq', 'Skip') }}
					</NcButton>
				</span>
			</li>
		</ol>
		<p v-if="error" class="decidiq-checklist__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { checklistRows, kindOf, stepPath } from '../../utils/onboardingChecklist.js'
import { objectIdLoader } from './objectIdLoader.js'
import { ensureRelationType } from './useRelationStore.js'

const STATUS_LABELS = {
	open: 'To do',
	'in-execution': 'In progress',
	completed: 'Done',
	skipped: 'Skipped',
}

export default {
	name: 'OnboardingChecklistTab',
	components: { NcButton },

	/** @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist */
	mixins: [objectIdLoader],

	props: {
		/** The page's schema slug: member-onboarding or member-offboarding. */
		schema: { type: String, default: '' },
	},

	data() {
		return {
			record: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist */
		kind() {
			return kindOf(this.schema)
		},

		/** @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist */
		rows() {
			return this.kind ? checklistRows(this.kind, this.record) : []
		},
	},

	methods: {
		/**
		 * Read the record and show its steps.
		 *
		 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
		 */
		async load() {
			this.record = null
			if (!this.objectId || !this.kind) return
			try {
				this.record = await ensureRelationType(this.schema).fetchObject(
					this.schema,
					String(this.objectId),
				)
			} catch {
				this.record = null
			}
		},

		/**
		 * Mark one step; the server answers with the record as saved.
		 *
		 * @param {number} index The step's position
		 * @param {string} status `completed` or `skipped`
		 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#scenario-the-last-open-step-is-marked-done
		 */
		async mark(index, status) {
			this.error = ''
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(stepPath(this.kind, String(this.objectId), index)),
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ status }),
					},
				)
				const payload = await response.json().catch(() => ({}))
				if (!response.ok) {
					this.error =
						payload?.message
						|| this.t('decidiq', 'The step was not changed.')
					return
				}
				this.record = payload
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'The step was not changed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * The label of a step status.
		 *
		 * @param {string} status The status
		 * @return {string}
		 * @spec openspec/changes/bodies-onboarding-checklist-walkthrough/specs/member-onboarding/spec.md#requirement-req-mobw-001-the-secretary-walks-a-member-through-the-checklist
		 */
		statusLabel(status) {
			return this.t('decidiq', STATUS_LABELS[status] ?? STATUS_LABELS.open)
		},
	},
}
</script>

<style scoped>
.decidiq-checklist__list {
	margin: 0;
	padding-inline-start: 0;
	list-style: none;
}

.decidiq-checklist__list li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	padding-block: calc(var(--default-grid-baseline, 4px) * 2);
	border-bottom: 1px solid var(--color-border);
}

.decidiq-checklist__title {
	flex: 1 1 auto;
}

.decidiq-checklist__optional,
.decidiq-checklist__status {
	color: var(--color-text-maxcontrast);
}

.decidiq-checklist__actions {
	display: flex;
	gap: var(--default-grid-baseline, 4px);
}

.decidiq-checklist__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
