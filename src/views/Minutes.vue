<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.
@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5
-->
<template>
	<div class="decidesk-minutes">
		<div class="decidesk-minutes__header">
			<h2>{{ t('decidesk', 'Notulen') }}</h2>
			<NcButton type="primary" @click="createNew">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('decidesk', 'Nieuwe notulen') }}
			</NcButton>
		</div>

		<div class="decidesk-minutes__filters">
			<NcTextField
				:value.sync="search"
				:label="t('decidesk', 'Zoeken')"
				:placeholder="t('decidesk', 'Zoek op titel...')"
				@update:value="onSearch" />
			<NcSelect
				v-model="lifecycleFilter"
				:options="lifecycleOptions"
				:placeholder="t('decidesk', 'Alle statussen')"
				:label="t('decidesk', 'Status')"
				@input="onFilter" />
		</div>

		<NcLoadingIcon v-if="loading" :size="48" class="decidesk-minutes__loading" />

		<NcEmptyContent
			v-else-if="!loading && minutes.length === 0"
			:name="t('decidesk', 'Geen notulen gevonden')"
			:description="t('decidesk', 'Er zijn nog geen notulen aangemaakt.')">
			<template #icon>
				<FileDocumentOutline :size="48" />
			</template>
		</NcEmptyContent>

		<table v-else class="decidesk-minutes__table">
			<thead>
				<tr>
					<th>{{ t('decidesk', 'Titel') }}</th>
					<th>{{ t('decidesk', 'Status') }}</th>
					<th>{{ t('decidesk', 'Versie') }}</th>
					<th>{{ t('decidesk', 'Goedgekeurd op') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="item in minutes"
					:key="item.id"
					class="decidesk-minutes__row"
					@click="openDetail(item)">
					<td class="decidesk-minutes__cell--title">{{ item.title }}</td>
					<td>
						<span :class="['decidesk-status-badge', 'decidesk-status-badge--' + item.lifecycle]">
							{{ t('decidesk', item.lifecycle || 'draft') }}
						</span>
					</td>
					<td>{{ item.version || 1 }}</td>
					<td>{{ formatDate(item.approvedAt) }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { useMinutesStore } from '../store/modules/minutes.js'

export default {
	name: 'Minutes',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		FileDocumentOutline,
		PlusIcon,
	},
	data() {
		return {
			search: '',
			lifecycleFilter: null,
			lifecycleOptions: [
				{ label: t('decidesk', 'Concept'), value: 'draft' },
				{ label: t('decidesk', 'Ter beoordeling'), value: 'review' },
				{ label: t('decidesk', 'Goedgekeurd'), value: 'approved' },
				{ label: t('decidesk', 'Ondertekend'), value: 'signed' },
				{ label: t('decidesk', 'Gepubliceerd'), value: 'published' },
			],
		}
	},
	computed: {
		minutes() {
			return useMinutesStore().minutes
		},
		loading() {
			return useMinutesStore().loading
		},
	},
	created() {
		useMinutesStore().fetchMinutes()
	},
	methods: {
		openDetail(item) {
			this.$router.push({ name: 'MinutesDetail', params: { id: item.id } })
		},
		createNew() {
			this.$router.push({ name: 'MinutesDetail', params: { id: 'new' } })
		},
		onSearch() {
			useMinutesStore().fetchMinutes({ search: this.search })
		},
		onFilter() {
			useMinutesStore().fetchMinutes({ lifecycle: this.lifecycleFilter?.value })
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-minutes {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.decidesk-minutes__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.decidesk-minutes__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.decidesk-minutes__filters {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
}

.decidesk-minutes__loading {
	display: block;
	margin: 40px auto;
}

.decidesk-minutes__table {
	width: 100%;
	border-collapse: collapse;
}

.decidesk-minutes__table th {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.decidesk-minutes__row {
	cursor: pointer;
}

.decidesk-minutes__row:hover {
	background: var(--color-background-hover);
}

.decidesk-minutes__row td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-minutes__cell--title {
	font-weight: 500;
}

.decidesk-status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 500;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.decidesk-status-badge--draft {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.decidesk-status-badge--review {
	background: var(--color-warning-light);
	color: var(--color-warning-text);
}

.decidesk-status-badge--approved {
	background: var(--color-success-light);
	color: var(--color-success-text);
}

.decidesk-status-badge--signed {
	background: var(--color-primary-light);
	color: var(--color-primary-text);
}

.decidesk-status-badge--published {
	background: var(--color-success);
	color: var(--color-main-background);
}
</style>
