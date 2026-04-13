<template>
	<div class="decidesk-dashboard">
		<header class="decidesk-dashboard__header">
			<h2>{{ t('decidesk', 'Dashboard') }}</h2>
		</header>

		<CnKpiGrid :columns="4">
			<CnStatsBlock
				:title="t('decidesk', 'Governance Bodies')"
				:count="governanceBodyCount"
				:count-label="t('decidesk', 'total')"
				:icon="AccountGroupOutline"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Meetings')"
				:count="meetingCount"
				:count-label="t('decidesk', 'total')"
				:icon="CalendarClock"
				variant="default"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Participants')"
				:count="participantCount"
				:count-label="t('decidesk', 'total')"
				:icon="AccountOutline"
				variant="success"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Upcoming Meetings')"
				:count="upcomingMeetingCount"
				:count-label="t('decidesk', 'scheduled')"
				:icon="CalendarCheckOutline"
				variant="warning"
				horizontal />
		</CnKpiGrid>

		<div class="decidesk-dashboard__chart">
			<CnChartWidget
				:title="t('decidesk', 'Meeting Lifecycle Distribution')"
				type="donut"
				:data="lifecycleChartData"
				:loading="loading" />
		</div>
	</div>
</template>

<script>
import { CnChartWidget, CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import { useGovernanceBodyStore } from '../store/modules/governanceBody.js'
import { useMeetingStore } from '../store/modules/meeting.js'
import { useParticipantStore } from '../store/modules/participant.js'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import CalendarCheckOutline from 'vue-material-design-icons/CalendarCheckOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'

export default {
	name: 'Dashboard',
	components: {
		CnChartWidget,
		CnKpiGrid,
		CnStatsBlock,
	},
	data() {
		return {
			AccountGroupOutline,
			AccountOutline,
			CalendarCheckOutline,
			CalendarClock,
			governanceBodyCount: 0,
			meetingCount: 0,
			participantCount: 0,
			upcomingMeetingCount: 0,
			lifecycleCounts: {},
			loading: true,
		}
	},
	computed: {
		lifecycleChartData() {
			const labels = ['draft', 'scheduled', 'opened', 'paused', 'adjourned', 'closed']
			return {
				labels,
				datasets: [{
					data: labels.map((state) => this.lifecycleCounts[state] || 0),
				}],
			}
		},
	},
	async created() {
		const governanceBodyStore = useGovernanceBodyStore()
		const meetingStore = useMeetingStore()
		const participantStore = useParticipantStore()

		const [governanceBodies, meetings, participants] = await Promise.all([
			governanceBodyStore.fetchCollection('governanceBody', { _limit: 1 }),
			meetingStore.fetchCollection('meeting', { _limit: 999 }),
			participantStore.fetchCollection('participant', { _limit: 1 }),
		])

		const gbPagination = governanceBodyStore.getPagination('governanceBody')
		const meetingPagination = meetingStore.getPagination('meeting')
		const participantPagination = participantStore.getPagination('participant')

		this.governanceBodyCount = gbPagination.total || governanceBodies.length
		this.meetingCount = meetingPagination.total || meetings.length
		this.participantCount = participantPagination.total || participants.length

		// Count upcoming (scheduled) meetings and lifecycle distribution
		const allMeetings = meetingStore.getCollection('meeting')
		const counts = {}
		let upcoming = 0
		for (const meeting of allMeetings) {
			const state = meeting.lifecycle || 'draft'
			counts[state] = (counts[state] || 0) + 1
			if (state === 'scheduled') {
				upcoming++
			}
		}
		this.upcomingMeetingCount = upcoming
		this.lifecycleCounts = counts
		this.loading = false
	},
}
</script>

<style scoped>
.decidesk-dashboard {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.decidesk-dashboard__header {
	margin-bottom: 20px;
}

.decidesk-dashboard__header h2 {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
}

.decidesk-dashboard__chart {
	margin-top: 20px;
	max-width: 600px;
}
</style>
