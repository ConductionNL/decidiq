import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'
import MeetingDetail from '../views/MeetingDetail.vue'
import AgendaItemDetail from '../views/AgendaItemDetail.vue'
import LiveMeeting from '../views/LiveMeeting.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/decidesk'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/settings', name: 'Settings', component: AdminRoot },
		{ path: '/meetings/:id', name: 'MeetingDetail', component: MeetingDetail },
		{ path: '/meetings/:id/live', name: 'LiveMeeting', component: LiveMeeting },
		{ path: '/agenda-items/:id', name: 'AgendaItemDetail', component: AgendaItemDetail },
		{ path: '*', redirect: '/' },
	],
})
