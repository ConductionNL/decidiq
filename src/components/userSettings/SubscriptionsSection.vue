<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 User settings: follow new and changed agendas, papers, decisions and minutes
 per body (publication-subscriptions-and-daily-digest, REQ-PSD-001, matrix
 rows pub-10 and pub-16). A member subscribes, sees only his own
 subscriptions, and removes them here. What arrives, and how, follows the
 delivery channels in the notification preferences above.

 @spec openspec/changes/publication-subscriptions-and-daily-digest/specs/public-publication/spec.md#requirement-req-psd-001-anyone-can-subscribe-per-body-and-kind-and-choose-how-often
-->
<template>
	<div class="user-settings-section" data-testid="subscriptions-section">
		<h3>{{ t('decidiq', 'Subscriptions') }}</h3>
		<p class="user-settings-section__hint">
			{{
				t(
					'decidiq',
					'Hear of new and changed agendas, papers, decisions and minutes of the bodies you follow, straight away or as a daily or weekly digest.',
				)
			}}
		</p>

		<ul
			v-if="subscriptions.length"
			class="subscriptions-list"
			data-testid="subscriptions-list">
			<li
				v-for="subscription in subscriptions"
				:key="subscription.id"
				class="subscriptions-list__row">
				<span>{{ describe(subscription) }}</span>
				<NcButton
					variant="tertiary"
					:aria-label="t('decidiq', 'Remove subscription: {what}', { what: describe(subscription) })"
					:disabled="saving"
					data-testid="subscription-remove"
					@click="remove(subscription)">
					{{ t('decidiq', 'Remove') }}
				</NcButton>
			</li>
		</ul>
		<p v-else-if="!loading" class="user-settings-section__hint" data-testid="subscriptions-empty">
			{{ t('decidiq', 'You do not follow any body yet.') }}
		</p>

		<fieldset class="user-settings-section__group">
			<legend>{{ t('decidiq', 'Follow') }}</legend>
			<NcSelect
				v-model="form.bodies"
				:inputLabel="t('decidiq', 'Bodies (leave empty for every body)')"
				:options="bodies"
				label="label"
				:multiple="true"
				:loading="loading"
				data-testid="subscription-bodies" />
			<NcCheckboxRadioSwitch
				v-for="kind in kindOptions"
				:key="kind.key"
				:modelValue="form.kinds.includes(kind.key)"
				:data-testid="`subscription-kind-${kind.key}`"
				@update:modelValue="toggleKind(kind.key, $event)">
				{{ kind.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<fieldset class="user-settings-section__group">
			<legend>{{ t('decidiq', 'How often') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="frequency in frequencyOptions"
				:key="frequency.key"
				type="radio"
				name="decidiq-subscription-frequency"
				:value="frequency.key"
				:modelValue="form.frequency"
				:data-testid="`subscription-frequency-${frequency.key}`"
				@update:modelValue="form.frequency = $event">
				{{ frequency.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<NcNoteCard v-if="validationError" type="warning">
			{{ validationError }}
		</NcNoteCard>

		<div class="user-settings-section__actions">
			<NcButton
				variant="primary"
				:disabled="saving || !!validationError"
				data-testid="subscription-add"
				@click="add">
				{{ t('decidiq', 'Subscribe') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	bodiesUrl,
	buildSubscription,
	describeSubscription,
	listUrl,
	ownSubscriptions,
	subscriptionUrl,
	validateSubscription,
} from '../../utils/publicationSubscriptions.js'

export default {
	name: 'SubscriptionsSection',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			uid: getCurrentUser()?.uid ?? '',
			subscriptions: [],
			bodies: [],
			form: { bodies: [], kinds: ['agenda'], frequency: 'daily' },
			loading: true,
			saving: false,
			error: '',
		}
	},

	computed: {
		kindOptions() {
			return [
				{ key: 'agenda', label: this.t('decidiq', 'Agendas') },
				{ key: 'paper', label: this.t('decidiq', 'Papers') },
				{ key: 'decision', label: this.t('decidiq', 'Decisions') },
				{ key: 'minutes', label: this.t('decidiq', 'Minutes') },
			]
		},

		frequencyOptions() {
			return [
				{ key: 'immediate', label: this.t('decidiq', 'Immediately') },
				{ key: 'daily', label: this.t('decidiq', 'Daily digest at 07:00') },
				{ key: 'weekly', label: this.t('decidiq', 'Weekly digest on Monday') },
			]
		},

		bodyNames() {
			return Object.fromEntries(this.bodies.map((body) => [body.id, body.label]))
		},

		validationError() {
			const reasons = {
				'Choose at least one thing to follow.': this.t('decidiq', 'Choose at least one thing to follow.'),
				'Choose how often you want to hear of it.': this.t('decidiq', 'Choose how often you want to hear of it.'),
			}
			const reason = validateSubscription(this.form)
			return reasons[reason] ?? reason
		},
	},

	async created() {
		await this.load()
	},

	methods: {
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [mine, bodies] = await Promise.all([
					axios.get(listUrl(this.uid)),
					axios.get(bodiesUrl()),
				])
				this.subscriptions = ownSubscriptions(mine.data?.results ?? [], this.uid)
				this.bodies = (bodies.data?.results ?? []).map((body) => ({
					id: body.id ?? body['@self']?.id,
					label: body.name ?? body.id,
				}))
			} catch (e) {
				this.error = this.t('decidiq', 'Your subscriptions could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		describe(subscription) {
			return describeSubscription(subscription, this.bodyNames, this.t)
		},

		toggleKind(kind, on) {
			const kinds = this.form.kinds.filter((k) => k !== kind)
			this.form.kinds = on ? [...kinds, kind] : kinds
		},

		async add() {
			this.saving = true
			this.error = ''
			try {
				await axios.post(subscriptionUrl(), buildSubscription(this.uid, this.form))
				await this.load()
			} catch (e) {
				this.error = this.t('decidiq', 'The subscription could not be saved.')
			} finally {
				this.saving = false
			}
		},

		async remove(subscription) {
			this.saving = true
			this.error = ''
			try {
				await axios.delete(subscriptionUrl(subscription.id))
				this.subscriptions = this.subscriptions.filter((row) => row.id !== subscription.id)
			} catch (e) {
				this.error = this.t('decidiq', 'The subscription could not be removed.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.user-settings-section {
	margin-bottom: 24px;
}

.user-settings-section__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.user-settings-section__group {
	border: 0;
	margin: 12px 0;
	padding: 0;
}

.user-settings-section__group legend {
	font-weight: bold;
	margin-bottom: 4px;
}

.user-settings-section__actions {
	margin-top: 12px;
}

.subscriptions-list {
	margin: 8px 0;
	padding: 0;
	list-style: none;
}

.subscriptions-list__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}
</style>
