<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: the publication was held back by the document accessibility check.
 It lists the documents the server named, each with its findings and how to
 fix them. In warn mode the publisher may confirm and publish; in block mode
 a reason is required before Publish anyway is enabled, and the server keeps
 the reason with the publication. The parent sends the publication again
 with the answer on @confirm (modal isolation, ADR-004).

 @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Documents that may not be accessible')"
		data-testid="accessibility-gate-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-accessibility-gate">
				<p>
					{{ intro }}
				</p>
				<ul class="decidiq-accessibility-gate__documents">
					<li
						v-for="document in rows"
						:key="document.fileId"
						data-testid="accessibility-gate-document">
						<div class="decidiq-accessibility-gate__head">
							<strong>{{ document.fileName }}</strong>
							<AccessibilityBadge :status="document.status" :interactive="false" />
						</div>
						<ul v-if="document.findings.length > 0">
							<li v-for="finding in document.findings" :key="finding.code">
								{{ finding.guidance || finding.code }}
								<span v-if="finding.evidence" class="decidiq-accessibility-gate__evidence">
									{{ t('decidiq', 'Seen: {evidence}', { evidence: finding.evidence }) }}
								</span>
							</li>
						</ul>
					</li>
				</ul>
				<NcTextArea
					v-if="mode === 'block'"
					v-model="reason"
					data-testid="accessibility-gate-reason"
					:label="t('decidiq', 'Why publish anyway')"
					:helperText="t('decidiq', 'The reason is kept with the publication.')"
					resize="vertical" />
			</div>
		</template>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="accessibility-gate-confirm"
				:disabled="!canConfirm"
				@click="$emit('confirm', answer)">
				{{ t('decidiq', 'Publish anyway') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'
import AccessibilityBadge from '../components/AccessibilityBadge.vue'
import {
	canPublishPastGate,
	findingRows,
	gateAnswer,
} from '../utils/documentAccessibility.js'

export default {
	name: 'AccessibilityGateDialog',

	components: { AccessibilityBadge, NcButton, NcDialog, NcTextArea },

	props: {
		/** `warn` or `block`, as the server answered. */
		mode: { type: String, default: 'warn' },
		/** The documents the server named: `{fileId, fileName, status, findings}`. */
		documents: { type: Array, default: () => [] },
	},

	emits: ['close', 'confirm'],

	data() {
		return { reason: '' }
	},

	computed: {
		/** What the dialog asks, by mode. */
		intro() {
			if (this.mode === 'block') {
				return this.t('decidiq', 'These documents did not pass the accessibility check. Fix them in the source document and upload them again, or give a reason to publish anyway.')
			}
			return this.t('decidiq', 'These documents did not pass the accessibility check or were not checked. Fix them in the source document, or confirm to publish anyway.')
		},

		/** The documents with their findings in the reader's language. */
		rows() {
			return this.documents.map((document) => ({
				fileId: document.fileId,
				fileName: document.fileName || String(document.fileId),
				status: document.status || 'not-scanned',
				findings: findingRows(document),
			}))
		},

		/** Whether Publish anyway may be pressed. */
		canConfirm() {
			return canPublishPastGate(this.mode, this.reason)
		},

		/** The fields the publication is sent again with. */
		answer() {
			return gateAnswer(this.mode, this.reason)
		},
	},
}
</script>

<style scoped>
.decidiq-accessibility-gate {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.decidiq-accessibility-gate__documents {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.decidiq-accessibility-gate__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.decidiq-accessibility-gate__evidence {
	display: block;
	color: var(--color-text-maxcontrast);
}
</style>
