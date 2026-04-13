<template>
	<div class="amendment-list">
		<div class="amendment-list__header">
			<span class="amendment-list__count">
				{{ t('decidesk', '{count} amendments', { count: amendments.length }) }}
			</span>
			<button v-if="canSubmit"
				class="amendment-list__add"
				@click="$router.push({ name: 'AmendmentDetail', params: { id: 'new' } })">
				{{ t('decidesk', 'Submit amendment') }}
			</button>
		</div>

		<ul v-if="amendments.length > 0" class="amendment-list__items">
			<li v-for="amendment in amendments"
				:key="amendment.id"
				class="amendment-list__item"
				@click="$router.push({ name: 'AmendmentDetail', params: { id: amendment.id } })">
				<span class="amendment-list__title">{{ amendment.title }}</span>
				<span class="amendment-list__proposer">{{ amendment.proposer }}</span>
				<span class="amendment-list__lifecycle" :data-status="amendment.lifecycle">
					{{ lifecycleLabel(amendment.lifecycle) }}
				</span>
			</li>
		</ul>
		<p v-else class="amendment-list__empty">
			{{ t('decidesk', 'No amendments submitted') }}
		</p>
	</div>
</template>

<script>
import { useObjectStore } from '../store/store.js'

export default {
	name: 'AmendmentList',
	props: {
		motionId: {
			type: String,
			required: true,
		},
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		amendments() {
			const all = this.objectStore.objects.amendment || []
			return all.filter(a => {
				const relations = a.relations || []
				return relations.some(r => String(r.objectId) === String(this.motionId))
			})
		},
		canSubmit() {
			return true
		},
	},
	created() {
		this.objectStore.fetchObjects('amendment')
	},
	methods: {
		lifecycleLabel(lifecycle) {
			const labels = {
				submitted: t('decidesk', 'Submitted'),
				debating: t('decidesk', 'Debating'),
				voting: t('decidesk', 'Voting'),
				adopted: t('decidesk', 'Adopted'),
				rejected: t('decidesk', 'Rejected'),
			}
			return labels[lifecycle] || lifecycle
		},
	},
}
</script>

<style scoped>
.amendment-list__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.amendment-list__count {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.amendment-list__add {
	padding: 4px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.amendment-list__items {
	list-style: none;
	margin: 0;
	padding: 0;
}

.amendment-list__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}

.amendment-list__item:hover {
	background: var(--color-background-hover);
}

.amendment-list__title {
	flex: 1;
	font-weight: 500;
}

.amendment-list__proposer {
	color: var(--color-text-maxcontrast);
}

.amendment-list__lifecycle {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 600;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.amendment-list__lifecycle[data-status='adopted'] {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.amendment-list__lifecycle[data-status='rejected'] {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.amendment-list__empty {
	color: var(--color-text-maxcontrast);
}
</style>
