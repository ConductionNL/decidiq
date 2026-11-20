/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A refused save reaches the user as an error, in every caller. The object
 * store's `saveObject` returns null on a refusal instead of throwing, so a
 * caller that awaits it inside a try/catch reported success and showed
 * nothing (the pattern that broke adding sessions). Each caller below saves
 * through `saveOrThrow`, which turns the refusal into an Error its catch
 * already reports. This repo's vitest cannot mount a `.vue` file, so the
 * component callers are asserted against their sources; the two plain modules
 * are run against a store double that refuses.
 *
 * @spec exclude wiring test of the refused-save sweep, no behaviour of its own
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { saveRelationRow } from '../../src/components/tabs/relationRows.js'
import { memberImportRunMixin } from '../../src/modals/memberImportRun.js'

globalThis.t = (_app, text) => text

let currentStore = null
vi.mock('../../src/components/tabs/useRelationStore.js', () => ({
	ensureRelationType: (type) => currentStore(type),
	buildMembershipPayload: ({ personId, governanceBodyId, role }) => ({ person: personId, governanceBody: governanceBodyId, role }),
	resolveOrCreatePerson: async () => ({ id: 'p1' }),
}))

/**
 * A store whose save is refused with the given message.
 *
 * @param {string} message The refusal the store records.
 * @return {object} The store.
 */
function refusingStore(message) {
	return {
		saveObject: vi.fn(async () => null),
		getError: () => ({ message }),
	}
}

/**
 * The source of a file under the repository root.
 *
 * @param {string} path The path from the root.
 * @return {string} The file's text.
 */
function source(path) {
	return readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8')
}

// Each caller and how many saves it makes through saveOrThrow.
const CALLERS = [
	['src/components/AgendaBuilder.vue', 7],
	['src/components/liveMeeting/AgendaItemTimer.vue', 1],
	['src/components/minutesEditor/MinutesPanel.vue', 2],
	['src/components/tabs/AgendaItemTypeFieldsTab.vue', 1],
	['src/components/tabs/BodyParticipationsTab.vue', 1],
	['src/components/tabs/GovernanceBodyEvaluationsTab.vue', 2],
	['src/components/tabs/GovernanceBodyMembersTab.vue', 1],
	['src/components/tabs/GovernanceBodyTemplateTab.vue', 1],
	['src/components/tabs/GoverningDocumentCitationsTab.vue', 1],
	['src/components/tabs/MeetingAgendaTab.vue', 3],
	['src/components/tabs/MeetingDecisionsTab.vue', 1],
	['src/components/tabs/MeetingMinutesTab.vue', 1],
	['src/components/tabs/MeetingParticipantsTab.vue', 1],
	['src/components/tabs/MeetingTranscriptionTab.vue', 1],
	['src/components/tabs/MinutesActionsTab.vue', 1],
	['src/components/tabs/MinutesSignersTab.vue', 2],
	['src/components/tabs/MotionTextLinesTab.vue', 1],
	['src/components/tabs/PlannedAgendaActionsTab.vue', 1],
	['src/components/tabs/RelatedDecisionsTab.vue', 2],
	['src/components/tabs/relationRows.js', 1],
	['src/dialogs/BodyParticipationDialog.vue', 1],
	['src/modals/ContactDetailsDialog.vue', 1],
	['src/modals/DocumentMetadataModal.vue', 1],
	['src/modals/MemberAddDialog.vue', 1],
	['src/modals/MemberRoleDialog.vue', 1],
	['src/modals/MemberTermDialog.vue', 1],
	['src/modals/memberImportRun.js', 1],
	['src/views/dashboard/widgets/IncomingDocumentsWidget.vue', 1],
	['src/views/plannedAgenda/PlannedAgendaBoard.vue', 1],
]

describe('every caller saves through saveOrThrow', () => {
	it.each(CALLERS)('%s surfaces a refused save', (path, saves) => {
		const text = source(path)
		expect(text).toMatch(/import \{ saveOrThrow \} from '[./]+\/utils\/saveOrThrow\.js'/)
		expect(text.split('saveOrThrow(').length - 1).toBe(saves)
		expect(text).not.toMatch(/(?:await|return) (?:this\.objectStore|store|membershipStore|stores\.membershipStore|ensureRelationType\([^()]*\))\.saveObject\(/)
	})
})

describe('saveRelationRow', () => {
	beforeEach(() => {
		currentStore = null
	})

	it('reports a refused save to the form and does not reload', async () => {
		const store = refusingStore('Not allowed')
		currentStore = () => store
		const setResult = vi.fn()
		const vm = {
			relationListing: () => ({ type: 'membership', filter: { governanceBody: 'b1' } }),
			$refs: { formDialog: { setResult } },
			refresh: vi.fn(),
			t: (_app, text) => text,
		}

		await saveRelationRow(vm, { role: 'chair' })

		expect(store.saveObject).toHaveBeenCalledWith('membership', { role: 'chair', governanceBody: 'b1' })
		expect(setResult).toHaveBeenCalledWith({ error: 'Not allowed' })
		expect(vm.refresh).not.toHaveBeenCalled()
	})
})

describe('memberImportRun importPerson', () => {
	it('rejects when the membership save is refused, so the import reports it', async () => {
		const vm = { bodyId: 'b1' }
		const membershipStore = refusingStore('Duplicate membership')
		const personStore = {}

		await expect(memberImportRunMixin.methods.importPerson.call(
			vm,
			{ name: 'A. Jansen', email: 'a@example.org', nextcloudUserId: '', role: 'member' },
			{ personStore, membershipStore },
		)).rejects.toThrow('Duplicate membership')
		expect(membershipStore.saveObject).toHaveBeenCalledWith('membership', { person: 'p1', governanceBody: 'b1', role: 'member' })
	})
})
