<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: visual diff of an Amendment against its parent Motion text
 (motion-amendment spec). Resolves the amendment's `proposedText` (falling
 back to the amendment `text` for legacy amendments without one) and the
 parent motion's `text`, then renders AmendmentDiffView (green additions /
 red removals, never colour-only).

 Cross-schema fetch lives inside this component, per the
 manifest-abstract-sidebar contract (same posture as AmendmentParentMotionTab).

 @spec openspec/specs/motion-amendment/spec.md
-->
<template>
	<div class="decidesk-tab decidesk-tab--amendment-diff" data-testid="amendment-diff-tab">
		<h3 class="decidesk-tab__title">
			{{ t('decidesk', 'Text changes') }}
		</h3>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load the diff')">
			{{ error }}
		</CnNoteCard>

		<p v-else-if="loading" class="decidesk-tab__loading">
			{{ t('decidesk', 'Loading…') }}
		</p>

		<CnNoteCard
			v-else-if="!parentMotionId"
			type="info"
			:title="t('decidesk', 'No parent motion')">
			{{ t('decidesk', 'This amendment is not linked to a motion, so there is no original text to compare against.') }}
		</CnNoteCard>

		<template v-else>
			<CnNoteCard
				v-if="!hasProposedText"
				type="info"
				:title="t('decidesk', 'No proposed text')">
				{{ t('decidesk', 'This amendment has no proposed replacement text; the amendment text itself is compared against the motion text.') }}
			</CnNoteCard>

			<AmendmentDiffView
				:original-text="originalText"
				:proposed-text="proposedText" />
		</template>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import AmendmentDiffView from '../AmendmentDiffView.vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'AmendmentDiffTab',
	components: { AmendmentDiffView, CnNoteCard },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			error: '',
			amendment: null,
			motion: null,
		}
	},
	computed: {
		/** @spec openspec/specs/motion-amendment/spec.md */
		parentMotionId() {
			const ref = this.amendment?.parentMotion
				?? (this.amendment?.relations || []).find((r) => (r?.schema || '') === 'motion')
			if (!ref) return ''
			if (typeof ref === 'object') return ref.id || ref.uuid || ''
			return ref
		},
		/** @spec openspec/specs/motion-amendment/spec.md */
		hasProposedText() {
			return Boolean(this.amendment?.proposedText)
		},
		/** @spec openspec/specs/motion-amendment/spec.md */
		originalText() {
			return this.motion?.text || ''
		},
		/** @spec openspec/specs/motion-amendment/spec.md */
		proposedText() {
			return this.amendment?.proposedText || this.amendment?.text || ''
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/motion-amendment/spec.md */
			handler() { this.refresh() },
		},
	},
	methods: {
		/** @spec openspec/specs/motion-amendment/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			this.motion = null
			try {
				const amendmentStore = ensureRelationType('amendment')
				this.amendment = await amendmentStore.fetchObject('amendment', this.objectId)
				if (this.parentMotionId) {
					const motionStore = ensureRelationType('motion')
					this.motion = await motionStore.fetchObject('motion', this.parentMotionId)
				}
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load the amendment diff.')
			} finally {
				this.loading = false
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
.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}
.decidesk-tab__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
