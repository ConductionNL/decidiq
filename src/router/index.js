/**
 * Vue Router configuration for Decidesk.
 *
 * Flat routes for all 17 entity types (index + detail) plus dashboard and settings.
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-4
 */
import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'

import Dashboard from '../views/Dashboard.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'

import GovernanceBodyIndex from '../views/GovernanceBody/Index.vue'
import GovernanceBodyDetail from '../views/GovernanceBody/Detail.vue'
import ParticipantIndex from '../views/Participant/Index.vue'
import ParticipantDetail from '../views/Participant/Detail.vue'
import MeetingIndex from '../views/Meeting/Index.vue'
import MeetingDetail from '../views/Meeting/Detail.vue'
import AgendaItemIndex from '../views/AgendaItem/Index.vue'
import AgendaItemDetail from '../views/AgendaItem/Detail.vue'
import MotionIndex from '../views/Motion/Index.vue'
import MotionDetail from '../views/Motion/Detail.vue'
import AmendmentIndex from '../views/Amendment/Index.vue'
import AmendmentDetail from '../views/Amendment/Detail.vue'
import VotingRoundIndex from '../views/VotingRound/Index.vue'
import VotingRoundDetail from '../views/VotingRound/Detail.vue'
import VoteIndex from '../views/Vote/Index.vue'
import VoteDetail from '../views/Vote/Detail.vue'
import DecisionIndex from '../views/Decision/Index.vue'
import DecisionDetail from '../views/Decision/Detail.vue'
import ActionItemIndex from '../views/ActionItem/Index.vue'
import ActionItemDetail from '../views/ActionItem/Detail.vue'
import MinutesIndex from '../views/Minutes/Index.vue'
import MinutesDetail from '../views/Minutes/Detail.vue'
import DigitalDocumentIndex from '../views/DigitalDocument/Index.vue'
import DigitalDocumentDetail from '../views/DigitalDocument/Detail.vue'
import MonetaryAmountIndex from '../views/MonetaryAmount/Index.vue'
import MonetaryAmountDetail from '../views/MonetaryAmount/Detail.vue'
import OfferIndex from '../views/Offer/Index.vue'
import OfferDetail from '../views/Offer/Detail.vue'
import OrderIndex from '../views/Order/Index.vue'
import OrderDetail from '../views/Order/Detail.vue'
import ProductIndex from '../views/Product/Index.vue'
import ProductDetail from '../views/Product/Detail.vue'
import ReportIndex from '../views/Report/Index.vue'
import ReportDetail from '../views/Report/Detail.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/decidesk'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/settings', name: 'Settings', component: AdminRoot },

		// Governance
		{ path: '/governance-bodies', name: 'GovernanceBodyIndex', component: GovernanceBodyIndex },
		{ path: '/governance-bodies/:id', name: 'GovernanceBodyDetail', component: GovernanceBodyDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/participants', name: 'ParticipantIndex', component: ParticipantIndex },
		{ path: '/participants/:id', name: 'ParticipantDetail', component: ParticipantDetail, props: (route) => ({ entityId: route.params.id }) },

		// Meetings
		{ path: '/meetings', name: 'MeetingIndex', component: MeetingIndex },
		{ path: '/meetings/:id', name: 'MeetingDetail', component: MeetingDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/agenda-items', name: 'AgendaItemIndex', component: AgendaItemIndex },
		{ path: '/agenda-items/:id', name: 'AgendaItemDetail', component: AgendaItemDetail, props: (route) => ({ entityId: route.params.id }) },

		// Deliberation
		{ path: '/motions', name: 'MotionIndex', component: MotionIndex },
		{ path: '/motions/:id', name: 'MotionDetail', component: MotionDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/amendments', name: 'AmendmentIndex', component: AmendmentIndex },
		{ path: '/amendments/:id', name: 'AmendmentDetail', component: AmendmentDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/voting-rounds', name: 'VotingRoundIndex', component: VotingRoundIndex },
		{ path: '/voting-rounds/:id', name: 'VotingRoundDetail', component: VotingRoundDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/votes', name: 'VoteIndex', component: VoteIndex },
		{ path: '/votes/:id', name: 'VoteDetail', component: VoteDetail, props: (route) => ({ entityId: route.params.id }) },

		// Outcomes
		{ path: '/decisions', name: 'DecisionIndex', component: DecisionIndex },
		{ path: '/decisions/:id', name: 'DecisionDetail', component: DecisionDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/action-items', name: 'ActionItemIndex', component: ActionItemIndex },
		{ path: '/action-items/:id', name: 'ActionItemDetail', component: ActionItemDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/minutes', name: 'MinutesIndex', component: MinutesIndex },
		{ path: '/minutes/:id', name: 'MinutesDetail', component: MinutesDetail, props: (route) => ({ entityId: route.params.id }) },

		// Documents
		{ path: '/digital-documents', name: 'DigitalDocumentIndex', component: DigitalDocumentIndex },
		{ path: '/digital-documents/:id', name: 'DigitalDocumentDetail', component: DigitalDocumentDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/reports', name: 'ReportIndex', component: ReportIndex },
		{ path: '/reports/:id', name: 'ReportDetail', component: ReportDetail, props: (route) => ({ entityId: route.params.id }) },

		// Commerce
		{ path: '/monetary-amounts', name: 'MonetaryAmountIndex', component: MonetaryAmountIndex },
		{ path: '/monetary-amounts/:id', name: 'MonetaryAmountDetail', component: MonetaryAmountDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/offers', name: 'OfferIndex', component: OfferIndex },
		{ path: '/offers/:id', name: 'OfferDetail', component: OfferDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/orders', name: 'OrderIndex', component: OrderIndex },
		{ path: '/orders/:id', name: 'OrderDetail', component: OrderDetail, props: (route) => ({ entityId: route.params.id }) },
		{ path: '/products', name: 'ProductIndex', component: ProductIndex },
		{ path: '/products/:id', name: 'ProductDetail', component: ProductDetail, props: (route) => ({ entityId: route.params.id }) },

		// Catch-all
		{ path: '*', redirect: '/' },
	],
})
