// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-3.3
 */

import { createObjectStore } from '@conduction/nextcloud-vue'

export const useParticipantStore = createObjectStore('participant', {
	plugins: ['files', 'auditTrails', 'relations'],
})
