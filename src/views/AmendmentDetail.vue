<template>
	<div class="amendment-detail">
		<header class="amendment-detail__header">
			<div class="amendment-detail__title-row">
				<h2>{{ amendment.title || t('decidesk', 'Amendment') }}</h2>
				<span v-if="amendment.lifecycle"
					class="amendment-detail__lifecycle"
					:data-status="amendment.lifecycle">
					{{ lifecycleLabel(amendment.lifecycle) }}
				</span>
			</div>
		</header>

		<!-- Conflict warning -->
		<div v-if="hasConflict" class="amendment-detail__conflict" role="alert">
			{{ t('decidesk', 'Possible conflict with another amendment — consult the clerk') }}
		</div>

		<!-- Timeline -->
		<div class="amendment-detail__timeline" role="list" :aria-label="t('decidesk', 'Amendment lifecycle')">
			<div v-for="stage in timelineStages"
				:key="stage.key"
				class="amendment-detail__stage"
				:class="{ 'amendment-detail__stage--active': stage.key === amendment.lifecycle, 'amendment-detail__stage--done': stage.done }"
				role="listitem"
				:aria-current="stage.key === amendment.lifecycle ? 'step' : undefined">
				{{ stage.label }}
			</div>
		</div>

		<!-- Actions -->
		<div class="amendment-detail__actions">
			<button v-if="amendment.lifecycle === 'submitted'"
				class="primary"
				@click="transition('debating')">
				{{ t('decidesk', 'Open debate') }}
			</button>
			<button v-if="amendment.lifecycle === 'debating'"
				class="primary"
				@click="transition('voting')">
				{{ t('decidesk', 'Open voting round') }}
			</button>
		</div>

		<!-- Amendment text -->
		<section class="amendment-detail__section">
			<h3>{{ t('decidesk', 'Amendment text') }}</h3>
			<p class="amendment-detail__text">{{ amendment.text }}</p>
		</section>

		<!-- Proposer -->
		<section class="amendment-detail__section">
			<h3>{{ t('decidesk', 'Proposer') }}</h3>
			<p>{{ amendment.proposer }}</p>
		</section>

		<!-- Voting round -->
		<section class="amendment-detail__section">
			<h3>{{ t('decidesk', 'Voting') }}</h3>
			<VotingRoundPanel :motion-id="amendmentId" :motion-lifecycle="amendment.lifecycle" />
		</section>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../store/store.js'
import VotingRoundPanel from '../components/VotingRoundPanel.vue'

export default {
	name: 'AmendmentDetail',
	components: {
		VotingRoundPanel,
	},
	data() {
		return {
			amendment: {},
			hasConflict: false,
		}
	},
	computed: {
		amendmentId() {
			return this.$route.params.id
		},
		objectStore() {
			return useObjectStore()
		},
		timelineStages() {
			const stages = [
				{ key: 'submitted', label: t('decidesk', 'Submitted') },
				{ key: 'debating', label: t('decidesk', 'Debating') },
				{ key: 'voting', label: t('decidesk', 'Voting') },
			]
			const terminal = this.amendment.lifecycle
			if (terminal === 'adopted') {
				stages.push({ key: 'adopted', label: t('decidesk', 'Adopted') })
			} else if (terminal === 'rejected') {
				stages.push({ key: 'rejected', label: t('decidesk', 'Rejected') })
			} else {
				stages.push({ key: 'result', label: t('decidesk', 'Result') })
			}
			const currentIdx = stages.findIndex(s => s.key === this.amendment.lifecycle)
			return stages.map((s, i) => ({ ...s, done: i < currentIdx }))
		},
	},
	created() {
		this.loadAmendment()
	},
	watch: {
		'$route.params.id'() {
			this.loadAmendment()
		},
	},
	methods: {
		async loadAmendment() {
			const amendments = await this.objectStore.fetchObjects('amendment')
			this.amendment = amendments.find(a => String(a.id) === String(this.amendmentId)) || {}
		},
		async transition(newState) {
			try {
				const url = generateUrl(`/apps/decidesk/api/amendments/${this.amendmentId}/transition`)
				await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ newState, actorId: 'current-user' }),
				})
				await this.loadAmendment()
			} catch (error) {
				console.error('Transition failed:', error)
			}
		},
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
.amendment-detail {
	padding: 8px 4px 24px;
	max-width: 900px;
}

.amendment-detail__header {
	margin-bottom: 16px;
}

.amendment-detail__title-row {
	display: flex;
	align-items: center;
	gap: 12px;
}

.amendment-detail__title-row h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.amendment-detail__lifecycle {
	display: inline-block;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 600;
}

.amendment-detail__lifecycle[data-status="adopted"] {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.amendment-detail__lifecycle[data-status="rejected"] {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.amendment-detail__lifecycle[data-status="voting"] {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.amendment-detail__lifecycle[data-status="debating"],
.amendment-detail__lifecycle[data-status="submitted"] {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.amendment-detail__conflict {
	padding: 12px 16px;
	margin-bottom: 16px;
	border-radius: var(--border-radius);
	background: var(--color-warning);
	color: var(--color-primary-text);
	font-weight: 600;
}

.amendment-detail__timeline {
	display: flex;
	gap: 4px;
	margin-bottom: 20px;
}

.amendment-detail__stage {
	flex: 1;
	padding: 8px 12px;
	text-align: center;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	border-radius: var(--border-radius);
}

.amendment-detail__stage--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.amendment-detail__stage--done {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.amendment-detail__actions {
	display: flex;
	gap: 8px;
	margin-bottom: 20px;
}

.amendment-detail__actions button {
	padding: 8px 16px;
	border: none;
	border-radius: var(--border-radius);
	cursor: pointer;
	font-weight: 600;
}

.amendment-detail__actions .primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.amendment-detail__section {
	margin-bottom: 24px;
}

.amendment-detail__section h3 {
	margin: 0 0 8px;
	font-size: 16px;
	font-weight: 600;
}

.amendment-detail__text {
	white-space: pre-wrap;
	line-height: 1.6;
}
</style>
