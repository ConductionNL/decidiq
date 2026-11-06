<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sessions: an evening split into parallel sessions (planning-parallel-sessions,
 matrix row pla-17). On the evening's page one column per session, with its
 room, chair, time, stage, first agenda items and live status, each linking to
 the session; the secretariat adds a session with the evening, its date and
 its body preset. On a session's page the widget names its evening and links
 the other sessions. The server keeps the shape (ParallelSessionListener).

 @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
-->
<template>
	<div class="sessions" data-testid="meeting-sessions">
		<NcLoadingIcon v-if="loading" :size="24" />
		<template v-else>
			<p v-if="error" class="sessions__error" role="alert">
				{{ error }}
			</p>
			<section
				v-if="evening"
				class="sessions__evening"
				data-testid="meeting-sessions-part-of">
				<p>
					{{ t('decidiq', 'Part of') }}
					<router-link
						data-testid="meeting-sessions-evening-link"
						:to="meetingRoute(evening.id)">
						{{ evening.title }}
					</router-link>
				</p>
				<ul
					v-if="siblings.length"
					class="sessions__siblings"
					:aria-label="t('decidiq', 'Other sessions of this evening')">
					<li v-for="sibling in siblings" :key="sibling.id">
						<router-link :to="meetingRoute(sibling.id)">
							{{ sessionLabel(sibling) }}
						</router-link>
					</li>
				</ul>
			</section>
			<template v-else>
				<div v-if="columns.length" class="sessions__columns">
					<article
						v-for="column in columns"
						:key="column.id"
						class="sessions__column"
						:data-testid="`meeting-sessions-column-${column.id}`">
						<h3 class="sessions__title">
							<router-link :to="meetingRoute(column.id)">
								{{ column.title }}
							</router-link>
						</h3>
						<p v-if="column.live" class="sessions__live">
							{{ liveLabel(column.live) }}
						</p>
						<dl class="sessions__facts">
							<dt>{{ t('decidiq', 'Room') }}</dt>
							<dd>{{ column.room || t('decidiq', 'Not set') }}</dd>
							<dt>{{ t('decidiq', 'Chair') }}</dt>
							<dd>{{ column.chair || t('decidiq', 'Not set') }}</dd>
							<dt>{{ t('decidiq', 'Time') }}</dt>
							<dd>{{ column.time || t('decidiq', 'Not set') }}</dd>
							<dt>{{ t('decidiq', 'Stage') }}</dt>
							<dd>
								{{ column.lifecycle || t('decidiq', 'Not set') }}
							</dd>
						</dl>
						<ol v-if="column.firstItems.length" class="sessions__items">
							<li v-for="item in column.firstItems" :key="item.id">
								{{ item.title }}
							</li>
						</ol>
						<p v-else class="sessions__muted">
							{{ t('decidiq', 'No agenda items yet') }}
						</p>
					</article>
				</div>
				<p v-else class="sessions__muted">
					{{ t('decidiq', 'This meeting has no sessions.') }}
				</p>
				<form
					class="sessions__add"
					data-testid="meeting-sessions-add"
					@submit.prevent="addSession">
					<NcTextField
						v-model="draft.title"
						:label="t('decidiq', 'Session title')"
						required />
					<NcTextField
						v-model="draft.room"
						:label="t('decidiq', 'Room')" />
					<NcTextField
						v-model="draft.time"
						type="time"
						:label="t('decidiq', 'Start time')"
						required />
					<NcButton
						type="submit"
						:disabled="saving || !draft.title || !draft.time">
						{{ t('decidiq', 'Add session') }}
					</NcButton>
				</form>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import {
	newSession,
	parentOf,
	referenceId,
	sessionColumns,
	sessionLabel,
	siblingsOf,
} from '../../utils/meetingSessions.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingSessionsTab',
	components: { NcButton, NcLoadingIcon, NcTextField },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			saving: false,
			error: '',
			meeting: null,
			evening: null,
			sessions: [],
			related: { agendaItems: {}, broadcasts: {}, chairs: {} },
			draft: { title: '', room: '', time: '' },
		}
	},

	computed: {
		/** @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side */
		columns() {
			return sessionColumns(this.sessions, this.related)
		},

		/** @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side */
		siblings() {
			return this.meeting ? siblingsOf(this.meeting, this.sessions) : []
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side */
		sessionLabel,

		/** @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side */
		meetingRoute(id) {
			return { name: 'MeetingDetail', params: { id } }
		},

		/** @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side */
		liveLabel(lifecycle) {
			const labels = {
				live: this.t('decidiq', 'Live now'),
				paused: this.t('decidiq', 'Broadcast paused'),
				ended: this.t('decidiq', 'Broadcast ended'),
			}
			return labels[lifecycle] || ''
		},

		/** @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('meeting')
				this.meeting = await store.fetchObject('meeting', this.objectId)
				const parent = parentOf(this.meeting)
				this.evening = parent
					? await store.fetchObject('meeting', parent)
					: null
				this.sessions =
					(await store.fetchCollection('meeting', {
						parentMeeting: parent || this.objectId,
						_limit: 50,
					})) || []
				if (!parent) {
					await this.loadRelated()
				}
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load the sessions.')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side */
		async loadRelated() {
			const items = ensureRelationType('agenda-item')
			const casts = ensureRelationType('meeting-broadcast')
			const people = ensureRelationType('participant')
			const related = { agendaItems: {}, broadcasts: {}, chairs: {} }
			await Promise.all(
				this.sessions.map(async (session) => {
					const [agenda, broadcast] = await Promise.all([
						items.fetchCollection('agenda-item', {
							meeting: session.id,
							_limit: 20,
						}),
						casts.fetchCollection('meeting-broadcast', {
							meeting: session.id,
							_limit: 1,
						}),
					])
					related.agendaItems[session.id] = agenda || []
					related.broadcasts[session.id] = (broadcast || [])[0] || null
					const chairId = referenceId(session.chair)
					if (chairId) {
						related.chairs[chairId] = await people.fetchObject(
							'participant',
							chairId,
						)
					}
				}),
			)
			this.related = related
		},

		/** @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side */
		async addSession() {
			if (!this.meeting) return
			this.saving = true
			this.error = ''
			try {
				await ensureRelationType('meeting').saveObject(
					'meeting',
					newSession(this.meeting, this.draft),
				)
				this.draft = { title: '', room: '', time: '' }
				await this.refresh()
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'The session could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.sessions__columns {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 12px;
}

.sessions__column {
	padding: 8px 12px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
}

.sessions__title {
	margin: 0 0 4px;
	font-size: 1.05em;
}

.sessions__live {
	margin: 0 0 4px;
	color: var(--color-error-text, #c9302c);
	font-weight: bold;
}

.sessions__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px 8px;
	margin: 0 0 8px;
}

.sessions__facts dt {
	color: var(--color-text-maxcontrast, #6b6b6b);
}

.sessions__facts dd {
	margin: 0;
}

.sessions__items {
	margin: 0;
	padding-inline-start: 20px;
}

.sessions__muted {
	color: var(--color-text-maxcontrast, #6b6b6b);
}

.sessions__error {
	color: var(--color-error-text, #c9302c);
}

.sessions__add {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
	margin-top: 12px;
}

.sessions__siblings {
	margin: 4px 0 0;
	padding-inline-start: 20px;
}
</style>
