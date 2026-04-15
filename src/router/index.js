// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-4
import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'

/**
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-3.4
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-8.3
 */

Vue.use(Router)

import Dashboard from '../views/Dashboard.vue'
import GovernanceBodies from '../views/GovernanceBodies.vue'
import GovernanceBodyDetail from '../views/GovernanceBodyDetail.vue'
import Meetings from '../views/Meetings.vue'
import MeetingDetail from '../views/MeetingDetail.vue'
import Participants from '../views/Participants.vue'
import ParticipantDetail from '../views/ParticipantDetail.vue'
import AgendaItems from '../views/AgendaItems.vue'
import AgendaItemDetail from '../views/AgendaItemDetail.vue'
import Motions from '../views/Motions.vue'
import MotionDetail from '../views/MotionDetail.vue'
import AmendmentDetail from '../views/AmendmentDetail.vue'
import SettingsView from '../views/SettingsView.vue'
import Minutes from '../views/Minutes.vue'
import MinutesDetail from '../views/MinutesDetail.vue'
import Decisions from '../views/Decisions.vue'
import DecisionDetail from '../views/DecisionDetail.vue'
import ActionItems from '../views/ActionItems.vue'
import ActionItemDetail from '../views/ActionItemDetail.vue'

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/decidesk'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/governance-bodies', name: 'GovernanceBodies', component: GovernanceBodies },
		{ path: '/governance-bodies/:id', name: 'GovernanceBodyDetail', component: GovernanceBodyDetail, props: true },
		{ path: '/meetings', name: 'Meetings', component: Meetings },
		{ path: '/meetings/:id', name: 'MeetingDetail', component: MeetingDetail, props: true },
		{ path: '/participants', name: 'Participants', component: Participants },
		{ path: '/participants/:id', name: 'ParticipantDetail', component: ParticipantDetail, props: true },
		{ path: '/agenda-items', name: 'AgendaItems', component: AgendaItems },
		{ path: '/agenda-items/:id', name: 'AgendaItemDetail', component: AgendaItemDetail, props: true },
		{ path: '/motions', name: 'Motions', component: Motions },
		{ path: '/motions/:id', name: 'MotionDetail', component: MotionDetail, props: true },
		{ path: '/amendments/:id', name: 'AmendmentDetail', component: AmendmentDetail, props: true },
		{ path: '/settings', name: 'Settings', component: SettingsView },
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
