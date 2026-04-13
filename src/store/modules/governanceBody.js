// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore, filesPlugin, auditTrailsPlugin, relationsPlugin } from '@conduction/nextcloud-vue'

export const useGovernanceBodyStore = createObjectStore('governance-body', {
	plugins: [filesPlugin(), auditTrailsPlugin(), relationsPlugin()],
})
