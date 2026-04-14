// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Vue Router configuration for Decidesk.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-3.4
 */

import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'

Vue.use(Router)

const DashboardView = () => import('../views/DashboardView.vue')
const GovernanceBodies = () => import('../views/GovernanceBodies.vue')
const GovernanceBodyDetail = () => import('../views/GovernanceBodyDetail.vue')
const Meetings = () => import('../views/Meetings.vue')
const MeetingDetail = () => import('../views/MeetingDetail.vue')
const Participants = () => import('../views/Participants.vue')
const ParticipantDetail = () => import('../views/ParticipantDetail.vue')
const AgendaItems = () => import('../views/AgendaItems.vue')
const AgendaItemDetail = () => import('../views/AgendaItemDetail.vue')
const SettingsView = () => import('../views/SettingsView.vue')

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/decidesk') + '/',
	routes: [
		{ path: '/', name: 'Dashboard', component: DashboardView },
		{ path: '/governance-bodies', name: 'GovernanceBodies', component: GovernanceBodies },
		{ path: '/governance-bodies/:id', name: 'GovernanceBodyDetail', component: GovernanceBodyDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/meetings', name: 'Meetings', component: Meetings },
		{ path: '/meetings/:id', name: 'MeetingDetail', component: MeetingDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/participants', name: 'Participants', component: Participants },
		{ path: '/participants/:id', name: 'ParticipantDetail', component: ParticipantDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/agenda-items', name: 'AgendaItems', component: AgendaItems },
		{ path: '/agenda-items/:id', name: 'AgendaItemDetail', component: AgendaItemDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/settings', name: 'Settings', component: SettingsView },
		{ path: '*', redirect: '/' },
	],
})
