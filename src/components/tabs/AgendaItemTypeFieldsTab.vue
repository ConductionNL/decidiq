<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Detail widget: the fields an agenda item's type declares (#1393).

 The generic data widget skips `typeFields` (a free object) and knows nothing
 of the type's labels and field types, so an answer to a technical question,
 or a letter's commitment reference, could not be seen by its label nor
 entered. This widget loads the item and its type, renders the declared
 fields through AgendaItemTypeFields, and saves them into `typeFields`.
 Stored values under keys the type does not declare (for example from a
 migration) are listed read-only so they are not invisible.

 @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--type-fields"
		data-testid="agenda-item-type-fields-tab">
		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load the fields')">
			{{ error }}
		</CnNoteCard>

		<p v-else-if="loading" class="decidiq-tab__muted">
			{{ t('decidiq', 'Loading…') }}
		</p>

		<template v-else>
			<p v-if="!itemType" class="decidiq-tab__muted">
				{{
					t(
						'decidiq',
						'This agenda item has no type, so it has no extra fields.',
					)
				}}
			</p>
			<p v-else-if="inputs.length === 0" class="decidiq-tab__muted">
				{{
					t('decidiq', 'The type {type} declares no extra fields.', {
						type: itemType.name || '',
					})
				}}
			</p>
			<template v-else>
				<AgendaItemTypeFields
					v-model="values"
					:type="itemType"
					:legend="itemType.name || ''" />
				<CnNoteCard
					v-if="saveError"
					type="error"
					:title="t('decidiq', 'Save failed.')">
					{{ saveError }}
				</CnNoteCard>
				<div class="decidiq-tab__actions">
					<NcButton
						variant="primary"
						data-testid="agenda-item-type-fields-save"
						:disabled="saving || !dirty"
						@click="save">
						{{ saving ? t('decidiq', 'Saving…') : t('decidiq', 'Save') }}
					</NcButton>
				</div>
			</template>

			<dl v-if="undeclared.length > 0" class="decidiq-tab__undeclared">
				<dt class="decidiq-tab__undeclared-title">
					{{ t('decidiq', 'Other stored values') }}
				</dt>
				<dd v-for="entry in undeclared" :key="entry.key">
					{{ entry.key }}: {{ entry.value }}
				</dd>
			</dl>
		</template>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import AgendaItemTypeFields from '../AgendaItemTypeFields.vue'
import {
	findItemType,
	missingRequiredTypeFields,
	typeFieldInputs,
} from '../../utils/agendaItemTypeFields.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'AgendaItemTypeFieldsTab',
	components: {
		AgendaItemTypeFields,
		CnNoteCard,
		NcButton,
	},

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			item: null,
			types: [],
			values: {},
			saving: false,
			saveError: '',
		}
	},

	computed: {
		/** @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md */
		itemType() {
			return findItemType(this.types, this.item?.type)
		},

		/** @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md */
		inputs() {
			return typeFieldInputs(this.itemType)
		},

		/** @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md */
		dirty() {
			return (
				JSON.stringify(this.values)
				!== JSON.stringify(this.item?.typeFields || {})
			)
		},

		/** @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md */
		undeclared() {
			const declared = new Set(this.inputs.map((input) => input.key))
			return Object.entries(this.item?.typeFields || {})
				.filter(([key]) => !declared.has(key))
				.map(([key, value]) => ({
					key,
					value:
						value !== null && typeof value === 'object'
							? JSON.stringify(value)
							: String(value),
				}))
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/** @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('agenda-item')
				const typeStore = ensureRelationType('agenda-item-type')
				const [item, types] = await Promise.all([
					store.fetchObject('agenda-item', this.objectId),
					typeStore.fetchCollection('agenda-item-type', { _limit: 200 }),
				])
				this.item = item || null
				this.types = types || []
				this.values = { ...(item?.typeFields || {}) }
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Could not load the fields')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md */
		async save() {
			this.saveError = ''
			const missing = missingRequiredTypeFields(this.inputs, this.values)
			if (missing.length > 0) {
				this.saveError = this.t('decidiq', 'Fill in: {fields}', {
					fields: missing.join(', '),
				})
				return
			}
			this.saving = true
			try {
				const store = ensureRelationType('agenda-item')
				const saved = await store.saveObject('agenda-item', {
					...this.item,
					typeFields: this.values,
				})
				this.item = saved || { ...this.item, typeFields: this.values }
				this.values = { ...(this.item.typeFields || {}) }
			} catch (e) {
				this.saveError = e?.message || this.t('decidiq', 'Save failed.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-tab--type-fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.decidiq-tab__muted {
	color: var(--color-text-maxcontrast);
}

.decidiq-tab__actions {
	display: flex;
	justify-content: flex-end;
}

.decidiq-tab__undeclared {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.decidiq-tab__undeclared-title {
	font-weight: bold;
}
</style>
