<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Amendment page widget: the voting round of one amendment
 (voting-chair-close-and-amendment-rounds, REQ-VCR-004).

 Mounts the same VotingRoundPanel the motion page uses, with
 subjectType="amendment", so opening a round here sends an amendment round.
 The round's meeting is the parent motion's meeting, reached through the
 amendment's `amends` link. The server keeps enforcing the amendment voting
 order and the panel shows its refusal.

 Like MotionVotingRoundTab, the amendment id comes from the route, because
 CnDetailPage does not pass objectId to a custom widget.
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--voting-round"
		data-testid="amendment-voting-round-tab">
		<p v-if="loading" class="decidiq-tab__empty">
			{{ t('decidiq', 'Loading…') }}
		</p>
		<VotingRoundPanel
			v-else-if="amendmentId"
			:motionId="amendmentId"
			subjectType="amendment"
			:motionLifecycle="lifecycle"
			:meetingId="meetingId" />
	</div>
</template>

<script>
import VotingRoundPanel from '../VotingRoundPanel.vue'
import { meetingIdOf, parentMotionIdOf } from '../../utils/votingPermissions.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'AmendmentVotingRoundTab',
	components: { VotingRoundPanel },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			lifecycle: '',
			meetingId: '',
		}
	},

	computed: {
		/**
		 * The amendment this widget is mounted on: the prop when a caller
		 * passes it, else the route id the page was resolved from.
		 *
		 * @return {string} The amendment (Decision) UUID, or ''.
		 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-004-a-chair-opens-and-closes-a-vote-on-an-amendment-from-the-amendment-page
		 */
		amendmentId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},
	},

	watch: {
		amendmentId: {
			immediate: true,
			/** @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-004-a-chair-opens-and-closes-a-vote-on-an-amendment-from-the-amendment-page */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * Read the amendment's lifecycle and its parent motion's meeting.
		 *
		 * @spec openspec/specs/voting-round-management/spec.md#requirement-req-vcr-004-a-chair-opens-and-closes-a-vote-on-an-amendment-from-the-amendment-page
		 */
		async refresh() {
			if (!this.amendmentId) return
			this.loading = true
			try {
				const amendment = await ensureRelationType('amendment').fetchObject(
					'amendment',
					this.amendmentId,
				)
				this.lifecycle = amendment?.lifecycle || ''
				const parentId = parentMotionIdOf(amendment)
				const motion = parentId
					? await ensureRelationType('motion').fetchObject(
							'motion',
							parentId,
						)
					: null
				this.meetingId = meetingIdOf(motion)
			} catch {
				this.lifecycle = ''
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
