<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: motions tied to an Agenda Item.

 Posture: full CRUD. Agenda items own their motions; this tab fetches
 motions whose `agendaItem === parent.id`, lists them with lifecycle
 badges, and lets users add / edit / delete inline. Lifecycle
 transitions and voting still live on /motions/:id (see MotionDetail
 page) — this tab is the structural CRUD surface only.
-->
<template>
	<div class="decidiq-tab decidiq-tab--motions" data-testid="agenda-motions-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Motions') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<NcButton
				variant="primary"
				data-testid="agenda-motions-add"
				:aria-label="t('decidiq', 'Add motion')"
				@click="openCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidiq', 'Add motion') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load motions')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No motions for this agenda item yet.')"
			:loadingText="t('decidiq', 'Loading motions…')"
			@rowClick="openEdit">
			<template #column-lifecycle="{ value }">
				<CnStatusBadge
					v-if="value"
					:label="value"
					:colorMap="lifecycleColors" />
			</template>
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="rowActions" />
			</template>
		</CnDataTable>

		<CnFormDialog
			v-if="formOpen"
			ref="formDialog"
			:schema="motionSchema"
			:item="editTarget"
			:dialogTitle="
				editTarget ? t('decidiq', 'Edit motion') : t('decidiq', 'Add motion')
			"
			:excludeFields="excludedFields"
			@confirm="onConfirm"
			@close="formOpen = false" />

		<CnDeleteDialog
			v-if="deleteTarget"
			ref="deleteDialog"
			:item="deleteTarget"
			nameField="title"
			:dialogTitle="t('decidiq', 'Delete motion')"
			@confirm="confirmDelete"
			@close="deleteTarget = null" />
	</div>
</template>

<script>
import {
	CnDataTable,
	CnDeleteDialog,
	CnFormDialog,
	CnNoteCard,
	CnRowActions,
	CnStatusBadge,
} from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { DECISION_LIFECYCLE_COLORS } from '../../constants/decisionLifecycle.js'
import { relationCrudTab, relationRowActions } from './relationRows.js'

export default {
	name: 'AgendaMotionsTab',
	components: {
		CnDataTable,
		CnDeleteDialog,
		CnFormDialog,
		CnNoteCard,
		CnRowActions,
		CnStatusBadge,
		NcButton,
		Plus,
	},

	mixins: [relationCrudTab, relationRowActions],

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			motionSchema: null,
			formOpen: false,
			editTarget: null,
			deleteTarget: null,
		}
	},

	computed: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{ key: 'title', label: this.t('decidiq', 'Title') },
				{ key: 'proposer', label: this.t('decidiq', 'Proposer') },
				{ key: 'lifecycle', label: this.t('decidiq', 'Status') },
			]
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		lifecycleColors() {
			return DECISION_LIFECYCLE_COLORS
		},

		/**
		 * `decisionType` is excluded because it is this tab's DISCRIMINATOR,
		 * not a choice: every row here is a Decision of decisionType=motion
		 * (the fetch filters on it) and onConfirm writes the value itself.
		 * Leaving it on the form showed an EMPTY required picker —
		 * decision-types-as-configuration (#1099) dropped the enum from the
		 * stored schema, so the select had no options and blocked the save.
		 *
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		excludedFields() {
			return [
				'id',
				'uuid',
				'agendaItem',
				'decisionType',
				// Written by the server when an amendment is adopted (#1394).
				'originalText',
				'amendmentHistory',
				'created',
				'updated',
			]
		},
	},

	methods: {
		/**
		 * The relation this tab lists: its type, where it keeps the schema,
		 * the fields that tie a row to the parent and the load failure text.
		 *
		 * @return {object} The listing.
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		relationListing() {
			return {
				type: 'motion',
				schemaKey: 'motionSchema',
				filter: {
					decisionType: 'motion',
					agendaItem: this.objectId,
				},

				failedText: this.t('decidiq', 'Failed to load motions.'),
			}
		},
	},
}
</script>

<style scoped>
.decidiq-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidiq-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidiq-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}
</style>
