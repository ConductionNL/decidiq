<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: parent Motion of an Amendment.

 Posture: read-only summary + click-through. Resolves the current
 amendment's `parentMotion` field to its motion record and displays
 a summary card (title, proposer, lifecycle) plus a "View motion"
 link to /motions/:parentMotionId. Cross-schema fetch lives inside
 this component (the abstract-sidebar contract doesn't traverse
 references for us).

 @spec openspec/changes/decidesk-manifest-v1/design.md (open question 3)
-->
<template>
	<div class="decidesk-tab decidesk-tab--parent-motion">
		<h3 class="decidesk-tab__title">
			{{ t('decidesk', 'Parent motion') }}
		</h3>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load parent motion')">
			{{ error }}
		</CnNoteCard>

		<p v-else-if="loading" class="decidesk-tab__loading">
			{{ t('decidesk', 'Loading…') }}
		</p>

		<CnNoteCard
			v-else-if="!parentMotionId"
			type="info"
			:title="t('decidesk', 'No parent motion')">
			{{ t('decidesk', 'This amendment is not linked to a motion.') }}
		</CnNoteCard>

		<CnDetailCard
			v-else-if="motion"
			:title="motion.title || t('decidesk', 'Motion')">
			<CnDetailGrid :items="propertyItems" />
			<div class="decidesk-tab__cta">
				<NcButton
					type="primary"
					:aria-label="t('decidesk', 'Open parent motion')"
					@click="openParent">
					{{ t('decidesk', 'View motion') }}
				</NcButton>
			</div>
		</CnDetailCard>

		<CnNoteCard
			v-else
			type="warning"
			:title="t('decidesk', 'Parent motion not found')">
			{{ t('decidesk', 'The referenced motion ({id}) could not be loaded.', { id: parentMotionId }) }}
		</CnNoteCard>
	</div>
</template>

<script>
import { CnDetailCard, CnDetailGrid, CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'AmendmentParentMotionTab',
	components: { CnDetailCard, CnDetailGrid, CnNoteCard, NcButton },
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
		parentMotionId() {
			const ref = this.amendment?.parentMotion
			if (!ref) return ''
			if (typeof ref === 'object') return ref.id || ref.uuid || ''
			return ref
		},
		propertyItems() {
			if (!this.motion) return []
			return [
				{ label: this.t('decidesk', 'Title'), value: this.motion.title },
				{ label: this.t('decidesk', 'Proposer'), value: this.motion.proposer },
				{ label: this.t('decidesk', 'Type'), value: this.motion.motionType },
				{ label: this.t('decidesk', 'Status'), value: this.motion.lifecycle },
				{ label: this.t('decidesk', 'Submitted'), value: this.motion.submittedAt },
			]
		},
	},
	watch: {
		objectId: {
			immediate: true,
			handler() { this.refresh() },
		},
	},
	methods: {
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
				this.error = e?.message || this.t('decidesk', 'Failed to load parent motion.')
			} finally {
				this.loading = false
			}
		},
		openParent() {
			if (!this.parentMotionId) return
			this.$router.push({ name: 'MotionDetail', params: { id: this.parentMotionId } })
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
.decidesk-tab__cta {
	margin-top: var(--default-grid-baseline);
}
</style>
