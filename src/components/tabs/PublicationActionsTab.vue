<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: public-publication actions on a decision, meeting (agenda), or
 set of minutes (publish-decisions-via-opencatalogi).

 Shows the current publication status and exposes publish / withdraw / rectify
 actions to authorized staff — the publish action is only rendered when the
 object meets the server-side eligibility gates (the server stays
 authoritative; this is UI gating only). Anonymous read of published data is
 NOT served by this app — it happens exclusively through OR's published-
 predicate / OpenCatalogi surface.

 @spec openspec/specs/public-publication/spec.md
-->
<template>
	<div class="decidesk-tab decidesk-tab--publication" data-testid="publication-actions-tab">
		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Publication error')">
			{{ error }}
		</CnNoteCard>

		<CnNoteCard
			v-for="warning in warnings"
			:key="warning"
			type="warning"
			:title="t('decidesk', 'Publication warning')"
			data-testid="publication-warning">
			{{ warningLabel(warning) }}
		</CnNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Public publication') }}
			</h3>

			<p v-if="activeRecord" class="decidesk-tab__meta" data-testid="publication-status">
				{{ t('decidesk', 'Published as {oriType} (version {version}) on {date}', {
					oriType: activeRecord.oriType,
					version: activeRecord.payloadVersion,
					date: activeRecord.publishedAt,
				}) }}
			</p>
			<p v-else class="decidesk-tab__meta" data-testid="publication-status">
				{{ t('decidesk', 'Not published.') }}
			</p>

			<div class="decidesk-tab__actions" data-testid="publication-actions">
				<NcButton
					v-if="!activeRecord && eligible"
					variant="primary"
					data-testid="publication-publish"
					:disabled="working"
					@click="publish">
					{{ t('decidesk', 'Publish') }}
				</NcButton>
				<NcButton
					v-if="activeRecord"
					variant="error"
					data-testid="publication-withdraw"
					:disabled="working"
					@click="withdrawModalOpen = true">
					{{ t('decidesk', 'Withdraw…') }}
				</NcButton>
				<NcButton
					v-if="activeRecord"
					data-testid="publication-rectify"
					:disabled="working"
					@click="rectifyModalOpen = true">
					{{ t('decidesk', 'Rectify…') }}
				</NcButton>
			</div>

			<div v-if="history.length" class="decidesk-tab__history" data-testid="publication-history">
				<h3 class="decidesk-tab__title">
					{{ t('decidesk', 'Publication history') }}
				</h3>
				<ul class="decidesk-tab__list" role="list">
					<li v-for="record in history"
						:key="record.id"
						class="decidesk-tab__history-row"
						role="listitem">
						<span>{{ t('decidesk', 'v{version}', { version: record.payloadVersion }) }}</span>
						<span>{{ statusLabel(record.status) }}</span>
						<span class="decidesk-tab__meta">{{ record.withdrawReason || '' }}</span>
					</li>
				</ul>
			</div>
		</template>

		<PublicationWithdrawModal
			v-if="withdrawModalOpen"
			@confirm="confirmWithdraw"
			@close="withdrawModalOpen = false" />
		<PublicationRectifyModal
			v-if="rectifyModalOpen"
			@confirm="confirmRectify"
			@close="rectifyModalOpen = false" />
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import PublicationWithdrawModal from '../../modals/PublicationWithdrawModal.vue'
import PublicationRectifyModal from '../../modals/PublicationRectifyModal.vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'PublicationActionsTab',
	components: {
		CnNoteCard,
		NcButton,
		NcLoadingIcon,
		PublicationWithdrawModal,
		PublicationRectifyModal,
	},
	props: {
		objectId: { type: [String, Number], default: '' },
		// The publication source type — set by the per-schema wrapper tab.
		sourceType: { type: String, default: 'decision' },
	},
	data() {
		return {
			loading: false,
			working: false,
			error: '',
			warnings: [],
			source: null,
			records: [],
			withdrawModalOpen: false,
			rectifyModalOpen: false,
		}
	},
	computed: {
		/** @spec openspec/specs/public-publication/spec.md */
		records_sorted() {
			return [...this.records].sort((a, b) => (b.payloadVersion || 0) - (a.payloadVersion || 0))
		},
		/** @spec openspec/specs/public-publication/spec.md */
		activeRecord() {
			return this.records_sorted.find(r => r.status === 'published') || null
		},
		/** @spec openspec/specs/public-publication/spec.md */
		history() {
			return this.records_sorted
		},
		/**
		 * Client-side eligibility mirror of the server gates — controls whether
		 * the Publish action is offered. The server remains authoritative.
		 *
		 * @return {boolean} Whether the object appears publishable.
		 * @spec openspec/specs/public-publication/spec.md
		 */
		eligible() {
			if (!this.source) return false
			if (this.sourceType === 'decision') {
				return ['decided', 'enacted'].includes(this.source.lifecycle)
			}
			if (this.sourceType === 'agenda') {
				return this.source.isPublic === true && !!(this.source.convocationSentAt || this.source.convocationSent)
			}
			if (this.sourceType === 'minutes') {
				return ['approved', 'signed', 'published'].includes(this.source.lifecycle)
			}
			return false
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/public-publication/spec.md */
			handler() { this.refresh() },
		},
	},
	methods: {
		/**
		 * Translated status label.
		 *
		 * @param {string} status Record status.
		 * @return {string} The translated label.
		 * @spec openspec/specs/public-publication/spec.md
		 */
		statusLabel(status) {
			const labels = {
				published: this.t('decidesk', 'Published'),
				withdrawn: this.t('decidesk', 'Withdrawn'),
				rectified: this.t('decidesk', 'Rectified'),
			}
			return labels[status] || status
		},
		/**
		 * Translated label for a publication warning code.
		 *
		 * @param {string} code Warning code.
		 * @return {string} The translated label.
		 * @spec openspec/specs/public-publication/spec.md
		 */
		warningLabel(code) {
			const labels = {
				'opencatalogi-absent': this.t('decidesk', 'OpenCatalogi is not installed — the record received the public predicate but was not routed to a catalog.'),
				'catalog-publish-failed': this.t('decidesk', 'Publishing to the OpenCatalogi catalog failed.'),
				'catalog-retraction-failed': this.t('decidesk', 'Retraction from the OpenCatalogi catalog failed and is pending retry — the record is no longer publicly readable but the catalog still lists it.'),
				'predicate-unavailable': this.t('decidesk', 'The published predicate could not be set on this OpenRegister version — anonymous read is not yet available.'),
			}
			return labels[code] || code
		},
		/** @spec openspec/specs/public-publication/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const sourceStore = ensureRelationType(this.sourceSchemaType())
				this.source = await sourceStore.fetchObject(this.sourceSchemaType(), this.objectId)

				const recordStore = ensureRelationType('publication-record')
				this.records = (await recordStore.fetchCollection('publication-record', {
					sourceObject: this.objectId,
					_limit: 100,
				})) || []
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load publication state.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Map the publication source type to the OR schema relation type.
		 *
		 * @return {string} The relation type slug.
		 * @spec openspec/specs/public-publication/spec.md
		 */
		sourceSchemaType() {
			return this.sourceType === 'agenda' ? 'meeting' : this.sourceType
		},
		/**
		 * POST helper against the decidesk publication API.
		 *
		 * @param {string} path Path under /apps/decidesk/api.
		 * @param {object} body JSON body.
		 * @return {Promise<object>} Parsed response body.
		 * @spec openspec/specs/public-publication/spec.md
		 */
		async callApi(path, body = {}) {
			const response = await fetch(generateUrl(`/apps/decidesk/api${path}`), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: window.OC?.requestToken,
				},
				body: JSON.stringify(body),
			})
			const data = await response.json().catch(() => ({}))
			if (!response.ok) {
				throw new Error(data.message || this.t('decidesk', 'The action failed.'))
			}
			return data
		},
		/** @spec openspec/specs/public-publication/spec.md */
		async publish() {
			this.working = true
			this.error = ''
			this.warnings = []
			try {
				const result = await this.callApi('/publications', { sourceType: this.sourceType, sourceId: this.objectId })
				this.warnings = result?.warnings || []
				await this.refresh()
			} catch (e) {
				this.error = e.message
			} finally {
				this.working = false
			}
		},
		/**
		 * Withdraw the active publication with a mandatory reason.
		 *
		 * @param {string} reason The withdraw reason.
		 * @spec openspec/specs/public-publication/spec.md
		 */
		async confirmWithdraw(reason) {
			this.withdrawModalOpen = false
			if (!this.activeRecord) return
			this.working = true
			this.error = ''
			this.warnings = []
			try {
				const result = await this.callApi(`/publications/${this.activeRecord.id}/withdraw`, { reason })
				this.warnings = result?.warnings || []
				await this.refresh()
			} catch (e) {
				this.error = e.message
			} finally {
				this.working = false
			}
		},
		/**
		 * Rectify the active publication (publish a corrected version).
		 *
		 * @param {string} reason Optional reason for the correction.
		 * @spec openspec/specs/public-publication/spec.md
		 */
		async confirmRectify(reason) {
			this.rectifyModalOpen = false
			if (!this.activeRecord) return
			this.working = true
			this.error = ''
			this.warnings = []
			try {
				const result = await this.callApi(`/publications/${this.activeRecord.id}/rectify`, { reason })
				this.warnings = result?.warnings || []
				await this.refresh()
			} catch (e) {
				this.error = e.message
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.decidesk-tab {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline);
}
.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}
.decidesk-tab__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}
.decidesk-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}
.decidesk-tab__history-row {
	display: flex;
	gap: var(--default-grid-baseline);
	align-items: center;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}
.decidesk-tab__meta {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
