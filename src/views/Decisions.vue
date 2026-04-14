<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.
@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6
-->
<template>
	<div class="decidesk-decisions">
		<div class="decidesk-decisions__header">
			<h2>{{ t('decidesk', 'Besluiten') }}</h2>
			<NcButton type="primary" @click="createNew">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('decidesk', 'Nieuw besluit') }}
			</NcButton>
		</div>

		<div class="decidesk-decisions__filters">
			<NcTextField
				:value.sync="search"
				:label="t('decidesk', 'Zoeken')"
				:placeholder="t('decidesk', 'Zoek op titel of tekst...')"
				@update:value="onSearch" />
			<NcSelect
				v-model="outcomeFilter"
				:options="outcomeOptions"
				:placeholder="t('decidesk', 'Alle uitkomsten')"
				@input="onFilter" />
			<NcSelect
				v-model="publishedFilter"
				:options="publishedOptions"
				:placeholder="t('decidesk', 'Alle publicaties')"
				@input="onFilter" />
		</div>

		<NcLoadingIcon v-if="loading" :size="48" class="decidesk-decisions__loading" />

		<NcEmptyContent
			v-else-if="!loading && decisions.length === 0"
			:name="t('decidesk', 'Geen besluiten gevonden')"
			:description="t('decidesk', 'Er zijn nog geen besluiten aangemaakt.')">
			<template #icon>
				<CheckDecagramIcon :size="48" />
			</template>
		</NcEmptyContent>

		<table v-else class="decidesk-decisions__table">
			<thead>
				<tr>
					<th>{{ t('decidesk', 'Titel') }}</th>
					<th>{{ t('decidesk', 'Uitkomst') }}</th>
					<th>{{ t('decidesk', 'Besluitdatum') }}</th>
					<th>{{ t('decidesk', 'Gepubliceerd') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="item in decisions"
					:key="item.id"
					class="decidesk-decisions__row"
					@click="openDetail(item)">
					<td class="decidesk-decisions__cell--title">{{ item.title }}</td>
					<td>
						<span :class="['decidesk-status-badge', 'decidesk-status-badge--' + item.outcome]">
							{{ item.outcome === 'adopted' ? t('decidesk', 'Aangenomen') : t('decidesk', 'Afgewezen') }}
						</span>
					</td>
					<td>{{ formatDate(item.decisionDate) }}</td>
					<td>
						<span v-if="item.isPublished" class="decidesk-status-badge decidesk-status-badge--published">
							{{ t('decidesk', 'Gepubliceerd') }}
						</span>
						<span v-else class="decidesk-status-badge decidesk-status-badge--unpublished">
							{{ t('decidesk', 'Niet gepubliceerd') }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { useDecisionStore } from '../store/modules/decisions.js'

export default {
	name: 'Decisions',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		CheckDecagramIcon,
		PlusIcon,
	},
	data() {
		return {
			search: '',
			outcomeFilter: null,
			publishedFilter: null,
			outcomeOptions: [
				{ label: t('decidesk', 'Aangenomen'), value: 'adopted' },
				{ label: t('decidesk', 'Afgewezen'), value: 'rejected' },
			],
			publishedOptions: [
				{ label: t('decidesk', 'Gepubliceerd'), value: 'true' },
				{ label: t('decidesk', 'Niet gepubliceerd'), value: 'false' },
			],
		}
	},
	computed: {
		decisions() {
			return useDecisionStore().decisions
		},
		loading() {
			return useDecisionStore().loading
		},
	},
	created() {
		useDecisionStore().fetchDecisions()
	},
	methods: {
		openDetail(item) {
			this.$router.push({ name: 'DecisionDetail', params: { id: item.id } })
		},
		createNew() {
			this.$router.push({ name: 'DecisionDetail', params: { id: 'new' } })
		},
		onSearch() {
			useDecisionStore().fetchDecisions({ search: this.search })
		},
		onFilter() {
			useDecisionStore().fetchDecisions({
				outcome: this.outcomeFilter?.value,
				isPublished: this.publishedFilter?.value,
			})
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-decisions {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.decidesk-decisions__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.decidesk-decisions__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.decidesk-decisions__filters {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
}

.decidesk-decisions__loading {
	display: block;
	margin: 40px auto;
}

.decidesk-decisions__table {
	width: 100%;
	border-collapse: collapse;
}

.decidesk-decisions__table th {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.decidesk-decisions__row {
	cursor: pointer;
}

.decidesk-decisions__row:hover {
	background: var(--color-background-hover);
}

.decidesk-decisions__row td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-decisions__cell--title {
	font-weight: 500;
}

.decidesk-status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 500;
}

.decidesk-status-badge--adopted {
	background: var(--color-success-light);
	color: var(--color-success-text);
}

.decidesk-status-badge--rejected {
	background: var(--color-error-light);
	color: var(--color-error-text);
}

.decidesk-status-badge--published {
	background: var(--color-success);
	color: var(--color-main-background);
}

.decidesk-status-badge--unpublished {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
</style>
