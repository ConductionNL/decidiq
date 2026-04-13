// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'

/**
 * Pinia object store for ActionItem entities.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8
 */
export const useActionItemStore = createObjectStore('action-item', {
	plugins: ['files', 'auditTrails', 'relations'],
})
