/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The create-proposal handler shared by the decisions tab and the decisions
 * widget on a host object.
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md
 */
import { translate as t } from '@nextcloud/l10n'
import { createHostDecision, hostProposalPayload } from './decisionLink.js'

export const hostProposalMixin = {
	methods: {
		/**
		 * Create a decision pre-linked to the host, then reload the list.
		 *
		 * @param {object} formData The proposal form values.
		 * @return {Promise<void>}
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md
		 */
		async onCreate(formData) {
			if (!this.hostObjectId || this.creating) {
				return
			}
			this.creating = true
			this.error = ''
			try {
				await createHostDecision(
					hostProposalPayload(
						formData,
						{
							register: this.hostRegister,
							schema: this.hostSchema,
							objectId: this.hostObjectId,
							label: this.objectLabel,
						},
						t('decidiq', 'Proposal'),
					),
				)
				this.createOpen = false
				await this.refresh()
			} catch (e) {
				this.error = e?.message || t('decidiq', 'Could not create proposal.')
			} finally {
				this.creating = false
			}
		},
	},
}
