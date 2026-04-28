// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// `setActivePinia` is required because `main.js` runs
// `initializeStores()` BEFORE `app.$mount()` (so the canonical object
// store can register types before any view's setup() fetches data).
// At that point Pinia hasn't been bound to the Vue app yet, and any
// `useStore()` call would otherwise return a detached store missing
// its `_s` plugin chain — the registrations would silently no-op.
// Activating the instance here makes the pre-mount store bootstrap
// work.
import { createPinia, setActivePinia } from 'pinia'

const pinia = createPinia()
setActivePinia(pinia)

export default pinia
