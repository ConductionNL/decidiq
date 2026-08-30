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
				:motionId="motionId"
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

	computed: {
		/**
		 * The motion this tab is mounted on.
		 *
		 * ⚠️ THE `objectId` PROP IS NOT DELIVERED HERE, and its `default: ''`
		 * made that impossible to notice. CnDetailPage's per-widget slot is
		 * declared as
		 *
		 *     <slot :name="`widget-${item.widgetId}`" :item="item"
		 *           :widget="findWidget(item)">
		 *
		 * — `item` and `widget`, and nothing else. CnPageRenderer forwards those
		 * two with `v-bind="slotProps"`, so a `type: "custom"` widget wired
		 * through a manifest `slots` map never receives the object id, and this
		 * component silently ran with `objectId === ''` from the day it was
		 * written: `refresh()` returned on its first line, the panel got an
		 * empty motionId, and its round query went out unfiltered. The route
		 * parameter is the id the page itself was resolved from
		 * (`/motions/:id`), so it answers the same question without reaching
		 * into a shared component's internals or forking it.
		 *
		 * The prop is still honoured first, so a future library release that
		 * does bind it — or any caller mounting this tab directly — wins.
		 *
		 * @spec openspec/specs/voting-system/spec.md
		 * @return {string} The motion (Decision) UUID, or '' when unresolvable
		 */
		motionId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},
	},

	watch: {
		motionId: {
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
		 * Reads the motion with `fetchObject`, not `fetchCollection({ id })`:
		 * OpenRegister's SearchQueryHandler `unset()`s `id` from the query
		 * before it becomes a filter, so the collection call returned the first
		 * arbitrary Decision on the instance and the `.find()` fallback below it
		 * then handed that stranger's lifecycle to the panel.
		 *
		 * @spec openspec/specs/voting-system/spec.md
		 */
		async refresh() {
			if (!this.motionId) return
			this.loading = true
			try {
				const motionStore = ensureRelationType('motion')
				const motion = await motionStore.fetchObject('motion', this.motionId)
				this.motionLifecycle = motion?.lifecycle || ''
				// Decision carries its own optional `meeting` reference
				// (register.d/67-model-debt-cleanup.json); the object shape is a
				// bare uuid or an expanded object depending on `_extend`.
				this.meetingId = String(motion?.meeting?.id ?? motion?.meeting ?? '')
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
