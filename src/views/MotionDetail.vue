<template>
	<CnDetailPage :title="motion.title || t('decidesk', 'Motion')">
		<template #actions>
			<div class="motion-detail__actions">
				<button v-if="isChairOrAdmin && motion.lifecycle === 'submitted'"
					class="primary"
					@click="transition('debating')">
					{{ t('decidesk', 'Open debate') }}
				</button>
				<button v-if="isChairOrAdmin && canWithdraw"
					class="error"
					@click="transition('withdrawn')">
					{{ t('decidesk', 'Withdraw motion') }}
				</button>
			</div>
		</template>

		<template #default>
			<span v-if="motion.lifecycle"
				class="motion-detail__lifecycle"
				:data-status="motion.lifecycle">
				{{ lifecycleLabel(motion.lifecycle) }}
			</span>

			<!-- Timeline stages -->
			<div class="motion-detail__timeline" role="list" :aria-label="t('decidesk', 'Motion lifecycle')">
				<div v-for="stage in timelineStages"
					:key="stage.key"
					class="motion-detail__stage"
					:class="{ 'motion-detail__stage--active': stage.key === motion.lifecycle, 'motion-detail__stage--done': stage.done }"
					role="listitem"
					:aria-current="stage.key === motion.lifecycle ? 'step' : undefined">
					{{ stage.label }}
				</div>
			</div>

			<!-- Motion text -->
			<section class="motion-detail__section">
				<h3>{{ t('decidesk', 'Motion text') }}</h3>
				<p class="motion-detail__text">
					{{ motion.text }}
				</p>
			</section>

			<!-- Proposer info -->
			<section class="motion-detail__section">
				<h3>{{ t('decidesk', 'Proposer') }}</h3>
				<p>{{ motion.proposer }}</p>
			</section>

			<!-- Co-signers -->
			<section class="motion-detail__section">
				<h3>{{ t('decidesk', 'Co-signers') }}</h3>
				<ul v-if="coSigners.length > 0" class="motion-detail__cosigners">
					<li v-for="signer in coSigners" :key="signer.uid || signer">
						{{ signer.displayName || signer }}
					</li>
				</ul>
				<p v-else class="motion-detail__empty">
					{{ t('decidesk', 'No co-signers yet') }}
				</p>

				<div class="motion-detail__cosign-form">
					<button @click="addCoSigner">
						{{ t('decidesk', 'Confirm my co-signature') }}
					</button>
				</div>
			</section>

			<!-- Budget impact (for amendments) -->
			<section v-if="motion.motionType === 'amendment'" class="motion-detail__section">
				<h3>{{ t('decidesk', 'Budget impact') }}</h3>
				<div class="motion-detail__budget-form">
					<label>
						{{ t('decidesk', 'Budget line') }}
						<input v-model="budgetLine" type="text">
					</label>
					<label>
						{{ t('decidesk', 'Amount delta') }}
						<input v-model.number="amountDelta" type="number" step="0.01">
					</label>
					<label>
						{{ t('decidesk', 'Rationale') }}
						<textarea v-model="budgetRationale" rows="3" />
					</label>
					<button @click="saveBudgetImpact">
						{{ t('decidesk', 'Save budget impact') }}
					</button>
				</div>
			</section>

			<!-- Amendments list -->
			<section class="motion-detail__section">
				<h3>{{ t('decidesk', 'Amendments') }}</h3>
				<AmendmentList :motion-id="motionId" :motion-lifecycle="motion.lifecycle" />
			</section>

			<!-- Voting round panel -->
			<section class="motion-detail__section">
				<h3>{{ t('decidesk', 'Voting') }}</h3>
				<VotingRoundPanel :motion-id="motionId" :motion-lifecycle="motion.lifecycle" />
			</section>
		</template>
	</CnDetailPage>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { useObjectStore, useSettingsStore } from '../store/store.js'
import AmendmentList from '../components/AmendmentList.vue'
import VotingRoundPanel from '../components/VotingRoundPanel.vue'

export default {
	name: 'MotionDetail',
	components: {
		CnDetailPage,
		AmendmentList,
		VotingRoundPanel,
	},
	data() {
		return {
			motion: {},
			budgetLine: '',
			amountDelta: 0,
			budgetRationale: '',
		}
	},
	computed: {
		motionId() {
			return this.$route.params.id
		},
		objectStore() {
			return useObjectStore()
		},
		settingsStore() {
			return useSettingsStore()
		},
		isChairOrAdmin() {
			return this.settingsStore.isChair || this.settingsStore.isAdmin
		},
		coSigners() {
			return this.motion.coSigners || []
		},
		canWithdraw() {
			return this.motion.lifecycle === 'submitted' || this.motion.lifecycle === 'debating'
		},
		timelineStages() {
			const stages = [
				{ key: 'submitted', label: t('decidesk', 'Submitted') },
				{ key: 'debating', label: t('decidesk', 'Debating') },
				{ key: 'voting', label: t('decidesk', 'Voting') },
			]
			const terminal = this.motion.lifecycle
			if (terminal === 'adopted') {
				stages.push({ key: 'adopted', label: t('decidesk', 'Adopted') })
			} else if (terminal === 'rejected') {
				stages.push({ key: 'rejected', label: t('decidesk', 'Rejected') })
			} else if (terminal === 'withdrawn') {
				stages.push({ key: 'withdrawn', label: t('decidesk', 'Withdrawn') })
			} else {
				stages.push({ key: 'result', label: t('decidesk', 'Result') })
			}
			const currentIdx = stages.findIndex(s => s.key === this.motion.lifecycle)
			return stages.map((s, i) => ({ ...s, done: i < currentIdx }))
		},
	},
	watch: {
		'$route.params.id'() {
			this.loadMotion()
		},
	},
	created() {
		this.loadMotion()
	},
	methods: {
		async loadMotion() {
			const motions = await this.objectStore.fetchObjects('motion')
			this.motion = motions.find(m => String(m.id) === String(this.motionId)) || {}
		},
		async transition(newState) {
			try {
				const url = generateUrl(`/apps/decidesk/api/motions/${this.motionId}/transition`)
				await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: getRequestToken(),
					},
					body: JSON.stringify({ newState }),
				})
				await this.loadMotion()
			} catch (error) {
				console.error('Transition failed:', error)
			}
		},
		async addCoSigner() {
			try {
				const url = generateUrl(`/apps/decidesk/api/motions/${this.motionId}/co-sign-confirm`)
				await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: getRequestToken(),
					},
				})
				await this.loadMotion()
			} catch (error) {
				console.error('Add co-signer failed:', error)
			}
		},
		async saveBudgetImpact() {
			try {
				const url = generateUrl(`/apps/decidesk/api/motions/${this.motionId}/budget-impact`)
				await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: getRequestToken(),
					},
					body: JSON.stringify({
						budgetLine: this.budgetLine,
						amountDelta: this.amountDelta,
						rationale: this.budgetRationale,
					}),
				})
				await this.loadMotion()
			} catch (error) {
				console.error('Save budget impact failed:', error)
			}
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
	},
}
</script>

<style scoped>
.motion-detail__lifecycle {
	display: inline-block;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 600;
	margin-bottom: 12px;
}

.motion-detail__lifecycle[data-status='adopted'] {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.motion-detail__lifecycle[data-status='rejected'] {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.motion-detail__lifecycle[data-status='voting'] {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.motion-detail__lifecycle[data-status='debating'],
.motion-detail__lifecycle[data-status='submitted'] {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.motion-detail__lifecycle[data-status='withdrawn'] {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.motion-detail__timeline {
	display: flex;
	gap: 4px;
	margin-bottom: 20px;
}

.motion-detail__stage {
	flex: 1;
	padding: 8px 12px;
	text-align: center;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	border-radius: var(--border-radius);
}

.motion-detail__stage--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.motion-detail__stage--done {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.motion-detail__actions {
	display: flex;
	gap: 8px;
	margin-bottom: 20px;
}

.motion-detail__actions button {
	padding: 8px 16px;
	border: none;
	border-radius: var(--border-radius);
	cursor: pointer;
	font-weight: 600;
}

.motion-detail__actions .primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.motion-detail__actions .error {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.motion-detail__section {
	margin-bottom: 24px;
}

.motion-detail__section h3 {
	margin: 0 0 8px;
	font-size: 16px;
	font-weight: 600;
}

.motion-detail__text {
	white-space: pre-wrap;
	line-height: 1.6;
}

.motion-detail__cosigners {
	margin: 0;
	padding-left: 1.2em;
}

.motion-detail__empty {
	color: var(--color-text-maxcontrast);
}

.motion-detail__cosign-form,
.motion-detail__budget-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 12px;
	max-width: 400px;
}

.motion-detail__cosign-form input,
.motion-detail__budget-form input,
.motion-detail__budget-form textarea {
	padding: 6px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.motion-detail__cosign-form button,
.motion-detail__budget-form button {
	align-self: flex-start;
	padding: 6px 14px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}
</style>
