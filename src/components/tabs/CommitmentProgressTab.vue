<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Commitment widget: the dated progress entries, newest first, and a field
 where the chair or secretary of the meeting adds one (fol-06). The server
 decides who may add; anyone else gets its refusal as a message.

 @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
-->
<template>
	<div class="decidiq-tab" data-testid="commitment-progress-tab">
		<p v-if="entries.length === 0" class="decidiq-progress__empty">
			{{ t('decidiq', 'No progress has been recorded yet.') }}
		</p>
		<ol v-else class="decidiq-progress__list">
			<li
				v-for="(entry, index) in entries"
				:key="`${entry.date}-${index}`"
				data-testid="commitment-progress-entry">
				<time :datetime="entry.date">{{ entry.date }}</time>
				<span>{{ entry.note }}</span>
			</li>
		</ol>
		<form class="decidiq-progress__form" @submit.prevent="add">
			<NcTextArea
				v-model="note"
				data-testid="commitment-progress-note"
				:label="t('decidiq', 'What happened')"
				:disabled="busy" />
			<NcButton
				type="submit"
				variant="secondary"
				data-testid="commitment-progress-add"
				:disabled="busy || !canSend">
				{{ t('decidiq', 'Add progress') }}
			</NcButton>
		</form>
		<p v-if="error" class="decidiq-progress__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextArea } from '@nextcloud/vue'
import {
	newestFirst,
	noteIsValid,
	progressPath,
} from '../../utils/commitmentProgress.js'
import { ensureRelationType } from './useRelationStore.js'

const TYPE = 'governance-commitment'

export default {
	name: 'CommitmentProgressTab',
	components: { NcButton, NcTextArea },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			entries: [],
			note: '',
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry */
		canSend() {
			return noteIsValid(this.note)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Read the commitment and show its entries.
		 *
		 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
		 */
		async load() {
			this.entries = []
			if (!this.objectId) return
			try {
				const commitment = await ensureRelationType(TYPE).fetchObject(
					TYPE,
					String(this.objectId),
				)
				this.entries = newestFirst(commitment?.progress)
			} catch {
				this.entries = []
			}
		},

		/**
		 * Send the note; the server dates it today and answers with every entry.
		 *
		 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
		 */
		async add() {
			if (!this.canSend) return
			this.error = ''
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(progressPath(String(this.objectId))),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ note: this.note.trim() }),
					},
				)
				const payload = await response.json().catch(() => ({}))
				if (!response.ok) {
					this.error =
						payload?.message
						|| this.t('decidiq', 'The progress entry was not added.')
					return
				}
				this.entries = newestFirst(payload.progress)
				this.note = ''
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'The progress entry was not added.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-progress__list {
	margin: 0;
	padding-inline-start: 0;
	list-style: none;
}

.decidiq-progress__list li {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	padding-block: var(--default-grid-baseline, 4px);
}

.decidiq-progress__list time {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.decidiq-progress__form {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-block-start: calc(var(--default-grid-baseline, 4px) * 3);
}

.decidiq-progress__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
