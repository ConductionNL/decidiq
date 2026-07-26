<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 RunningProcessesWidget — motions in flight, grouped by lifecycle stage
 (REQ-001).

 Fetches motions with lifecycle in [submitted, under-discussion, voting] and
 groups them by stage in a computed property (reduce). Each stage renders a
 labelled section; clicking a motion entry navigates to the motion detail
 view. An empty state is shown when no motions are in flight.
-->
<template>
	<div class="running-processes" data-testid="running-processes">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="total === 0"
			:name="t('decidesk', 'No active motions')"
			data-testid="running-processes-empty">
			<template #icon>
				<CommentCheckOutline :size="32" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<section v-for="stage in stages"
				v-show="groups[stage.key].length > 0"
				:key="stage.key"
				class="running-processes__stage"
				:data-testid="`running-processes-stage-${stage.key}`">
				<h3 class="running-processes__stage-title">
					{{ stage.label }} <span class="running-processes__count">{{ groups[stage.key].length }}</span>
				</h3>
				<ul class="running-processes__list">
					<li v-for="motion in groups[stage.key]"
						:key="motion.id"
						:data-testid="`running-motion-row-${motion.id}`"
						class="running-processes__row"
						@click="openMotion(motion)">
						{{ motion.title || motion.name }}
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CommentCheckOutline from 'vue-material-design-icons/CommentCheckOutline.vue'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'
import { groupMotionsByLifecycle, RUNNING_MOTION_LIFECYCLES } from './widgetLogic.js'
import { getMotions } from '../../../services/dashboardData.js'

export default {
	name: 'RunningProcessesWidget',

	components: { NcEmptyContent, NcLoadingIcon, CommentCheckOutline },

	mixins: [dashboardRefreshMixin],

	data() {
		return {
			/** In-flight motions fetched from OR. */
			motions: [],
		}
	},

	computed: {
		/**
		 * Stage descriptors (key + translated label) in display order.
		 *
		 * @return {Array<{key: string, label: string}>} Ordered stages.
		 */
		stages() {
			const labels = {
				submitted: t('decidesk', 'Submitted'),
				'under-discussion': t('decidesk', 'Under discussion'),
				voting: t('decidesk', 'Voting'),
			}
			return RUNNING_MOTION_LIFECYCLES.map((key) => ({ key, label: labels[key] }))
		},

		/**
		 * Motions grouped by lifecycle stage.
		 *
		 * @return {Object<string, Array<object>>} Stage → motions map.
		 */
		groups() {
			return groupMotionsByLifecycle(this.motions)
		},

		/**
		 * Total in-flight motions across all stages.
		 *
		 * @return {number} Combined motion count.
		 */
		total() {
			return this.stages.reduce((sum, s) => sum + this.groups[s.key].length, 0)
		},
	},

	methods: {
		/**
		 * Fetch in-flight motions. Called on mount and on dashboard refresh.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				this.motions = await getMotions({ lifecycle: RUNNING_MOTION_LIFECYCLES })
			} catch (e) {
				console.error('[decidesk] RunningProcessesWidget load failed', e)
				this.error = e
				this.motions = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Navigate to the motion detail view.
		 *
		 * @param {object} motion The clicked motion.
		 * @return {void}
		 */
		openMotion(motion) {
			this.$router.push({ name: 'MotionDetail', params: { id: String(motion.id) } })
		},
	},
}
</script>

<style scoped>
.running-processes {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.running-processes__stage-title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 4px;
	font-size: 0.95em;
	font-weight: 600;
}

.running-processes__count {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	padding: 0 6px;
	border-radius: 10px;
	background: var(--color-primary-light, #eaf3fb);
	color: var(--color-primary-text-dark, #1a4a72);
	font-size: 0.8em;
}

.running-processes__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.running-processes__row {
	padding: 6px 12px;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
	cursor: pointer;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.running-processes__row:hover {
	background: var(--color-background-hover, #f5f5f5);
}
</style>
