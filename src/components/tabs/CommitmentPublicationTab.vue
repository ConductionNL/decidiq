<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Commitment widget: where the commitment stands on the public list, with
 Publish / Withdraw, and Record settlement once it is in execution. Each action
 goes through its own dialog and saves the whole commitment; OpenRegister's
 access rules decide who may save, anyone else gets the refusal as a message.

 @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate
-->
<template>
	<div class="decidiq-tab" data-testid="commitment-publication-tab">
		<p data-testid="commitment-publication-state">
			{{ stateLabel }}
		</p>
		<p v-if="commitment && commitment.settlementNotes" data-testid="commitment-settlement-note-shown">
			<strong>{{ t('decidiq', 'Settlement') }}:</strong>
			{{ commitment.settlementNotes }}
		</p>
		<div v-if="commitment" class="decidiq-publication__actions">
			<NcButton
				v-if="state !== 'published'"
				variant="secondary"
				data-testid="commitment-publish"
				:disabled="saving"
				@click="dialog = 'publish'">
				{{ t('decidiq', 'Publish') }}
			</NcButton>
			<NcButton
				v-if="state === 'published' || state === 'scheduled'"
				variant="secondary"
				data-testid="commitment-withdraw"
				:disabled="saving"
				@click="dialog = 'withdraw'">
				{{ t('decidiq', 'Withdraw') }}
			</NcButton>
			<NcButton
				v-if="settleable"
				variant="primary"
				data-testid="commitment-settle"
				:disabled="saving"
				@click="dialog = 'settle'">
				{{ t('decidiq', 'Record settlement') }}
			</NcButton>
		</div>
		<p v-if="error" class="decidiq-publication__error" role="alert">
			{{ error }}
		</p>
		<CommitmentPublicationDialog
			v-if="dialog === 'publish' || dialog === 'withdraw'"
			:withdraw="dialog === 'withdraw'"
			:saving="saving"
			@confirm="save(dialog)"
			@close="dialog = null" />
		<CommitmentSettlementDialog
			v-if="dialog === 'settle'"
			:saving="saving"
			@submit="save('settle', $event)"
			@close="dialog = null" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CommitmentPublicationDialog from '../../dialogs/CommitmentPublicationDialog.vue'
import CommitmentSettlementDialog from '../../dialogs/CommitmentSettlementDialog.vue'
import {
	canSettle,
	publicationState,
	published,
	settled,
	withdrawn,
} from '../../utils/commitmentPublication.js'
import { objectIdLoader } from './objectIdLoader.js'
import { ensureRelationType } from './useRelationStore.js'

const TYPE = 'governance-commitment'

export default {
	name: 'CommitmentPublicationTab',
	components: { CommitmentPublicationDialog, CommitmentSettlementDialog, NcButton },

	/** @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate */
	mixins: [objectIdLoader],

	data() {
		return {
			commitment: null,
			dialog: null,
			saving: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate */
		state() {
			return publicationState(this.commitment)
		},

		/** @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate */
		stateLabel() {
			switch (this.state) {
			case 'published':
				return this.t('decidiq', 'On the public list.')
			case 'scheduled':
				return this.t('decidiq', 'Scheduled for the public list.')
			case 'withdrawn':
				return this.t('decidiq', 'Withdrawn from the public list.')
			default:
				return this.t('decidiq', 'Not on the public list.')
			}
		},

		/** @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-002-lifecycle-is-declarative-with-four-states */
		settleable() {
			return canSettle(this.commitment)
		},
	},

	methods: {
		/**
		 * Read the commitment.
		 *
		 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate
		 */
		async load() {
			this.commitment = null
			if (!this.objectId) return
			try {
				this.commitment = await ensureRelationType(TYPE).fetchObject(
					TYPE,
					String(this.objectId),
				)
			} catch {
				this.commitment = null
			}
		},

		/**
		 * Save the whole commitment after a confirmed action.
		 *
		 * @param {'publish'|'withdraw'|'settle'} action The confirmed action
		 * @param {{note: string, evidence: string}} [settlement] The settlement, for settle
		 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate
		 */
		async save(action, settlement) {
			if (!this.commitment) return
			this.error = ''
			this.saving = true
			try {
				let next
				if (action === 'publish') next = published(this.commitment)
				else if (action === 'withdraw') next = withdrawn(this.commitment)
				else next = settled(this.commitment, settlement)
				const store = ensureRelationType(TYPE)
				const saved = await store.saveObject(TYPE, next)
				if (!saved) {
					this.error = store.errors?.[TYPE]?.message
						|| this.t('decidiq', 'The commitment was not saved.')
					return
				}
				this.commitment = saved
				this.dialog = null
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The commitment was not saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-publication__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.decidiq-publication__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
