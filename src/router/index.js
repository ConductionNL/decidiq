import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'
import MotionIndex from '../views/MotionIndex.vue'
import MotionDetail from '../views/MotionDetail.vue'
import AmendmentDetail from '../views/AmendmentDetail.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/decidesk'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/settings', name: 'Settings', component: AdminRoot },
		{ path: '/motions', name: 'MotionIndex', component: MotionIndex },
		{ path: '/motions/:id', name: 'MotionDetail', component: MotionDetail },
		{ path: '/amendments/:id', name: 'AmendmentDetail', component: AmendmentDetail },
		{ path: '*', redirect: '/' },
	],
})
