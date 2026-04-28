// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Vue-router configuration generated from `src/manifest.json`.
// Every page entry becomes a route mounted via `CnPageRenderer`;
// the renderer matches by route name (`page.id`) and dispatches based
// on `page.type` (today: all `custom` against `src/customComponents.js`).
//
// To add a new route: edit `manifest.json` — no router edit needed.
//
// @spec openspec/changes/p1-crud-operations/tasks.md#task-3.4
// @spec openspec/changes/p2-motion-and-voting/tasks.md#task-8.3
// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
// @spec openspec/changes/p2-agenda-management/tasks.md#task-4.5

import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import { CnPageRenderer } from '@conduction/nextcloud-vue'
import manifest from '../manifest.json'

Vue.use(Router)

const routes = manifest.pages.map((page) => ({
	name: page.id,
	path: page.route,
	component: CnPageRenderer,
	// Forward route params (e.g. `:id`) as props to the dispatched
	// component — existing detail views read `id` from props.
	props: page.route.includes(':'),
}))

// Catch-all: fall back to the dashboard for unknown paths, matching
// the pre-Tier-4 behaviour.
routes.push({ path: '*', redirect: '/' })

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/decidesk'),
	routes,
})
