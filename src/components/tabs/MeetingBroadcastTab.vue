<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Broadcast: the chair or secretary runs the meeting's public livestream
 (live-public-livestream, matrix rows liv-06 liv-10 liv-19 liv-22). A test
 on the staff preview, the test result, going live, pausing for a closed
 session, resuming and stopping. The streaming itself is done by the
 streaming service linked in integriq; without one the widget says so and
 shows no buttons. Anyone who is not staff of the meeting sees one line.

 @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
-->
<template>
	<div class="broadcast" data-testid="meeting-broadcast">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="forbidden" class="broadcast__muted">
			{{
				t(
					'decidiq',
					'The chair or secretary of the meeting runs the broadcast.',
				)
			}}
		</p>
		<p
			v-else-if="!connected"
			class="broadcast__muted"
			data-testid="meeting-broadcast-not-connected">
			{{ t('decidiq', 'No streaming service is connected') }}
		</p>
		<template v-else>
			<p class="broadcast__state" data-testid="meeting-broadcast-state">
				{{ stateLabel }}
			</p>
			<p
				v-if="
					broadcast
					&& broadcast.previewUrl
					&& broadcast.lifecycle === 'testing'
				">
				<a
					:href="broadcast.previewUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('decidiq', 'Open the staff preview') }}
				</a>
			</p>
			<p
				v-if="
					broadcast
					&& broadcast.playerUrl
					&& broadcast.lifecycle !== 'ended'
				">
				<a
					:href="broadcast.playerUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('decidiq', 'Open the public player') }}
				</a>
			</p>
			<p v-if="broadcast && broadcast.recordingUrl">
				<a
					:href="broadcast.recordingUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('decidiq', 'Open the recording') }}
				</a>
			</p>
			<p
				v-if="captions === 'unavailable'"
				class="broadcast__muted"
				data-testid="meeting-broadcast-captions">
				{{
					t(
						'decidiq',
						'Live captions are not available from this streaming service',
					)
				}}
			</p>
			<p v-else-if="captions === 'requested'" class="broadcast__muted">
				{{
					t('decidiq', 'Live captions were asked of the streaming service')
				}}
			</p>
			<div v-if="actions.includes('testResult')" class="broadcast__form">
				<NcCheckboxRadioSwitch
					v-model="testResult"
					type="radio"
					value="ok"
					name="broadcast-test-result">
					{{ t('decidiq', 'Picture and sound were fine') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="testResult"
					type="radio"
					value="problems"
					name="broadcast-test-result">
					{{ t('decidiq', 'There were problems') }}
				</NcCheckboxRadioSwitch>
				<NcTextArea
					v-model="testNote"
					:label="t('decidiq', 'What the test showed')"
					resize="vertical"
					data-testid="meeting-broadcast-test-note" />
			</div>
			<div class="broadcast__actions">
				<NcButton
					v-for="action in actions"
					:key="action"
					:disabled="working || (action === 'testResult' && !testResult)"
					:variant="
						action === 'start' || action === 'resume'
							? 'primary'
							: 'secondary'
					"
					:data-testid="`meeting-broadcast-${action}`"
					@click="run(action)">
					{{ actionLabel(action) }}
				</NcButton>
			</div>
		</template>
		<p
			v-if="!loading && !forbidden && result"
			class="broadcast__result"
			data-testid="meeting-broadcast-test-result">
			{{
				result.ok
					? t('decidiq', 'Test fine, recorded by {name} at {time}', {
							name: result.by,
							time: formatTime(result.at),
						})
					: t(
							'decidiq',
							'Test had problems, recorded by {name} at {time}',
							{ name: result.by, time: formatTime(result.at) },
						)
			}}<template v-if="result.note">: {{ result.note }}</template>
		</p>
		<p v-if="message" class="broadcast__message" role="status">
			{{ message }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcTextArea,
} from '@nextcloud/vue'
import {
	actionsFor,
	actionUrl,
	captionsNotice,
	statusUrl,
	testResultOf,
	testUrl,
} from '../../utils/meetingBroadcast.js'

export default {
	name: 'MeetingBroadcastTab',

	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcTextArea },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			working: false,
			forbidden: false,
			connected: false,
			broadcast: null,
			testResult: '',
			testNote: '',
			message: '',
		}
	},

	computed: {
		/** @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service */
		actions() {
			return actionsFor(this.broadcast, this.connected)
		},

		/** @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see */
		result() {
			return testResultOf(this.broadcast)
		},

		/** @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-005-live-captions-come-from-the-streaming-service */
		captions() {
			return captionsNotice(this.broadcast)
		},

		/** @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window */
		stateLabel() {
			return {
				planned: this.t('decidiq', 'The broadcast has not started.'),
				testing: this.t(
					'decidiq',
					'A test broadcast is running. Only staff can see it.',
				),

				live: this.t('decidiq', 'The meeting is live.'),
				paused: this.t('decidiq', 'Paused for a closed session.'),
				ended: this.t('decidiq', 'The broadcast has ended.'),
			}[this.broadcast?.lifecycle || 'planned']
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Read the connection and the meeting's broadcast.
		 *
		 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-003-going-live-needs-a-public-meeting-and-a-connected-streaming-service
		 */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.forbidden = false
			try {
				const response = await axios.get(statusUrl(String(this.objectId)))
				this.connected = response.data?.connected === true
				this.broadcast = response.data?.broadcast || null
			} catch (e) {
				const status = e.response?.status
				this.forbidden = status === 401 || status === 403
				if (!this.forbidden) {
					this.message = this.t(
						'decidiq',
						'The broadcast could not be read.',
					)
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * A button's label.
		 *
		 * @param {string} action The action.
		 * @return {string} The label.
		 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-004-a-closed-session-pauses-the-broadcast-and-closes-the-public-window
		 */
		actionLabel(action) {
			return (
				{
					test: this.t('decidiq', 'Run a test broadcast'),
					testResult: this.t('decidiq', 'Save the test result'),
					start: this.t('decidiq', 'Go live'),
					pause: this.t('decidiq', 'Pause for a closed session'),
					resume: this.t('decidiq', 'Resume'),
					stop: this.t('decidiq', 'Stop the broadcast'),
				}[action] || action
			)
		},

		/**
		 * A recorded time, in the reader's locale.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string} The time.
		 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
		 */
		formatTime(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
		},

		/**
		 * Run one broadcast action and show the broadcast it returns.
		 *
		 * @param {string} action The action.
		 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
		 */
		async run(action) {
			this.working = true
			this.message = ''
			try {
				const url =
					action === 'test'
						? testUrl(String(this.objectId))
						: actionUrl(this.broadcast.id, action)
				const body =
					action === 'testResult'
						? { result: this.testResult, note: this.testNote }
						: {}
				const response = await axios.post(url, body)
				this.broadcast = response.data
				if (action === 'testResult') {
					this.testResult = ''
					this.testNote = ''
				}
			} catch (e) {
				this.message =
					e.response?.data?.message
					|| this.t('decidiq', 'The streaming service did not answer.')
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.broadcast__muted {
	color: var(--color-text-maxcontrast);
}

.broadcast__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}

.broadcast__form {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
