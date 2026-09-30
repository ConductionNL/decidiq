<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Papers: each Office paper of a meeting or an agenda item once, as the PDF
 decidiq made of it (agenda-office-files-to-pdf, matrix row age-17). The chair
 and the secretariat also get the original and can try a failed conversion
 again. The entries are built by src/utils/paperRenditions.js.

 @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf
-->
<template>
	<div class="paper-renditions" data-testid="paper-renditions">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="paper-renditions__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="entries.length === 0" class="paper-renditions__empty">
			{{ t('decidiq', 'No Office papers on this page.') }}
		</p>
		<ul v-else class="paper-renditions__list">
			<li
				v-for="entry in entries"
				:key="entry.key"
				class="paper-renditions__row"
				data-testid="paper-renditions-row">
				<div class="paper-renditions__text">
					<a
						v-if="entry.pdfUrl"
						:href="entry.pdfUrl"
						class="paper-renditions__name"
						data-testid="paper-renditions-pdf">
						{{ entry.title }}
					</a>
					<a
						v-else
						:href="entry.originalUrl"
						class="paper-renditions__name">
						{{ entry.title }}
					</a>
					<span
						v-if="entry.failure"
						class="paper-renditions__failure"
						data-testid="paper-renditions-failure">
						{{
							t('decidiq', 'Not converted: {reason}', {
								reason: reasonText(entry.failure),
							})
						}}
					</span>
					<a
						v-if="entry.pdfUrl && entry.originalUrl"
						:href="entry.originalUrl"
						class="paper-renditions__original"
						data-testid="paper-renditions-original">
						{{ originalLabel(entry.originalKind) }}
					</a>
				</div>
				<NcButton
					v-if="entry.canRetry"
					variant="secondary"
					:disabled="retrying === entry.key"
					:aria-label="
						t('decidiq', 'Try the conversion of {name} again', {
							name: entry.title,
						})
					"
					data-testid="paper-renditions-retry"
					@click="retry(entry)">
					{{ t('decidiq', 'Try again') }}
				</NcButton>
			</li>
		</ul>
		<p v-if="notice" class="paper-renditions__notice" role="status">
			{{ notice }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { canManageAgenda } from '../../services/agendaRules.js'
import { paperEntries, retryUrl } from '../../utils/paperRenditions.js'

export default {
	name: 'AgendaPaperRenditionsTab',

	components: { NcButton, NcLoadingIcon },

	props: {
		objectId: { type: [String, Number], default: '' },
		targetType: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			notice: '',
			object: null,
			roles: null,
			retrying: null,
		}
	},

	computed: {
		schema() {
			if (this.targetType) return this.targetType
			return String(this.$route?.name || '').startsWith('AgendaItem')
				? 'agenda-item'
				: 'meeting'
		},

		entries() {
			return paperEntries(
				this.object?.paperRenditions,
				canManageAgenda(this.roles),
			)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/** @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/decidiq/${this.schema}/${this.objectId}`,
					),
				)
				this.object = data || null
				const meetingId =
					this.schema === 'meeting' ? this.objectId : data?.meeting
				this.roles = meetingId ? await this.fetchRoles(meetingId) : null
			} catch {
				this.error = this.t(
					'decidiq',
					'The papers of this page could not be read.',
				)
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf */
		async fetchRoles(meetingId) {
			try {
				const { data } = await axios.get(
					generateUrl(`/apps/decidiq/api/meetings/${meetingId}/my-roles`),
				)
				return data || null
			} catch {
				return null
			}
		},

		/** @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays */
		async retry(entry) {
			this.retrying = entry.key
			this.notice = ''
			try {
				await axios.post(retryUrl(this.schema, this.objectId, entry.key))
				this.notice = this.t(
					'decidiq',
					'The conversion is queued. Reload the page in a few minutes.',
				)
			} catch {
				this.notice = this.t(
					'decidiq',
					'The conversion could not be queued.',
				)
			} finally {
				this.retrying = null
			}
		},

		/** @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf */
		originalLabel(kind) {
			if (!kind) return this.t('decidiq', 'Original')
			return this.t('decidiq', 'Original ({kind})', { kind })
		},

		/** @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-002-a-failed-or-impossible-conversion-is-visible-and-the-original-stays */
		reasonText(failure) {
			if (failure === 'No backend could convert this file') {
				return this.t('decidiq', 'no backend could convert this file')
			}
			return this.t('decidiq', 'the conversion stopped before a PDF was made')
		},
	},
}
</script>

<style scoped>
.paper-renditions__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.paper-renditions__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.paper-renditions__text {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.paper-renditions__failure,
.paper-renditions__original {
	color: var(--color-text-maxcontrast);
}
</style>
