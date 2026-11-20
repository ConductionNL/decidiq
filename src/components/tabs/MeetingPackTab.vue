<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Vergaderbundel: the meeting pack's versions with their change notes and a
 download per version, the latest skip and delivery reports, the "outdated"
 badge when the agenda moved since the latest pack, and, for the chair or
 secretary only, the action that compiles the next version (with a change
 note from version 2 on). The packs are read from the OpenRegister objects
 API under the meeting's own access (meeting-pack-board-book, task 8).

 @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page
-->
<template>
	<div class="meeting-pack" data-testid="meeting-pack">
		<NcLoadingIcon v-if="loading" :size="24" />
		<template v-else>
			<div class="meeting-pack__head">
				<CnStatusBadge
					v-if="outdated"
					size="small"
					variant="warning"
					data-testid="meeting-pack-outdated"
					:label="t('decidiq', 'Meeting pack outdated')" />
				<span v-if="compiling" class="meeting-pack__state" role="status">
					{{ t('decidiq', 'The meeting pack is being compiled.') }}
				</span>
			</div>

			<form
				v-if="mayCompile"
				class="meeting-pack__compile"
				data-testid="meeting-pack-compile"
				@submit.prevent="compile">
				<NcTextArea
					v-if="noteNeeded"
					v-model="changeNote"
					:label="t('decidiq', 'What changed in this version?')"
					:required="true"
					:maxlength="1000" />
				<NcButton
					variant="primary"
					type="submit"
					:disabled="compiling || working || (noteNeeded && changeNote.trim() === '')">
					{{ versions.length === 0
						? t('decidiq', 'Compile the meeting pack')
						: t('decidiq', 'Compile a new version') }}
				</NcButton>
			</form>

			<p v-if="versions.length === 0" class="meeting-pack__empty">
				{{ t('decidiq', 'No meeting pack has been compiled yet.') }}
			</p>
			<table v-else class="meeting-pack__versions">
				<thead>
					<tr>
						<th scope="col">
							{{ t('decidiq', 'Version') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Compiled') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'By') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Change note') }}
						</th>
						<th scope="col">
							{{ t('decidiq', 'Pages') }}
						</th>
						<th scope="col">
							<span class="hidden-visually">{{ t('decidiq', 'Download') }}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="pack in versions" :key="pack.id || pack.version" data-testid="meeting-pack-version">
						<td>{{ pack.version }}</td>
						<td>{{ formatDate(pack.generatedAt) }}</td>
						<td>{{ pack.generatedBy }}</td>
						<td>{{ pack.changeNote }}</td>
						<td>{{ pack.pageCount }}</td>
						<td>
							<a
								v-if="download(pack)"
								class="button"
								:href="download(pack)"
								:aria-label="t('decidiq', 'Download version {version}', { version: pack.version })">
								{{ t('decidiq', 'Download') }}
							</a>
						</td>
					</tr>
				</tbody>
			</table>

			<NcNoteCard v-if="skipped.length > 0" type="warning" data-testid="meeting-pack-skipped">
				<p>{{ t('decidiq', 'Not included in the latest pack; available separately per agenda item:') }}</p>
				<ul>
					<li v-for="line in skipped" :key="line">
						{{ line }}
					</li>
				</ul>
			</NcNoteCard>
			<NcNoteCard v-if="mayCompile && deliveryFailures.length > 0" type="warning">
				<p>{{ t('decidiq', 'The latest pack did not reach:') }}</p>
				<ul>
					<li v-for="line in deliveryFailures" :key="line">
						{{ line }}
					</li>
				</ul>
			</NcNoteCard>
			<p v-if="message" class="meeting-pack__message" role="status">
				{{ message }}
			</p>
		</template>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextArea } from '@nextcloud/vue'
import {
	canCompile,
	compileUrl,
	downloadUrl,
	isCompiling,
	isOutdated,
	needsChangeNote,
	packsUrl,
	statusUrl,
	versionsOf,
} from '../../utils/meetingPack.js'
import { objectIdLoader } from './objectIdLoader.js'

export default {
	name: 'MeetingPackTab',

	components: { CnStatusBadge, NcButton, NcLoadingIcon, NcNoteCard, NcTextArea },

	/** @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page */
	mixins: [objectIdLoader],

	data() {
		return {
			loading: false,
			working: false,
			versions: [],
			status: null,
			roles: null,
			changeNote: '',
			message: '',
		}
	},

	computed: {
		/** @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page */
		mayCompile() {
			return canCompile(this.roles)
		},

		/** @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-003-meetingpack-version-tracking */
		noteNeeded() {
			return needsChangeNote(this.versions)
		},

		/** @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-004-pack-outdated-indicator */
		outdated() {
			return isOutdated(this.status)
		},

		/** @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page */
		compiling() {
			return isCompiling(this.status)
		},

		/** @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page */
		skipped() {
			return this.status?.skipped || []
		},

		/** @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-006-offline-delivery-into-attendees-own-files */
		deliveryFailures() {
			return this.status?.deliveryFailures || []
		},
	},

	methods: {
		/**
		 * Read the versions, the status and the caller's roles.
		 *
		 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page
		 */
		async load() {
			if (!this.objectId) return
			const id = String(this.objectId)
			this.loading = true
			try {
				const [packs, status, roles] = await Promise.allSettled([
					axios.get(packsUrl(id)),
					axios.get(statusUrl(id)),
					axios.get(generateUrl(`/apps/decidiq/api/meetings/${id}/my-roles`)),
				])
				const rows = packs.status === 'fulfilled' ? (packs.value.data?.results ?? packs.value.data ?? []) : []
				this.versions = versionsOf(rows, id)
				this.status = status.status === 'fulfilled' ? status.value.data : null
				// Fail closed: without an answer no compile action shows.
				this.roles = roles.status === 'fulfilled' ? roles.value.data : null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Ask for the next version.
		 *
		 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-001-one-click-compilation-of-a-complete-meeting-pack
		 */
		async compile() {
			this.working = true
			this.message = ''
			try {
				await axios.post(compileUrl(String(this.objectId)), { changeNote: this.changeNote.trim() })
				this.changeNote = ''
				this.message = this.t('decidiq', 'The meeting pack is being compiled. It appears here when it is ready.')
				this.status = { ...(this.status || {}), state: 'queued' }
			} catch (e) {
				this.message = e.response?.data?.message || this.t('decidiq', 'The meeting pack could not be compiled.')
			} finally {
				this.working = false
			}
		},

		/**
		 * The download link of a version for the signed-in user.
		 *
		 * @param {object} pack The MeetingPack.
		 * @return {string} The URL, or ''.
		 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page
		 */
		download(pack) {
			return downloadUrl(pack?.filePath, getCurrentUser()?.uid || '')
		},

		/**
		 * A compile moment as a reader says it.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string} The local date and time, or the value as given.
		 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? String(value || '') : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.meeting-pack__head,
.meeting-pack__compile {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
	margin-bottom: 8px;
}

.meeting-pack__versions {
	width: 100%;
	border-collapse: collapse;
}

.meeting-pack__versions th,
.meeting-pack__versions td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.meeting-pack__empty,
.meeting-pack__state {
	color: var(--color-text-maxcontrast);
}
</style>
