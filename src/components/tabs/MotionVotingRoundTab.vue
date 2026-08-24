<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: voting round management for a Motion.

 Hosts the VotingRoundPanel (open rounds with configurable rules, cast votes,
 live tally, tie-break controls, results). The panel was orphaned when the
 bespoke MotionDetail.vue was deleted during the IA alignment; this tab
 re-mounts it on the manifest MotionDetail page so rounds can actually be
 opened — and the voting-rules-v1 selectors exposed — from the real UI.

 Resolves the motion's lifecycle and linked meeting (both required by the
 panel) from the motion object itself.

 @spec openspec/specs/voting-system/spec.md
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--voting-round"
		data-testid="motion-voting-round-tab">
		<p v-if="loading" class="decidiq-tab__empty">
			{{ t('decidiq', 'Loading…') }}
		</p>
		<template v-else>
			<VotingRoundPanel
				:motionId="String(objectId)"
				:motionLifecycle="motionLifecycle"
				:meetingId="meetingId" />
		</template>
	</div>
</template>

<script>
import VotingRoundPanel from '../VotingRoundPanel.vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MotionVotingRoundTab',
	components: { VotingRoundPanel },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			motionLifecycle: '',
			meetingId: '',
		}
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/voting-system/spec.md */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * Resolve the motion's lifecycle + linked meeting for the panel.
		 *
		 * @spec openspec/specs/voting-system/spec.md
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			try {
				const motionStore = ensureRelationType('motion')
				const motions = await motionStore.fetchCollection('motion', {
					id: this.objectId,
					_limit: 1,
				})
				const motion =
					(motions || []).find(
						(m) =>
							String(m?.id ?? m?.uuid ?? '') === String(this.objectId),
					) || (motions || [])[0]
				this.motionLifecycle = motion?.lifecycle || ''
				// The meeting link lives either as a flat foreign key or a relation entry.
				this.meetingId = String(
					motion?.meeting?.id
						?? motion?.meeting
						?? (motion?.relations || []).find(
							(r) => (r?.schema || '') === 'meeting',
						)?.id
						?? '',
				)
			} catch (e) {
				this.motionLifecycle = ''
				this.meetingId = ''
			} finally {
				this.loading = false
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

.decidiq-tab__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
