// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'

/**
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-3.4
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4.3
 */

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

// Minutes, Decisions, Action Items — p2-minutes-and-decisions
const Minutes = () => import('../views/Minutes.vue')
const MinutesDetail = () => import('../views/MinutesDetail.vue')
const Decisions = () => import('../views/Decisions.vue')
const DecisionDetail = () => import('../views/DecisionDetail.vue')
const ActionItems = () => import('../views/ActionItems.vue')
const ActionItemDetail = () => import('../views/ActionItemDetail.vue')

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/decidesk') + '/',
	routes: [
		{ path: '/', name: 'Dashboard', component: DashboardView },
		{ path: '/governance-bodies', name: 'GovernanceBodies', component: GovernanceBodies },
		{ path: '/governance-bodies/:id', name: 'GovernanceBodyDetail', component: GovernanceBodyDetail, props: true },
		{ path: '/meetings', name: 'Meetings', component: Meetings },
		{ path: '/meetings/:id', name: 'MeetingDetail', component: MeetingDetail, props: true },
		{ path: '/participants', name: 'Participants', component: Participants },
		{ path: '/participants/:id', name: 'ParticipantDetail', component: ParticipantDetail, props: true },
		{ path: '/agenda-items', name: 'AgendaItems', component: AgendaItems },
		{ path: '/agenda-items/:id', name: 'AgendaItemDetail', component: AgendaItemDetail, props: true },
		// Minutes routes — @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4.3
		{ path: '/minutes', name: 'Minutes', component: Minutes },
		{ path: '/minutes/:id', name: 'MinutesDetail', component: MinutesDetail, props: true },
		// Decision routes — @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4.3
		{ path: '/decisions', name: 'Decisions', component: Decisions },
		{ path: '/decisions/:id', name: 'DecisionDetail', component: DecisionDetail, props: true },
		// Action Item routes — @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4.3
		{ path: '/action-items', name: 'ActionItems', component: ActionItems },
		{ path: '/action-items/:id', name: 'ActionItemDetail', component: ActionItemDetail, props: true },
		{ path: '/settings', name: 'Settings', component: SettingsView },
		{ path: '*', redirect: '/' },
	],
})
