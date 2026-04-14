// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'
import Minutes from '../views/Minutes.vue'
import MinutesDetail from '../views/MinutesDetail.vue'
import Decisions from '../views/Decisions.vue'
import DecisionDetail from '../views/DecisionDetail.vue'
import ActionItems from '../views/ActionItems.vue'
import ActionItemDetail from '../views/ActionItemDetail.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/decidesk'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/settings', name: 'Settings', component: AdminRoot },
		// Minutes routes — @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		{ path: '/minutes', name: 'Minutes', component: Minutes },
		{ path: '/minutes/:id', name: 'MinutesDetail', component: MinutesDetail, props: true },
		// Decision routes — @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		{ path: '/decisions', name: 'Decisions', component: Decisions },
		{ path: '/decisions/:id', name: 'DecisionDetail', component: DecisionDetail, props: true },
		// Action item routes — @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
		{ path: '/action-items', name: 'ActionItems', component: ActionItems },
		{ path: '/action-items/:id', name: 'ActionItemDetail', component: ActionItemDetail, props: true },
		{ path: '*', redirect: '/' },
	],
})
