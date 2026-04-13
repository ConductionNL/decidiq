<template>
	<div class="motion-index">
		<header class="motion-index__header">
			<h2>{{ t('decidesk', 'Motions') }}</h2>
		</header>

		<div class="motion-index__filters">
			<select v-model="filterLifecycle" class="motion-index__select">
				<option value="">
					{{ t('decidesk', 'All statuses') }}
				</option>
				<option value="submitted">
					{{ t('decidesk', 'Submitted') }}
				</option>
				<option value="debating">
					{{ t('decidesk', 'Debating') }}
				</option>
				<option value="voting">
					{{ t('decidesk', 'Voting') }}
				</option>
				<option value="adopted">
					{{ t('decidesk', 'Adopted') }}
				</option>
				<option value="rejected">
					{{ t('decidesk', 'Rejected') }}
				</option>
				<option value="withdrawn">
					{{ t('decidesk', 'Withdrawn') }}
				</option>
			</select>
			<select v-model="filterType" class="motion-index__select">
				<option value="">
					{{ t('decidesk', 'All types') }}
				</option>
				<option value="motion">
					{{ t('decidesk', 'Motion') }}
				</option>
				<option value="amendment">
					{{ t('decidesk', 'Amendment') }}
				</option>
				<option value="order">
					{{ t('decidesk', 'Order motion') }}
				</option>
				<option value="procedural">
					{{ t('decidesk', 'Procedural') }}
				</option>
			</select>
		</div>

		<table class="motion-index__table">
			<thead>
				<tr>
					<th>{{ t('decidesk', 'Title') }}</th>
					<th>{{ t('decidesk', 'Type') }}</th>
					<th>{{ t('decidesk', 'Proposer') }}</th>
					<th>{{ t('decidesk', 'Status') }}</th>
					<th>{{ t('decidesk', 'Submitted') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="motion in filteredMotions"
					:key="motion.id"
					class="motion-index__row"
					@click="$router.push({ name: 'MotionDetail', params: { id: motion.id } })">
					<td>{{ motion.title }}</td>
					<td>
						<span class="motion-index__badge" :data-type="motion.motionType">
							{{ motionTypeLabel(motion.motionType) }}
						</span>
					</td>
					<td>{{ motion.proposer }}</td>
					<td>
						<span class="motion-index__status" :data-status="motion.lifecycle">
							{{ lifecycleLabel(motion.lifecycle) }}
						</span>
					</td>
					<td>{{ formatDate(motion.submittedAt) }}</td>
				</tr>
				<tr v-if="filteredMotions.length === 0">
					<td colspan="5" class="motion-index__empty">
						{{ t('decidesk', 'No motions found') }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { useObjectStore } from '../store/store.js'

export default {
	name: 'MotionIndex',
	data() {
		return {
			filterLifecycle: '',
			filterType: '',
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		motions() {
			return this.objectStore.objects.motion || []
		},
		filteredMotions() {
			return this.motions.filter(m => {
				if (this.filterLifecycle && m.lifecycle !== this.filterLifecycle) return false
				if (this.filterType && m.motionType !== this.filterType) return false
				return true
			})
		},
	},
	created() {
		this.objectStore.fetchObjects('motion')
	},
	methods: {
		motionTypeLabel(type) {
			const labels = {
				motion: t('decidesk', 'Motion'),
				amendment: t('decidesk', 'Amendment'),
				order: t('decidesk', 'Order motion'),
				procedural: t('decidesk', 'Procedural'),
			}
			return labels[type] || type
		},
		lifecycleLabel(lifecycle) {
			const labels = {
				submitted: t('decidesk', 'Submitted'),
				debating: t('decidesk', 'Debating'),
				voting: t('decidesk', 'Voting'),
				adopted: t('decidesk', 'Adopted'),
				rejected: t('decidesk', 'Rejected'),
				withdrawn: t('decidesk', 'Withdrawn'),
			}
			return labels[lifecycle] || lifecycle
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.motion-index {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.motion-index__header {
	margin-bottom: 16px;
}

.motion-index__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.motion-index__filters {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.motion-index__select {
	padding: 6px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.motion-index__table {
	width: 100%;
	border-collapse: collapse;
}

.motion-index__table th {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.motion-index__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.motion-index__row {
	cursor: pointer;
}

.motion-index__row:hover {
	background: var(--color-background-hover);
}

.motion-index__badge,
.motion-index__status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 600;
}

.motion-index__badge {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.motion-index__status[data-status='adopted'] {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.motion-index__status[data-status='rejected'] {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.motion-index__status[data-status='voting'] {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.motion-index__status[data-status='debating'],
.motion-index__status[data-status='submitted'] {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.motion-index__status[data-status='withdrawn'] {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.motion-index__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 24px;
}
</style>
