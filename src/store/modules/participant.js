// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore, filesPlugin, auditTrailsPlugin, relationsPlugin } from '@conduction/nextcloud-vue'

export const useParticipantStore = createObjectStore('participant', {
	plugins: [filesPlugin(), auditTrailsPlugin(), relationsPlugin()],
})
