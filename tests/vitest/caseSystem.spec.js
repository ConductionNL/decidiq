// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * platform-case-system-document-exchange (matrix rows plt-23, plt-24): the
 * case widgets show case actions only with a connected case system, fetch a
 * document once, and offer Send again only for a send with failed lines.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	canSendAgain,
	canSendMeetingFile,
	caseLabel,
	lineCounts,
	newestFirst,
	toFetch,
} from '../../src/utils/caseSystem.js'
import { validatorFor } from './helpers/registerSchema.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const failedSend = {
	meeting: '00000000-0000-4000-8000-000000000001',
	direction: 'send',
	target: 'https://zaken.example.org/api/v1/zaken/1',
	targetLabel: 'Z-2026-00500',
	requestedBy: 'griffier',
	requestedAt: '2026-03-13T09:00:00+01:00',
	lines: [
		{ name: 'Agenda.pdf', kind: 'agenda', source: 'agenda', status: 'sent', remoteUrl: 'https://documenten.example.org/1' },
		{ name: 'Besluitenlijst.pdf', kind: 'decision-list', source: 'decision-list', status: 'sent', remoteUrl: 'https://documenten.example.org/2' },
		{ name: 'Bijlage mededelingen.pdf', kind: 'item-document', source: 'file:502', fileId: 502, status: 'failed', error: 'The case system refused the document: informatieobjecttype not configured', confidential: true, ground: 'Gemeentewet artikel 25' },
	],
}

describe('the case system exchange (REQ-CSDX-001 to 006)', () => {
	it('counts lines per status and offers Send again only for a failed send', () => {
		expect(lineCounts(failedSend)).toEqual({ pending: 0, sent: 2, failed: 1 })
		expect(canSendAgain(failedSend)).toBe(true)
		expect(canSendAgain({ ...failedSend, direction: 'fetch' })).toBe(false)
		expect(canSendAgain({ ...failedSend, lines: failedSend.lines.slice(0, 2) })).toBe(false)
	})

	it('sends the meeting file only from approved minutes on', () => {
		expect(canSendMeetingFile({ lifecycle: 'review' })).toBe(false)
		expect(canSendMeetingFile({ lifecycle: 'approved' })).toBe(true)
		expect(canSendMeetingFile({ lifecycle: 'signed' })).toBe(true)
	})

	it('names a linked case by title and number, and nothing without a case', () => {
		expect(caseLabel({ url: 'https://z/1', identification: 'Z-2026-00412', title: 'Omgevingsvisie 2040' })).toBe('Omgevingsvisie 2040 (Z-2026-00412)')
		expect(caseLabel(null)).toBe('')
	})

	it('never fetches a document twice', () => {
		const documents = [
			{ url: 'https://d/voorstel', name: 'Raadsvoorstel omgevingsvisie.pdf', fetched: true },
			{ url: 'https://d/kaart', name: 'Bijlage 1 kaart.pdf', fetched: false },
		]
		expect(toFetch(documents, ['https://d/voorstel', 'https://d/kaart', 'https://d/kaart'])).toEqual(['https://d/kaart'])
	})

	it('lists the newest exchange first', () => {
		expect(newestFirst([{ requestedAt: '2026-03-12' }, { requestedAt: '2026-03-13' }]).map((r) => r.requestedAt)).toEqual(['2026-03-13', '2026-03-12'])
	})

	it('writes records and case links the register schema accepts', () => {
		const record = validatorFor('case-exchange-record')
		expect(record(failedSend), JSON.stringify(record.errors)).toBe(true)
		const seeds = JSON.parse(read('lib/Settings/profiles/municipality.json'))
		const text = JSON.stringify(seeds)
		expect(text).toContain('Z-2026-00412')
		expect(text).toContain('informatieobjecttype not configured')
	})

	it('puts the case widgets on the agenda item and meeting pages', () => {
		const manifest = JSON.parse(read('src/manifest.json'))
		const page = (id) => manifest.pages.find((p) => p.id === id)
		const widget = (id, component) => page(id).config.widgets.find((w) => w.component === component)
		expect(widget('AgendaItemDetail', 'AgendaItemCaseTab')).toBeTruthy()
		expect(widget('MeetingDetail', 'MeetingCaseSystemTab')).toBeTruthy()
		const registry = read('src/registry.js')
		expect(registry).toContain('AgendaItemCaseTab: page(AgendaItemCaseTab)')
		expect(registry).toContain('MeetingCaseSystemTab: page(MeetingCaseSystemTab)')
		expect(read('src/components/tabs/MinutesDocumentTab.vue')).toContain('<CaseSystemSendButton')
	})
})
