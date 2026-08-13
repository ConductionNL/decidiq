<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: signers / approvers on a Minutes record.

 Posture: add-existing / remove + "Sign now" CTA. The minutes object
 carries a `signers[]` array of participant references with a
 `signedAt` timestamp. Tab renders that list (with names hydrated
 from the participant store), supports linking new signers,
 removing them, and a "Sign now" button that calls the lifecycle
 transition endpoint when the current user matches a pending signer.
-->
<template>
	<div
		class="decidesk-tab decidesk-tab--signers"
		data-testid="minutes-signers-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Signers') }}
				<span v-if="!loading" class="decidesk-tab__count"
					>({{ signersWithName.length }})</span
				>
			</h3>
			<NcButton
				variant="primary"
				data-testid="minutes-signers-add"
				:aria-label="t('decidesk', 'Add signer')"
				@click="addDialogOpen = true">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'Add signer') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load signers')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="signersWithName"
			:loading="loading"
			row-key="id"
			:empty-text="t('decidesk', 'No signers added yet.')"
			:loading-text="t('decidesk', 'Loading signers…')">
			<template #column-signedAt="{ value }">
				<CnStatusBadge
					v-if="value"
					:label="t('decidesk', 'Signed')"
					:color-map="{ Signed: 'success' }" />
				<span v-else class="decidesk-tab__pending">
					{{ t('decidesk', 'Pending') }}
				</span>
			</template>
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="rowActionsFor(row)" />
			</template>
		</CnDataTable>

		<div v-if="canSignNow" class="decidesk-tab__cta">
			<NcButton variant="primary" @click="signNow">
				{{ t('decidesk', 'Sign now') }}
			</NcButton>
			<p v-if="signError" class="decidesk-tab__error" role="alert">
				{{ signError }}
			</p>
		</div>

		<MinutesSignerAddDialog
			v-if="addDialogOpen"
			:candidates="candidates"
			:loading="loadingCandidates"
			@select="addSigner"
			@close="addDialogOpen = false" />

		<CnDeleteDialog
			v-if="removeTarget"
			ref="removeDialog"
			:item="removeTarget"
			name-field="displayName"
			:dialog-title="t('decidesk', 'Remove signer')"
			@confirm="confirmRemove"
			@close="removeTarget = null" />
	</div>
</template>

<script>
import {
	CnDataTable,
	CnDeleteDialog,
	CnNoteCard,
	CnRowActions,
	CnStatusBadge,
} from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import Plus from 'vue-material-design-icons/Plus.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import MinutesSignerAddDialog from '../../dialogs/MinutesSignerAddDialog.vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MinutesSignersTab',
	components: {
		CnDataTable,
		CnDeleteDialog,
		CnNoteCard,
		CnRowActions,
		CnStatusBadge,
		MinutesSignerAddDialog,
		NcButton,
		Plus,
	},
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			error: '',
			minutes: null,
			participantsById: {},
			addDialogOpen: false,
			loadingCandidates: false,
			candidates: [],
			removeTarget: null,
			signError: '',
		}
	},
	computed: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{ key: 'displayName', label: this.t('decidesk', 'Name') },
				{ key: 'role', label: this.t('decidesk', 'Role') },
				{ key: 'signedAt', label: this.t('decidesk', 'Status') },
			]
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		rawSigners() {
			return Array.isArray(this.minutes?.signers) ? this.minutes.signers : []
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		signersWithName() {
			return this.rawSigners.map((entry) => {
				const participantId =
					typeof entry === 'object'
						? entry.participant || entry.id || entry.uuid
						: entry
				const p = this.participantsById[participantId] || {}
				const signedAt = typeof entry === 'object' ? entry.signedAt : null
				return {
					id: participantId,
					participantId,
					displayName: p.displayName || p.name || participantId,
					role: p.role || '',
					signedAt: signedAt || null,
				}
			})
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		canSignNow() {
			const user = getCurrentUser()
			if (!user) return false
			return this.signersWithName.some(
				(s) =>
					!s.signedAt
					&& (s.participantId === user.uid
						|| this.participantsById[s.participantId]?.owner
							=== user.uid),
			)
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/relation-tab-ui/spec.md */
			handler() {
				this.refresh()
			},
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		addDialogOpen(open) {
			if (open) this.loadCandidates()
		},
	},
	methods: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		rowActionsFor(row) {
			return [
				{
					label: this.t('decidesk', 'Remove signer'),
					icon: LinkOff,
					destructive: true,
					handler: () => {
						this.removeTarget = { ...row }
					},
				},
			]
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const minutesStore = ensureRelationType('minutes')
				this.minutes = await minutesStore.fetchObject(
					'minutes',
					this.objectId,
				)

				const participantStore = ensureRelationType('participant')
				const ids = this.rawSigners
					.map((e) =>
						typeof e === 'object' ? e.participant || e.id || e.uuid : e,
					)
					.filter(Boolean)
				if (ids.length) {
					// Fetch a page wide enough to cover the signer ids and index
					// by id. Server-side `id IN (...)` filtering varies across
					// OpenRegister versions, so we hydrate via a single call.
					const list = await participantStore.fetchCollection(
						'participant',
						{ _limit: 200 },
					)
					const map = {}
					for (const p of list || []) map[p.id || p.uuid] = p
					this.participantsById = map
				}
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Failed to load signers.')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async loadCandidates() {
			this.loadingCandidates = true
			try {
				const store = ensureRelationType('participant')
				const items = await store.fetchCollection('participant', {
					_limit: 200,
				})
				const taken = new Set(
					this.rawSigners.map((e) =>
						typeof e === 'object' ? e.participant || e.id || e.uuid : e,
					),
				)
				this.candidates = (items || []).filter(
					(p) => !taken.has(p.id || p.uuid),
				)
			} catch {
				this.candidates = []
			} finally {
				this.loadingCandidates = false
			}
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async addSigner(participant) {
			const minutesStore = ensureRelationType('minutes')
			const next = this.rawSigners
				.slice()
				.concat([{ participant: participant.id || participant.uuid }])
			try {
				const updated = await minutesStore.saveObject('minutes', {
					...this.minutes,
					signers: next,
				})
				this.minutes = updated || this.minutes
				this.addDialogOpen = false
				this.refresh()
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Failed to add signer.')
			}
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async confirmRemove() {
			const target = this.removeTarget
			const next = this.rawSigners.filter((e) => {
				const id =
					typeof e === 'object' ? e.participant || e.id || e.uuid : e
				return id !== target.participantId
			})
			const minutesStore = ensureRelationType('minutes')
			try {
				const updated = await minutesStore.saveObject('minutes', {
					...this.minutes,
					signers: next,
				})
				this.minutes = updated || this.minutes
				this.$refs.removeDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.removeDialog?.setResult({
					error: e?.message || this.t('decidesk', 'Remove failed.'),
				})
			}
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async signNow() {
			this.signError = ''
			try {
				const url = generateUrl(
					`/apps/decidesk/api/minutes/${this.objectId}/transition`,
				)
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: window.OC?.requestToken,
					},
					body: JSON.stringify({ lifecycle: 'signed' }),
				})
				if (!response.ok) {
					const data = await response.json().catch(() => ({}))
					this.signError =
						data.message || this.t('decidesk', 'Signing failed.')
					return
				}
				this.refresh()
			} catch (e) {
				this.signError = e?.message || this.t('decidesk', 'Signing failed.')
			}
		},
	},
}
</script>

<style scoped>
.decidesk-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidesk-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidesk-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}

.decidesk-tab__pending {
	color: var(--color-text-maxcontrast);
}

.decidesk-tab__cta {
	margin-top: var(--default-grid-baseline);
}

.decidesk-tab__error {
	color: var(--color-error);
	margin: 4px 0 0;
}
</style>
