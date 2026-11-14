<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: amendments proposed against a Motion.

 Posture: full CRUD. Motions own their amendments via `parentMotion`;
 this tab fetches amendments where `parentMotion === parent.id`,
 lists them with lifecycle badges, and lets users add / edit / delete.
 Lifecycle transitions, voting, and conflict warnings remain on the
 standalone /amendments/:id detail page.
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--amendments"
		data-testid="motion-amendments-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Amendments') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<NcButton
				variant="primary"
				data-testid="motion-amendments-add"
				:aria-label="t('decidiq', 'Submit amendment')"
				@click="openCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidiq', 'Submit amendment') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load amendments')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No amendments for this motion yet.')"
			:loadingText="t('decidiq', 'Loading amendments…')"
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
			:schema="amendmentSchema"
			:item="editTarget"
			:dialogTitle="
				editTarget
					? t('decidiq', 'Edit amendment')
					: t('decidiq', 'Submit amendment')
			"
			:excludeFields="excludedFields"
			@confirm="onConfirm"
			@close="formOpen = false" />

		<CnDeleteDialog
			v-if="deleteTarget"
			ref="deleteDialog"
			:item="deleteTarget"
			nameField="title"
			:dialogTitle="t('decidiq', 'Delete amendment')"
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
	name: 'MotionAmendmentsTab',
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
			amendmentSchema: null,
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
		 * not a choice: every row here is a Decision of decisionType=amendment
		 * and onConfirm already writes the value itself. Leaving it on the
		 * form showed an EMPTY required picker — decision-types-as-configuration
		 * (#1099) dropped the enum from the stored schema, so the select had
		 * no options and blocked the save.
		 * `originalText` and `amendmentHistory` belong to the MOTION and are
		 * written by the server when an amendment is adopted (#1394); an
		 * amendment never carries them.
		 *
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		excludedFields() {
			return [
				'id',
				'uuid',
				'parentMotion',
				'decisionType',
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
				type: 'amendment',
				schemaKey: 'amendmentSchema',
				filter: {
					decisionType: 'amendment',
					amends: this.objectId,
				},

				failedText: this.t('decidiq', 'Failed to load amendments.'),
			}
		},
	},
}
</script>

<style scoped src="./relationTab.css"></style>
