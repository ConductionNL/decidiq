// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Object store — delegates to the @conduction/nextcloud-vue shared store.
 *
 * Using `createObjectStore` with plugins provides files, audit-trails and
 * relations sub-resource management on every registered object type.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-3.3
 */
import {
	createObjectStore,
	filesPlugin,
	auditTrailsPlugin,
	relationsPlugin,
} from '@conduction/nextcloud-vue'

export const useObjectStore = createObjectStore('decidesk-objects', {
	plugins: [filesPlugin(), auditTrailsPlugin(), relationsPlugin()],
})
