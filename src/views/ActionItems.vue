<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.
@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7
-->
<template>
	<div class="decidesk-action-items">
		<div class="decidesk-action-items__header">
			<h2>{{ t('decidesk', 'Actiepunten') }}</h2>
			<NcButton type="primary" @click="createNew">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('decidesk', 'Nieuw actiepunt') }}
			</NcButton>
		</div>

		<div class="decidesk-action-items__filters">
			<NcTextField
				:value.sync="search"
				:label="t('decidesk', 'Zoeken')"
				:placeholder="t('decidesk', 'Zoek op titel...')"
				@update:value="onSearch" />
			<NcSelect
				v-model="statusFilter"
				:options="statusOptions"
				:placeholder="t('decidesk', 'Alle statussen')"
				@input="onFilter" />
			<NcTextField
				:value.sync="assigneeFilter"
				:label="t('decidesk', 'Verantwoordelijke')"
				:placeholder="t('decidesk', 'Filter op naam...')"
				@update:value="onFilter" />
		</div>

		<NcLoadingIcon v-if="loading" :size="48" class="decidesk-action-items__loading" />

		<NcEmptyContent
			v-else-if="!loading && actionItems.length === 0"
			:name="t('decidesk', 'Geen actiepunten gevonden')"
			:description="t('decidesk', 'Er zijn nog geen actiepunten aangemaakt.')">
			<template #icon>
				<CheckboxMarkedOutline :size="48" />
			</template>
		</NcEmptyContent>

		<table v-else class="decidesk-action-items__table">
			<thead>
				<tr>
					<th>{{ t('decidesk', 'Titel') }}</th>
					<th>{{ t('decidesk', 'Verantwoordelijke') }}</th>
					<th>{{ t('decidesk', 'Deadline') }}</th>
					<th>{{ t('decidesk', 'Status') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="item in actionItems"
					:key="item.id"
					:class="['decidesk-action-items__row', { 'decidesk-action-items__row--overdue': isOverdue(item) }]"
					@click="openDetail(item)">
					<td class="decidesk-action-items__cell--title">{{ item.title }}</td>
					<td>{{ item.assignee || '—' }}</td>
					<td :class="{ 'decidesk-action-items__cell--overdue': isOverdue(item) }">
						{{ formatDate(item.dueDate) }}
					</td>
					<td>
						<span :class="['decidesk-status-badge', statusBadgeClass(item)]">
							{{ statusLabel(item) }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { useActionItemStore } from '../store/modules/actionItems.js'

export default {
	name: 'ActionItems',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		CheckboxMarkedOutline,
		PlusIcon,
	},
	data() {
		return {
			search: '',
			statusFilter: null,
			assigneeFilter: '',
			statusOptions: [
				{ label: t('decidesk', 'Open'), value: 'open' },
				{ label: t('decidesk', 'In behandeling'), value: 'in-progress' },
				{ label: t('decidesk', 'Afgerond'), value: 'completed' },
				{ label: t('decidesk', 'Verlopen'), value: 'overdue' },
			],
		}
	},
	computed: {
		actionItems() {
			return useActionItemStore().actionItems
		},
		loading() {
			return useActionItemStore().loading
		},
	},
	created() {
		useActionItemStore().fetchActionItems()
	},
	methods: {
		openDetail(item) {
			this.$router.push({ name: 'ActionItemDetail', params: { id: item.id } })
		},
		createNew() {
			this.$router.push({ name: 'ActionItemDetail', params: { id: 'new' } })
		},
		onSearch() {
			useActionItemStore().fetchActionItems({ search: this.search })
		},
		onFilter() {
			useActionItemStore().fetchActionItems({
				taskStatus: this.statusFilter?.value,
				assignee: this.assigneeFilter || undefined,
			})
		},
		/**
		 * Client-side overdue detection for immediate visual feedback.
		 * The OverdueActionItemsJob persists this state server-side.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7
		 */
		isOverdue(item) {
			if (!item.dueDate || item.taskStatus === 'completed') return false
			return new Date(item.dueDate) < new Date() || item.taskStatus === 'overdue'
		},
		statusBadgeClass(item) {
			if (this.isOverdue(item)) return 'decidesk-status-badge--overdue'
			if (item.taskStatus === 'completed') return 'decidesk-status-badge--completed'
			if (item.taskStatus === 'in-progress') return 'decidesk-status-badge--in-progress'
			return 'decidesk-status-badge--open'
		},
		statusLabel(item) {
			if (this.isOverdue(item) && item.taskStatus !== 'overdue') {
				return t('decidesk', 'Verlopen')
			}

			const labels = {
				open: t('decidesk', 'Open'),
				'in-progress': t('decidesk', 'In behandeling'),
				completed: t('decidesk', 'Afgerond'),
				overdue: t('decidesk', 'Verlopen'),
			}
			return labels[item.taskStatus] || item.taskStatus
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-action-items {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.decidesk-action-items__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.decidesk-action-items__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.decidesk-action-items__filters {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
}

.decidesk-action-items__loading {
	display: block;
	margin: 40px auto;
}

.decidesk-action-items__table {
	width: 100%;
	border-collapse: collapse;
}

.decidesk-action-items__table th {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.decidesk-action-items__row {
	cursor: pointer;
}

.decidesk-action-items__row:hover {
	background: var(--color-background-hover);
}

.decidesk-action-items__row td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-action-items__row--overdue {
	border-left: 3px solid var(--color-error);
}

.decidesk-action-items__cell--title {
	font-weight: 500;
}

.decidesk-action-items__cell--overdue {
	color: var(--color-error);
	font-weight: 500;
}

.decidesk-status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 500;
}

.decidesk-status-badge--open {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.decidesk-status-badge--in-progress {
	background: var(--color-warning-light);
	color: var(--color-warning-text);
}

.decidesk-status-badge--completed {
	background: var(--color-success-light);
	color: var(--color-success-text);
}

.decidesk-status-badge--overdue {
	background: var(--color-error-light);
	color: var(--color-error-text);
}
</style>
