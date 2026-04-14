// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'

/**
 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-3.4
 */

Vue.use(Router)

/**
 * Lazy-load view components to reduce initial bundle size.
 */
const DashboardView = () => import('../views/DashboardView.vue')
const MeetingList = () => import('../views/MeetingList.vue')
const MeetingDetail = () => import('../views/MeetingDetail.vue')
const MotionList = () => import('../views/MotionList.vue')
const MotionDetail = () => import('../views/MotionDetail.vue')
const DecisionList = () => import('../views/DecisionList.vue')
const DecisionDetail = () => import('../views/DecisionDetail.vue')
const ParticipantList = () => import('../views/ParticipantList.vue')
const ParticipantDetail = () => import('../views/ParticipantDetail.vue')
const GovernanceBodyList = () => import('../views/GovernanceBodyList.vue')
const GovernanceBodyDetail = () => import('../views/GovernanceBodyDetail.vue')
const SettingsView = () => import('../views/SettingsView.vue')
const LiveMeeting = () => import('../views/LiveMeeting.vue')
const AgendaItemDetail = () => import('../views/AgendaItemDetail.vue')

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/decidesk') + '/',
	routes: [
		{ path: '/', name: 'Dashboard', component: DashboardView },
		{ path: '/meetings', name: 'MeetingList', component: MeetingList },
		{ path: '/meetings/:id', name: 'MeetingDetail', component: MeetingDetail, props: true },
		{ path: '/meetings/:id/live', name: 'LiveMeeting', component: LiveMeeting, props: true },
		{ path: '/motions', name: 'MotionList', component: MotionList },
		{ path: '/motions/:id', name: 'MotionDetail', component: MotionDetail, props: true },
		{ path: '/decisions', name: 'DecisionList', component: DecisionList },
		{ path: '/decisions/:id', name: 'DecisionDetail', component: DecisionDetail, props: true },
		{ path: '/participants', name: 'ParticipantList', component: ParticipantList },
		{ path: '/participants/:id', name: 'ParticipantDetail', component: ParticipantDetail, props: true },
		{ path: '/governance-bodies', name: 'GovernanceBodyList', component: GovernanceBodyList },
		{ path: '/governance-bodies/:id', name: 'GovernanceBodyDetail', component: GovernanceBodyDetail, props: true },
		{ path: '/agenda-items/:id', name: 'AgendaItemDetail', component: AgendaItemDetail, props: true },
		{ path: '/settings', name: 'Settings', component: SettingsView },
		{ path: '*', redirect: '/' },
	],
})
