// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The steps the relation tabs that list, create, edit and delete the objects
 * under their parent all take: load the rows, open the form, save a row and
 * delete one. Each tab describes its relation with `relationListing()`:
 *
 * - `type`: the relation type and schema slug;
 * - `schemaKey`: the data key the tab keeps the fetched schema under;
 * - `filter`: the fields that tie a row to the parent, written again on save;
 * - `failedText`: the message shown when a load fails without one.
 *
 * @spec openspec/specs/relation-tab-ui/spec.md
 */
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { ensureRelationType } from './useRelationStore.js'

/**
 * The tab's relation store, with the schema fetched once into the tab.
 *
 * @param {object} vm The tab.
 * @return {Promise<object>} The store.
 */
export async function relationStore(vm) {
	const listing = vm.relationListing()
	const store = ensureRelationType(listing.type)
	if (!vm[listing.schemaKey]) {
		vm[listing.schemaKey] = await store.fetchSchema(listing.type)
	}
	return store
}

/**
 * Load the rows under the tab's parent; nothing without a parent.
 *
 * @param {object} vm The tab.
 * @return {Promise<void>}
 */
export async function loadRelationRows(vm) {
	if (!vm.objectId) return
	vm.loading = true
	vm.error = ''
	const listing = vm.relationListing()
	try {
		const store = await relationStore(vm)
		const items = await store.fetchCollection(listing.type, {
			...listing.filter,
			_limit: 100,
		})
		vm.rows = items || []
	} catch (e) {
		vm.error = e?.message || listing.failedText
	} finally {
		vm.loading = false
	}
}

/**
 * Open the form, for a new row or a copy of the row being edited.
 *
 * @param {object} vm The tab.
 * @param {object|null} row The row to edit, or null to create.
 * @return {Promise<void>}
 */
export async function openRelationForm(vm, row) {
	await relationStore(vm)
	vm.editTarget = row ? { ...row } : null
	vm.formOpen = true
}

/**
 * Save the form's row under the parent and reload, or report why not.
 *
 * @param {object} vm The tab.
 * @param {object} formData The submitted form values.
 * @return {Promise<void>}
 */
export async function saveRelationRow(vm, formData) {
	const listing = vm.relationListing()
	const store = ensureRelationType(listing.type)
	try {
		await store.saveObject(listing.type, { ...formData, ...listing.filter })
		vm.$refs.formDialog?.setResult({ success: true })
		vm.refresh()
	} catch (e) {
		vm.$refs.formDialog?.setResult({
			error: e?.message || vm.t('decidiq', 'Save failed.'),
		})
	}
}

/**
 * Delete the row the delete dialog confirmed and reload, or report why not.
 *
 * @param {object} vm The tab.
 * @return {Promise<void>}
 */
export async function deleteRelationRow(vm) {
	const listing = vm.relationListing()
	const store = ensureRelationType(listing.type)
	try {
		await store.deleteObject(listing.type, vm.deleteTarget.id)
		vm.$refs.deleteDialog?.setResult({ success: true })
		vm.refresh()
	} catch (e) {
		vm.$refs.deleteDialog?.setResult({
			error: e?.message || vm.t('decidiq', 'Delete failed.'),
		})
	}
}

/**
 * The relation tab behaviour as a mixin: reload when the parent changes, and
 * the list, open, save and delete methods over the tab's `relationListing()`.
 * A tab that saves or deletes another way declares its own `onConfirm` or
 * `confirmDelete`, which takes precedence.
 *
 * @spec openspec/specs/relation-tab-ui/spec.md
 */
export const relationCrudTab = {
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/relation-tab-ui/spec.md */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async refresh() {
			await loadRelationRows(this)
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async openCreate() {
			await openRelationForm(this, null)
		},

		/**
		 * @param {object} row The row to edit.
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async openEdit(row) {
			await openRelationForm(this, row)
		},

		/**
		 * The discriminator in the listing's filter is written on save, not
		 * picked on the form, so a new row stays inside the tab's own filter.
		 *
		 * @param {object} formData The submitted form values.
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async onConfirm(formData) {
			await saveRelationRow(this, formData)
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async confirmDelete() {
			await deleteRelationRow(this)
		},
	},
}

/**
 * The Edit and Delete row actions of a relation table, as a mixin.
 *
 * @spec openspec/specs/relation-tab-ui/spec.md
 */
export const relationRowActions = {
	computed: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		rowActions() {
			return [
				{
					label: this.t('decidiq', 'Edit'),
					icon: Pencil,
					handler: (row) => this.openEdit(row),
				},
				{
					label: this.t('decidiq', 'Delete'),
					icon: TrashCanOutline,
					destructive: true,
					handler: (row) => {
						this.deleteTarget = { ...row }
					},
				},
			]
		},
	},
}
