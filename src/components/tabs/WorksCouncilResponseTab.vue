<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Works-council consultation widget: the next step on the traject. Once the
 response is adopted the secretary writes the formal advice or consent
 document; once it is sent, records the director's decision. The
 constituency consultation and the related decision open as links: the poll
 lives on the consultation, and the advisory stage is recorded on the
 decision as decision-methods does, never on the traject. The server decides
 who may write the document; anyone else gets its refusal as a message.

 @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn
 @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
-->
<template>
	<div class="decidiq-tab" data-testid="works-council-response-tab">
		<p v-if="!hasAny" class="decidiq-wor__empty">
			{{ t('decidiq', 'Nothing to do on this consultation right now.') }}
		</p>
		<div class="decidiq-wor__actions">
			<NcButton
				v-if="steps.writeDocument"
				variant="secondary"
				data-testid="works-council-write-document"
				:disabled="busy"
				@click="writeDocument">
				{{ t('decidiq', 'Write the response document') }}
			</NcButton>
			<NcButton
				v-if="steps.recordDecision"
				variant="primary"
				data-testid="works-council-record-decision"
				:disabled="busy"
				@click="deciding = true">
				{{ t('decidiq', 'Record the director\'s decision') }}
			</NcButton>
		</div>
		<ul class="decidiq-wor__links">
			<li v-if="steps.constituencyConsultation">
				<router-link :to="{ path: `/governance-consultations/${steps.constituencyConsultation}` }" data-testid="works-council-constituency-link">
					{{ t('decidiq', 'Open the constituency consultation') }}
				</router-link>
			</li>
			<li v-if="steps.relatedDecision">
				<router-link :to="{ path: `/decisions/${steps.relatedDecision}` }" data-testid="works-council-decision-link">
					{{ t('decidiq', 'Open the decision and its advisory stage') }}
				</router-link>
			</li>
		</ul>
		<p v-if="message" class="decidiq-wor__message" role="status">
			{{ message }}
		</p>
		<p v-if="error" class="decidiq-wor__error" role="alert">
			{{ error }}
		</p>
		<RecordDirectorDecisionDialog
			v-if="deciding"
			:type="traject.type"
			:saving="busy"
			@submit="recordDecision"
			@close="deciding = false" />
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import RecordDirectorDecisionDialog from '../../dialogs/RecordDirectorDecisionDialog.vue'
import { decisionPayload, nextSteps, responseDocumentPath } from '../../utils/worksCouncilResponse.js'
import { objectIdLoader } from './objectIdLoader.js'
import { ensureRelationType } from './useRelationStore.js'

const TYPE = 'consultation-request'

export default {
	name: 'WorksCouncilResponseTab',
	components: { NcButton, RecordDirectorDecisionDialog },

	/** @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn */
	mixins: [objectIdLoader],

	data() {
		return {
			traject: null,
			deciding: false,
			busy: false,
			message: '',
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn */
		steps() {
			return nextSteps(this.traject)
		},

		/** @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn */
		hasAny() {
			const s = this.steps
			return s.writeDocument || s.recordDecision || Boolean(s.constituencyConsultation) || Boolean(s.relatedDecision)
		},
	},

	methods: {
		/**
		 * Read the traject.
		 *
		 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn
		 */
		async load() {
			this.traject = null
			if (!this.objectId) return
			try {
				this.traject = await ensureRelationType(TYPE).fetchObject(TYPE, String(this.objectId))
			} catch {
				this.traject = null
			}
		},

		/**
		 * Save the decision with the move to decision-received, then read the traject again.
		 *
		 * @param {{outcome: string, text: string, date: string}} decision The decision
		 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn
		 */
		async recordDecision(decision) {
			this.error = ''
			this.message = ''
			this.busy = true
			try {
				const store = ensureRelationType(TYPE)
				const saved = await store.saveObject(TYPE, { ...decisionPayload(this.traject, decision), id: String(this.objectId) })
				if (!saved) {
					this.error = store.errors?.[TYPE]?.message || this.t('decidiq', 'The decision was not recorded.')
					return
				}
				this.deciding = false
				this.message = this.t('decidiq', 'The decision is recorded.')
				await this.load()
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The decision was not recorded.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Write the response document; the server stores it on the traject and links it.
		 *
		 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-005-formal-response-document-and-decision-linkage
		 */
		async writeDocument() {
			this.error = ''
			this.message = ''
			this.busy = true
			try {
				const response = await fetch(generateUrl(responseDocumentPath(String(this.objectId))), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({ format: 'pdf' }),
				})
				const payload = await response.json().catch(() => ({}))
				if (!response.ok) {
					this.error = payload?.message || this.t('decidiq', 'The response document was not written.')
					return
				}
				this.message = payload.note || this.t('decidiq', 'The response document is stored with this consultation.')
				await this.load()
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The response document was not written.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-wor__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.decidiq-wor__links {
	margin-block: calc(var(--default-grid-baseline, 4px) * 3) 0;
	padding-inline-start: 0;
	list-style: none;
}

.decidiq-wor__links a {
	color: var(--color-primary-element);
}

.decidiq-wor__empty,
.decidiq-wor__message {
	color: var(--color-text-maxcontrast);
}

.decidiq-wor__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
