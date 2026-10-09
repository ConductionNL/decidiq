<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 AdhocMeetingPage: the Nieuw overleg page of board DcAdhocOverleg
 (meeting-ad-hoc-with-guests Task 3, pla-20). Anyone sets up a meeting
 without a governing body on one page: title, date and time, place, an
 optional Talk conversation, agenda points, papers, and colleagues and
 guests together, then Overleg aanmaken. Nothing is written before that
 button; src/utils/adhocMeeting.js writes it all in order and the page
 then opens the new meeting.

 @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
-->
<template>
	<div class="adhoc-meeting" data-testid="adhoc-meeting">
		<header class="adhoc-meeting__header">
			<h1 class="adhoc-meeting__title">
				{{ t('decidiq', 'New ad hoc meeting') }}
			</h1>
			<div class="adhoc-meeting__meta">
				<span class="adhoc-meeting__badge">{{
					t('decidiq', 'Meeting without a body')
				}}</span>
				<nav
					:aria-label="t('decidiq', 'Breadcrumb')"
					class="adhoc-meeting__crumbs">
					<router-link :to="{ name: 'Meetings' }">
						{{ t('decidiq', 'Meetings') }}
					</router-link>
					<span aria-hidden="true">/</span>
					<span aria-current="page">{{
						t('decidiq', 'New ad hoc meeting')
					}}</span>
				</nav>
				<span aria-hidden="true">·</span>
				<span>{{
					t(
						'decidiq',
						'Anyone can set up a meeting and invite people from outside',
					)
				}}</span>
			</div>
		</header>

		<section class="adhoc-meeting__card">
			<div class="adhoc-meeting__fields">
				<NcTextField
					v-model="form.title"
					data-testid="adhoc-title"
					:label="t('decidiq', 'Title')"
					:error="shown('title')"
					:helperText="
						shown('title') ? t('decidiq', 'Fill in a title.') : ''
					" />
				<div class="adhoc-meeting__when">
					<NcDateTimePickerNative
						id="adhoc-start"
						v-model="startDate"
						data-testid="adhoc-start"
						type="datetime-local"
						:label="t('decidiq', 'Date and time')" />
					<NcDateTimePickerNative
						id="adhoc-end"
						v-model="endDate"
						data-testid="adhoc-end"
						type="time"
						:label="t('decidiq', 'Until')" />
				</div>
				<NcTextField
					v-model="form.location"
					data-testid="adhoc-location"
					:label="t('decidiq', 'Place')"
					:error="shown('location')"
					:helperText="
						shown('location')
							? t(
									'decidiq',
									'Fill in a place, or create a Talk conversation.',
								)
							: ''
					" />
				<div class="adhoc-meeting__online">
					<span class="adhoc-meeting__label">
						{{ t('decidiq', 'Join online') }}
						<span class="adhoc-meeting__optional">{{
							t('decidiq', '(optional)')
						}}</span>
					</span>
					<NcCheckboxRadioSwitch
						v-model="form.talk"
						data-testid="adhoc-talk"
						type="switch">
						{{ t('decidiq', 'Create a Talk conversation') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
			<p v-if="shown('start')" class="adhoc-meeting__error" role="alert">
				{{ t('decidiq', 'Choose a date and time.') }}
			</p>
			<p v-if="shown('endTime')" class="adhoc-meeting__error" role="alert">
				{{ t('decidiq', 'The end time must be after the start.') }}
			</p>

			<div class="adhoc-meeting__section">
				<div class="adhoc-meeting__section-head">
					<h2>{{ t('decidiq', 'Agenda') }}</h2>
				</div>
				<ol class="adhoc-meeting__agenda" data-testid="adhoc-agenda">
					<li
						v-for="(point, index) in form.agenda"
						:key="index"
						class="adhoc-meeting__point">
						<span class="adhoc-meeting__number">{{ index + 1 }}</span>
						<span class="adhoc-meeting__point-title">{{ point }}</span>
						<NcButton
							variant="tertiary"
							:aria-label="
								t('decidiq', 'Remove {name}', { name: point })
							"
							@click="form.agenda.splice(index, 1)">
							<template #icon>
								<Delete :size="20" />
							</template>
						</NcButton>
					</li>
				</ol>
				<form class="adhoc-meeting__add-point" @submit.prevent="addPoint">
					<NcTextField
						v-model="newPoint"
						data-testid="adhoc-agenda-input"
						:label="t('decidiq', 'New agenda point')" />
					<NcButton
						type="submit"
						data-testid="adhoc-agenda-add"
						:disabled="!newPoint.trim()">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('decidiq', 'Add point') }}
					</NcButton>
				</form>
			</div>

			<div class="adhoc-meeting__section">
				<div class="adhoc-meeting__section-head">
					<h2>{{ t('decidiq', 'Papers') }}</h2>
				</div>
				<div
					class="adhoc-meeting__drop"
					:class="{ 'adhoc-meeting__drop--over': dragging }"
					data-testid="adhoc-papers"
					@dragover.prevent="dragging = true"
					@dragleave.prevent="dragging = false"
					@drop.prevent="dropPapers">
					{{ t('decidiq', 'Drag papers here or') }}
					<NcButton
						variant="tertiary-no-background"
						class="adhoc-meeting__choose"
						@click="$refs.files.click()">
						{{ t('decidiq', 'choose files') }}
					</NcButton>
					<input
						ref="files"
						class="hidden-visually"
						type="file"
						multiple
						tabindex="-1"
						aria-hidden="true"
						@change="choosePapers" />
					<template v-if="form.papers.length">
						<span aria-hidden="true">·</span>
						{{
							t('decidiq', 'Papers added: {names}', {
								names: form.papers.map((f) => f.name).join(', '),
							})
						}}
					</template>
				</div>
			</div>

			<div class="adhoc-meeting__section">
				<div class="adhoc-meeting__section-head">
					<h2>{{ t('decidiq', 'Participants') }}</h2>
					<div class="adhoc-meeting__actions">
						<NcButton
							data-testid="adhoc-guest-add"
							@click="guestDialogOpen = true">
							<template #icon>
								<EmailPlus :size="20" />
							</template>
							{{ t('decidiq', 'Invite a guest') }}
						</NcButton>
						<NcButton
							data-testid="adhoc-colleague-add"
							@click="openColleagues">
							<template #icon>
								<AccountPlus :size="20" />
							</template>
							{{ t('decidiq', 'Add a colleague') }}
						</NcButton>
					</div>
				</div>
				<div class="adhoc-meeting__table-wrap">
					<table
						:aria-label="t('decidiq', 'Participants')"
						class="adhoc-meeting__table"
						data-testid="adhoc-participants">
						<thead>
							<tr>
								<th scope="col">
									{{ t('decidiq', 'Name') }}
								</th>
								<th scope="col">
									{{ t('decidiq', 'Email') }}
								</th>
								<th scope="col">
									{{ t('decidiq', 'Kind') }}
								</th>
								<th scope="col">
									{{ t('decidiq', 'Actions') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-if="!people.length">
								<td colspan="4" class="adhoc-meeting__empty">
									{{ t('decidiq', 'No participants yet.') }}
								</td>
							</tr>
							<tr v-for="person in people" :key="person.key">
								<td>{{ person.name }}</td>
								<td>{{ person.email }}</td>
								<td>
									{{
										person.guest
											? t('decidiq', 'Guest')
											: t('decidiq', 'Staff member')
									}}
								</td>
								<td>
									<NcButton
										variant="tertiary"
										:aria-label="
											t('decidiq', 'Remove {name}', {
												name: person.name,
											})
										"
										@click="removePerson(person)">
										<template #icon>
											<Delete :size="20" />
										</template>
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p class="adhoc-meeting__hint">
					{{
						t(
							'decidiq',
							'Guests from outside get an invitation by email with a link to the agenda and the papers of this meeting, and see nothing else.',
						)
					}}
				</p>
			</div>

			<div
				v-if="failures.length"
				class="adhoc-meeting__failures"
				role="alert"
				data-testid="adhoc-failures">
				<p>
					{{
						t(
							'decidiq',
							'The meeting was created. Finish these steps on the meeting page:',
						)
					}}
				</p>
				<ul>
					<li v-for="(failure, index) in failures" :key="index">
						{{ failureLabel(failure) }}
					</li>
				</ul>
				<NcButton variant="primary" @click="openMeeting">
					{{ t('decidiq', 'Open the meeting') }}
				</NcButton>
			</div>
			<p
				v-if="error"
				class="adhoc-meeting__error"
				role="alert"
				data-testid="adhoc-error">
				{{ error }}
			</p>

			<div class="adhoc-meeting__footer">
				<NcButton data-testid="adhoc-cancel" @click="cancel">
					{{ t('decidiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="adhoc-create"
					:disabled="saving || !!meetingId"
					@click="create">
					{{
						saving
							? t('decidiq', 'Creating…')
							: t('decidiq', 'Create the meeting')
					}}
				</NcButton>
			</div>
		</section>

		<GuestInviteDialog
			v-if="guestDialogOpen"
			@staged="stageGuest"
			@close="guestDialogOpen = false" />
		<MeetingParticipantAddDialog
			v-if="colleagueDialogOpen"
			:candidates="candidates"
			:loading="loadingCandidates"
			@select="addColleague"
			@close="colleagueDialogOpen = false" />
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDateTimePickerNative,
	NcTextField,
} from '@nextcloud/vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import EmailPlus from 'vue-material-design-icons/EmailPlus.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import GuestInviteDialog from '../../dialogs/GuestInviteDialog.vue'
import MeetingParticipantAddDialog from '../../dialogs/MeetingParticipantAddDialog.vue'
import { ensureRelationType } from '../../components/tabs/useRelationStore.js'
import {
	createAdhocMeeting,
	createTalkRoom,
	missingFields,
	uploadPapers,
} from '../../utils/adhocMeeting.js'
import { inviteGuest, isGuest } from '../../utils/guestInvitation.js'

/**
 * A Date as the value of a datetime-local input.
 *
 * @param {Date|null} date The date
 * @return {string} YYYY-MM-DDTHH:MM in local time, or ''
 */
function localValue(date) {
	if (!(date instanceof Date) || Number.isNaN(date.getTime())) return ''
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

export default {
	name: 'AdhocMeetingPage',

	components: {
		AccountPlus,
		Delete,
		EmailPlus,
		GuestInviteDialog,
		MeetingParticipantAddDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDateTimePickerNative,
		NcTextField,
		Plus,
	},

	data() {
		return {
			form: {
				title: '',
				start: '',
				endTime: '',
				location: '',
				talk: false,
				agenda: [],
				papers: [],
				colleagues: [],
				guests: [],
			},

			startDate: null,
			endDate: null,
			newPoint: '',
			dragging: false,
			guestDialogOpen: false,
			colleagueDialogOpen: false,
			candidates: [],
			loadingCandidates: false,
			tried: false,
			saving: false,
			error: '',
			failures: [],
			meetingId: '',
		}
	},

	computed: {
		/**
		 * Colleagues and guests in one list, as the board draws them.
		 *
		 * @return {Array<object>} The rows
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		people() {
			return [
				...this.form.colleagues.map((c) => ({
					key: `c-${c.id}`,
					name: c.displayName || c.name || c.id,
					email: c.email || '',
					guest: false,
					ref: c,
				})),
				...this.form.guests.map((g) => ({
					key: `g-${g.email}`,
					name: g.name || g.email,
					email: g.email,
					guest: true,
					ref: g,
				})),
			]
		},

		/**
		 * The fields still missing or wrong.
		 *
		 * @return {Array<string>} The field keys
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		missing() {
			return missingFields(this.form)
		},
	},

	watch: {
		/**
		 * @param {Date|null} value The picked start
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		startDate(value) {
			this.form.start = localValue(value)
		},

		/**
		 * @param {Date|null} value The picked end time
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		endDate(value) {
			this.form.endTime = localValue(value).slice(11)
		},
	},

	methods: {
		/**
		 * Whether to show the message of a field: only after a first try.
		 *
		 * @param {string} key The field key
		 * @return {boolean} True when the field is missing after a try
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		shown(key) {
			return this.tried && this.missing.includes(key)
		},

		/** @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting */
		addPoint() {
			const point = this.newPoint.trim()
			if (!point) return
			this.form.agenda.push(point)
			this.newPoint = ''
		},

		/**
		 * @param {DragEvent} event The drop
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		dropPapers(event) {
			this.dragging = false
			this.addPapers(event.dataTransfer?.files)
		},

		/**
		 * @param {Event} event The file input change
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		choosePapers(event) {
			this.addPapers(event.target?.files)
			event.target.value = ''
		},

		/**
		 * @param {FileList|null} files The files
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		addPapers(files) {
			const known = new Set(this.form.papers.map((f) => f.name))
			for (const file of Array.from(files || [])) {
				if (!known.has(file.name)) this.form.papers.push(file)
			}
		},

		/**
		 * @param {object} guest The guest ({ name, email })
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		stageGuest(guest) {
			if (!this.form.guests.some((g) => g.email === guest.email)) {
				this.form.guests.push(guest)
			}
		},

		/** @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting */
		async openColleagues() {
			this.colleagueDialogOpen = true
			this.loadingCandidates = true
			try {
				const all = await ensureRelationType('participant').fetchCollection(
					'participant',
					{ _limit: 500 },
				)
				const picked = new Set(this.form.colleagues.map((c) => c.id))
				this.candidates = (all || []).filter(
					(p) => !isGuest(p) && !picked.has(p.id),
				)
			} catch {
				this.candidates = []
			} finally {
				this.loadingCandidates = false
			}
		},

		/**
		 * @param {object} participant The colleague picked
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		addColleague(participant) {
			this.colleagueDialogOpen = false
			this.form.colleagues.push(participant)
		},

		/**
		 * @param {object} person A row of the participants table
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		removePerson(person) {
			const list = person.guest ? this.form.guests : this.form.colleagues
			const index = list.indexOf(person.ref)
			if (index >= 0) list.splice(index, 1)
		},

		/**
		 * @param {object} failure A failed step
		 * @return {string} What to finish on the meeting page
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		failureLabel(failure) {
			switch (failure.step) {
				case 'agenda':
					return this.t('decidiq', 'The agenda points were not all saved.')
				case 'colleagues':
					return this.t('decidiq', 'The colleagues were not all added.')
				case 'papers':
					return this.t('decidiq', 'The papers were not uploaded.')
				case 'talk':
					return this.t(
						'decidiq',
						'The Talk conversation was not created.',
					)
				case 'mail':
					return this.t(
						'decidiq',
						'The invitation to {email} was not sent.',
						{ email: failure.detail },
					)
				default:
					return this.t('decidiq', 'A guest was not invited.')
			}
		},

		/**
		 * Overleg aanmaken: write everything, then open the meeting.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
		 */
		async create() {
			this.tried = true
			if (this.missing.length) return
			this.saving = true
			this.error = ''
			try {
				const result = await createAdhocMeeting(this.form, {
					saveObject: (slug, payload) =>
						ensureRelationType(slug).saveObject(slug, payload),
					uploadPapers,
					createTalkRoom,
					inviteGuest,
				})
				this.meetingId = result.meetingId
				this.failures = result.failures
				if (!this.failures.length) this.openMeeting()
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'The meeting could not be created.')
			} finally {
				this.saving = false
			}
		},

		/** @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting */
		openMeeting() {
			this.$router.push({
				name: 'MeetingDetail',
				params: { id: this.meetingId },
			})
		},

		/** @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting */
		cancel() {
			this.$router.push({ name: 'Meetings' })
		},
	},
}
</script>

<style scoped>
.adhoc-meeting {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px 24px;
	max-width: 1200px;
}

.adhoc-meeting__title {
	margin: 0;
	font-size: 28px;
	font-weight: 700;
}

.adhoc-meeting__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.adhoc-meeting__crumbs {
	display: flex;
	gap: 8px;
}

.adhoc-meeting__badge {
	padding: 3px 10px;
	border-radius: 12px;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-size: 13px;
	font-weight: 600;
}

.adhoc-meeting__card {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 22px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.adhoc-meeting__fields {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
	gap: 16px;
	align-items: end;
}

.adhoc-meeting__when {
	display: flex;
	gap: 8px;
}

.adhoc-meeting__label {
	font-weight: 600;
}

.adhoc-meeting__optional,
.adhoc-meeting__hint {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

.adhoc-meeting__section {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.adhoc-meeting__section-head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
}

.adhoc-meeting__section-head h2 {
	margin: 0;
	font-size: 17px;
	font-weight: 700;
}

.adhoc-meeting__actions,
.adhoc-meeting__footer {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.adhoc-meeting__footer {
	justify-content: flex-end;
}

.adhoc-meeting__agenda {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.adhoc-meeting__point {
	display: flex;
	gap: 10px;
	align-items: center;
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.adhoc-meeting__number {
	width: 24px;
	text-align: center;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
}

.adhoc-meeting__point-title {
	flex: 1 1 auto;
}

.adhoc-meeting__add-point {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.adhoc-meeting__drop {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	align-items: center;
	gap: 6px;
	padding: 18px;
	border: 2px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
}

.adhoc-meeting__drop--over {
	border-color: var(--color-primary-element);
}

.adhoc-meeting__table-wrap {
	overflow-x: auto;
}

.adhoc-meeting__table {
	width: 100%;
	border-collapse: collapse;
}

.adhoc-meeting__table th,
.adhoc-meeting__table td {
	padding: 8px 12px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.adhoc-meeting__table th {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.adhoc-meeting__empty {
	color: var(--color-text-maxcontrast);
}

.adhoc-meeting__error {
	margin: 0;
	color: var(--color-error-text);
}

.adhoc-meeting__failures {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius-large);
}
</style>
