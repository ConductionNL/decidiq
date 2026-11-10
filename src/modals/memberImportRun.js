// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The import step the CSV and the group member-import dialogs share: each
// previewed person becomes a Person (matched by email, else created) plus a
// Membership of the body, one after the other. A dialog says which rows it
// imports (`importablePeople`) and how its preview reads afterwards
// (`previewAfterImport`). @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md

import {
	buildMembershipPayload,
	ensureRelationType,
	resolveOrCreatePerson,
} from '../components/tabs/useRelationStore.js'

export const memberImportRunMixin = {
	methods: {
		/**
		 * Import the people the dialog previews into the body, then say how
		 * many and mark them so a second click cannot double-import.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
		 */
		async runImport() {
			this.importing = true
			this.error = ''
			try {
				const personStore = ensureRelationType('person')
				const membershipStore = ensureRelationType('membership')
				const people = this.importablePeople()
				for (const person of people) {
					// Sequential on purpose: predictable ordering + no API hammering.
					await this.importPerson(person, { personStore, membershipStore })
				}
				this.doneMessage = this.t('decidiq', '{count} members imported.', {
					count: people.length,
				})
				this.$emit('imported')
				this.preview = this.previewAfterImport()
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'Import failed.')
			} finally {
				this.importing = false
			}
		},

		/**
		 * Resolve or create one person and give them a seat on the body; a
		 * person the store cannot resolve is skipped.
		 *
		 * @param {{name: string, email: string, nextcloudUserId: string, role: string}} person The person.
		 * @param {{personStore: object, membershipStore: object}} stores The person and membership stores.
		 * @return {Promise<void>}
		 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
		 */
		async importPerson(person, stores) {
			const stored = await resolveOrCreatePerson(stores.personStore, {
				name: person.name,
				email: person.email,
				nextcloudUserId: person.nextcloudUserId,
			})
			if (!stored?.id) {
				return
			}
			await stores.membershipStore.saveObject(
				'membership',
				buildMembershipPayload({
					personId: stored.id,
					governanceBodyId: this.bodyId,
					role: person.role,
				}),
			)
		},
	},
}
