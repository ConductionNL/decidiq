// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'

Vue.use(Router)

export default new Router({
	mode: 'hash',
	base: generateUrl('/apps/decidesk'),
	routes: [
		{ path: '/', name: 'Dashboard', component: () => import('../views/Dashboard.vue') },

		{ path: '/governance-bodies', name: 'GovernanceBodies', component: () => import('../views/GovernanceBodies.vue') },
		{ path: '/governance-bodies/:id', name: 'GovernanceBodyDetail', component: () => import('../views/GovernanceBodyDetail.vue'), props: true },

		{ path: '/meetings', name: 'Meetings', component: () => import('../views/Meetings.vue') },
		{ path: '/meetings/:id', name: 'MeetingDetail', component: () => import('../views/MeetingDetail.vue'), props: true },

		{ path: '/participants', name: 'Participants', component: () => import('../views/Participants.vue') },
		{ path: '/participants/:id', name: 'ParticipantDetail', component: () => import('../views/ParticipantDetail.vue'), props: true },

		{ path: '/agenda-items', name: 'AgendaItems', component: () => import('../views/AgendaItems.vue') },
		{ path: '/agenda-items/:id', name: 'AgendaItemDetail', component: () => import('../views/AgendaItemDetail.vue'), props: true },

		{ path: '/settings', name: 'Settings', component: () => import('../views/Settings.vue') },

		{ path: '*', redirect: '/' },
	],
})
