<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Signers of a record that is signed through the external signing service:
 minutes (the default), a meeting's decision list (DecisionListSignersTab)
 or a motion (MotionSignersTab).

 The record carries a `signers[]` array of participant references, each
 with an `order` (1 signs first) and a `signedAt` timestamp. The tab
 renders that list in signing order (names hydrated from the participant
 store), adds, removes and moves signers, and sends the record for
 signature (min-17). Once the signing service reports it signed, the
 signed copy is stored in the record's files and named here. On minutes
 a "Sign now" button also calls the lifecycle transition when the current
 user is a pending signer.
-->
<template>
	<div class="decidiq-tab decidiq-tab--signers" data-testid="minutes-signers-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Signers') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ signersWithName.length }})</span
				>
			</h3>
			<NcButton
				variant="primary"
				data-testid="minutes-signers-add"
				:aria-label="t('decidiq', 'Add signer')"
				@click="addDialogOpen = true">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidiq', 'Add signer') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load signers')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="signersWithName"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No signers added yet.')"
			:loadingText="t('decidiq', 'Loading signers…')">
			<template #column-signedAt="{ value }">
				<CnStatusBadge
					v-if="value"
					:label="t('decidiq', 'Signed')"
					:colorMap="{ Signed: 'success' }" />
				<span v-else class="decidiq-tab__pending">
					{{ t('decidiq', 'Pending') }}
				</span>
			</template>
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="rowActionsFor(row)" />
			</template>
		</CnDataTable>

		<div class="decidiq-tab__cta">
			<NcButton
				v-if="canSend"
				variant="primary"
				data-testid="signers-send"
				:disabled="sending"
				@click="sendForSignature">
				{{ t('decidiq', 'Send for signature') }}
			</NcButton>
			<NcButton
				v-if="signingStatus === 'sent'"
				data-testid="signers-collect"
				:disabled="sending"
				@click="collectSignedCopy">
				{{ t('decidiq', 'Check signing status') }}
			</NcButton>
			<NcButton v-if="canSignNow" variant="primary" @click="signNow">
				{{ t('decidiq', 'Sign now') }}
			</NcButton>
			<p
				v-if="signingStatusText"
				class="decidiq-tab__status"
				data-testid="signers-status">
				{{ signingStatusText }}
			</p>
			<p v-if="signError" class="decidiq-tab__error" role="alert">
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
			nameField="displayName"
			:dialogTitle="t('decidiq', 'Remove signer')"
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
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import MinutesSignerAddDialog from '../../dialogs/MinutesSignerAddDialog.vue'
import {
	addSigner,
	moveSigner,
	orderedSigners,
	signingUrl,
} from '../../utils/signingRound.js'
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
		/** The schema that holds the record: minutes, meeting or decision. */
		schema: { type: String, default: 'minutes' },
		/** What is signed: minutes, decision-list or motion. */
		subjectType: { type: String, default: 'minutes' },
	},

	data() {
		return {
			loading: false,
			error: '',
			record: null,
			sending: false,
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
				{ key: 'order', label: this.t('decidiq', 'Order') },
				{ key: 'displayName', label: this.t('decidiq', 'Name') },
				{ key: 'role', label: this.t('decidiq', 'Role') },
				{ key: 'signedAt', label: this.t('decidiq', 'Status') },
			]
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		rawSigners() {
			return orderedSigners(this.record?.signers)
		},

		/** @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy */
		signingStatus() {
			return this.record?.signingStatus || ''
		},

		/** @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy */
		canSend() {
			return (
				this.rawSigners.length > 0
				&& this.signingStatus !== 'sent'
				&& this.signingStatus !== 'signed'
			)
		},

		/** @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy */
		signingStatusText() {
			if (this.signingStatus === 'sent') {
				return this.t(
					'decidiq',
					'Out for signature. The signed copy is stored here once everyone has signed.',
				)
			}
			if (this.signingStatus === 'signed') {
				return this.t(
					'decidiq',
					'Signed. The signed copy {file} is stored in the files of this record.',
					{
						file: this.record?.signedCopy || '',
					},
				)
			}
			if (this.signingStatus === 'failed') {
				return this.t(
					'decidiq',
					'The signing round did not finish. You can send it again.',
				)
			}
			return ''
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		signersWithName() {
			return this.rawSigners.map((entry) => {
				const participantId = entry.participant
				const p = this.participantsById[participantId] || {}
				const signedAt = entry.signedAt
				return {
					id: participantId,
					participantId,
					order: entry.order,
					displayName: p.displayName || p.name || participantId,
					role: p.role || '',
					signedAt: signedAt || null,
				}
			})
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		canSignNow() {
			const user = getCurrentUser()
			if (!user || this.subjectType !== 'minutes') return false
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

		/**
		 * @param open
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		addDialogOpen(open) {
			if (open) this.loadCandidates()
		},
	},

	methods: {
		/**
		 * @param row
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		rowActionsFor(row) {
			return [
				{
					label: this.t('decidiq', 'Move up'),
					icon: ArrowUp,
					disabled: row.order === 1,
					handler: () =>
						this.saveSigners(
							moveSigner(this.rawSigners, row.participantId, -1),
						),
				},
				{
					label: this.t('decidiq', 'Move down'),
					icon: ArrowDown,
					disabled: row.order === this.rawSigners.length,
					handler: () =>
						this.saveSigners(
							moveSigner(this.rawSigners, row.participantId, 1),
						),
				},
				{
					label: this.t('decidiq', 'Remove signer'),
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
				const store = ensureRelationType(this.schema)
				this.record = await store.fetchObject(this.schema, this.objectId)

				const participantStore = ensureRelationType('participant')
				const ids = this.rawSigners.map((e) => e.participant)
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
					e?.message || this.t('decidiq', 'Failed to load signers.')
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
				const taken = new Set(this.rawSigners.map((e) => e.participant))
				this.candidates = (items || []).filter(
					(p) => !taken.has(p.id || p.uuid),
				)
			} catch {
				this.candidates = []
			} finally {
				this.loadingCandidates = false
			}
		},

		/**
		 * @param participant
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async addSigner(participant) {
			try {
				await this.saveSigners(
					addSigner(this.rawSigners, participant.id || participant.uuid),
					true,
				)
				this.addDialogOpen = false
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'Failed to add signer.')
			}
		},

		/**
		 * Write the signer list, in order, back onto the record.
		 *
		 * @param {Array<object>} signers The signers in signing order
		 * @param {boolean} rethrow Throw instead of showing the error
		 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
		 */
		async saveSigners(signers, rethrow = false) {
			const store = ensureRelationType(this.schema)
			try {
				const updated = await store.saveObject(this.schema, {
					...this.record,
					signers,
				})
				this.record = updated || this.record
				this.refresh()
			} catch (e) {
				if (rethrow) throw e
				this.error =
					e?.message || this.t('decidiq', 'Failed to save the signers.')
			}
		},

		/**
		 * Send the record to the signing service with its signers in order.
		 *
		 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
		 */
		async sendForSignature() {
			await this.postSigning('send')
		},

		/**
		 * Ask the signing service where the round stands; once signed the
		 * signed copy is stored on the record.
		 *
		 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
		 */
		async collectSignedCopy() {
			await this.postSigning('collect')
		},

		/**
		 * @param {'send'|'collect'} action The signing action
		 * @spec openspec/specs/p2-minutes-and-decisions-core-t3/spec.md#requirement-req-ses-001-send-for-signature-in-a-chosen-order-and-store-the-signed-copy
		 */
		async postSigning(action) {
			this.signError = ''
			this.sending = true
			try {
				const response = await fetch(
					generateUrl(signingUrl(this.subjectType, this.objectId, action)),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: window.OC?.requestToken,
						},
					},
				)
				if (!response.ok) {
					const data = await response.json().catch(() => ({}))
					this.signError =
						data.message
						|| this.t('decidiq', 'Could not send it for signature.')
					return
				}
				this.refresh()
			} catch (e) {
				this.signError =
					e?.message
					|| this.t('decidiq', 'Could not send it for signature.')
			} finally {
				this.sending = false
			}
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async confirmRemove() {
			const target = this.removeTarget
			const next = orderedSigners(
				this.rawSigners.filter(
					(e) => e.participant !== target.participantId,
				),
			)
			const store = ensureRelationType(this.schema)
			try {
				const updated = await store.saveObject(this.schema, {
					...this.record,
					signers: next,
				})
				this.record = updated || this.record
				this.$refs.removeDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.removeDialog?.setResult({
					error: e?.message || this.t('decidiq', 'Remove failed.'),
				})
			}
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async signNow() {
			this.signError = ''
			try {
				const url = generateUrl(
					`/apps/decidiq/api/minutes/${this.objectId}/transition`,
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
						data.message || this.t('decidiq', 'Signing failed.')
					return
				}
				this.refresh()
			} catch (e) {
				this.signError = e?.message || this.t('decidiq', 'Signing failed.')
			}
		},
	},
}
</script>

<style scoped>
.decidiq-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidiq-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidiq-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}

.decidiq-tab__pending {
	color: var(--color-text-maxcontrast);
}

.decidiq-tab__cta {
	margin-top: var(--default-grid-baseline);
}

.decidiq-tab__cta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__status {
	flex-basis: 100%;
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.decidiq-tab__error {
	color: var(--color-error);
	margin: 4px 0 0;
}
</style>
