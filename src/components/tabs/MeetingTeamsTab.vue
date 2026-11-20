<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Meeting page widget: the Microsoft Teams meeting of a digital or hybrid
 meeting (platform-microsoft-365-calendar-and-teams, matrix row plt-12).
 Members get Join in Microsoft Teams once the meeting has a join address.
 The chair or secretary creates the Teams meeting through the Microsoft 365
 source linked in integriq; without a linked source the button is not shown
 and the join address can still be pasted in Planning. An in-person meeting
 without a Teams meeting shows nothing.

 @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
 @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-005-members-join-the-teams-meeting-from-decidiq
-->
<template>
	<div v-if="shown" class="teams-meeting" data-testid="meeting-teams">
		<NcLoadingIcon v-if="loading" :size="24" />
		<template v-else-if="joinUrl">
			<NcButton
				variant="primary"
				:href="joinUrl"
				target="_blank"
				rel="noopener noreferrer"
				data-testid="meeting-teams-join">
				{{ t('decidiq', 'Join in Microsoft Teams') }}
			</NcButton>
			<p
				v-if="createdBy"
				class="teams-meeting__muted"
				data-testid="meeting-teams-created">
				{{
					t('decidiq', 'Set up by {name} at {time}', {
						name: createdBy,
						time: formatTime(createdAt),
					})
				}}
			</p>
		</template>
		<template v-else-if="canCreate">
			<p class="teams-meeting__muted">
				{{
					t(
						'decidiq',
						'Create a Teams meeting with the title, start and end of this meeting in the organisation\'s Microsoft 365.',
					)
				}}
			</p>
			<NcButton
				variant="primary"
				:disabled="working"
				data-testid="meeting-teams-create"
				@click="create">
				{{ t('decidiq', 'Create a Teams meeting') }}
			</NcButton>
		</template>
		<p
			v-else-if="status && !status.connected"
			class="teams-meeting__muted"
			data-testid="meeting-teams-not-connected">
			{{
				t(
					'decidiq',
					'No Microsoft 365 service is connected. You can paste a Teams join address in Planning.',
				)
			}}
		</p>
		<p v-else class="teams-meeting__muted">
			{{ t('decidiq', 'This meeting has no Teams meeting yet.') }}
		</p>
		<p v-if="message" class="teams-meeting__message" role="status">
			{{ message }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import {
	canCreateTeams,
	isOnlineMeeting,
	teamsJoinUrl,
	teamsMeetingUrl,
} from '../../utils/teamsMeeting.js'
import { objectIdLoader } from './objectIdLoader.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingTeamsTab',

	components: { NcButton, NcLoadingIcon },

	/** @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting */
	mixins: [objectIdLoader],

	data() {
		return {
			meeting: null,
			status: null,
			loading: false,
			working: false,
			message: '',
		}
	},

	computed: {
		/** @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-005-members-join-the-teams-meeting-from-decidiq */
		joinUrl() {
			return teamsJoinUrl(this.meeting)
		},

		/** @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting */
		shown() {
			return this.joinUrl !== '' || isOnlineMeeting(this.meeting)
		},

		/** @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting */
		canCreate() {
			return canCreateTeams(this.meeting, this.status)
		},

		/** @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting */
		createdBy() {
			return this.meeting?.teamsMeeting?.createdBy || ''
		},

		/** @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting */
		createdAt() {
			return this.meeting?.teamsMeeting?.createdAt || ''
		},
	},

	methods: {
		/**
		 * Read the meeting, then, for staff, whether Microsoft 365 is connected.
		 *
		 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
		 */
		async load() {
			this.status = null
			this.message = ''
			if (!this.objectId) return
			this.loading = true
			try {
				this.meeting = await ensureRelationType('meeting').fetchObject(
					'meeting',
					String(this.objectId),
				)
			} catch {
				this.meeting = null
			}
			if (this.shown && !this.joinUrl) {
				try {
					const response = await axios.get(
						teamsMeetingUrl(String(this.objectId)),
					)
					this.status = response.data || null
				} catch {
					// Not the chair or secretary (403), or not reachable: no button.
					this.status = null
				}
			}
			this.loading = false
		},

		/**
		 * Create the Teams meeting and show its join address.
		 *
		 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
		 */
		async create() {
			this.working = true
			this.message = ''
			try {
				const response = await axios.post(
					teamsMeetingUrl(String(this.objectId)),
				)
				this.meeting = { ...this.meeting, teamsMeeting: response.data }
			} catch (e) {
				this.message =
					e.response?.data?.message
					|| this.t('decidiq', 'The Teams meeting could not be created.')
			} finally {
				this.working = false
			}
		},

		/**
		 * A recorded time, in the reader's locale.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string} The time.
		 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-004-a-digital-or-hybrid-meeting-can-carry-a-teams-meeting
		 */
		formatTime(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.teams-meeting {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
}

.teams-meeting__muted {
	color: var(--color-text-maxcontrast);
}

.teams-meeting__message {
	color: var(--color-main-text);
}
</style>
