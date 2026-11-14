/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The word under each tile of the amendment, decision and meeting step bars.
 *
 * @spec openspec/specs/decision-management/spec.md#requirement-req-sdp-003-a-step-bar-shows-where-the-decision-stands
 */

export const stepBarWordsMixin = {
	methods: {
		/**
		 * Whether a step is behind, current or ahead, in a word. Colour alone
		 * must not carry it.
		 *
		 * @param {string} status `done`, `current` or `upcoming`.
		 * @return {string} The translated word.
		 * @spec openspec/specs/decision-management/spec.md#requirement-req-sdp-003-a-step-bar-shows-where-the-decision-stands
		 */
		stateWord(status) {
			if (status === 'done') {
				return this.t('decidiq', 'Done')
			}
			return status === 'current'
				? this.t('decidiq', 'Now')
				: this.t('decidiq', 'Next')
		},
	},
}
