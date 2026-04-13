// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * OpenRegister object store — provided by the platform.
 *
 * Re-exports the canonical useObjectStore from @conduction/nextcloud-vue
 * per ADR-001: no custom CRUD stores; use the platform factory instead.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-2
 */
export { useObjectStore } from '@conduction/nextcloud-vue'
