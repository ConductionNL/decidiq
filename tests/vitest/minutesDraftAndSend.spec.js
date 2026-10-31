// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Draft the minutes from the meeting, use the AI draft as the minutes, and
// send approved minutes to the members (minutes-draft-and-send).
//
// @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting
// @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-002-use-the-ai-draft-as-the-minutes
// @spec openspec/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-003-send-approved-minutes-to-the-members
// @e2e tests/e2e/minutes-draft-and-send.spec.ts

import Ajv from 'ajv'
import addFormats from 'ajv-formats'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	canDraft,
	canSend,
	distributePath,
	draftPath,
	keptSections,
	minutesFromAiDraft,
	newMinutesFor,
	withDraft,
} from '../../src/utils/minutesDraft.js'

const here = dirname(fileURLToPath(import.meta.url))
const root = resolve(here, '../../')
const read = (path) => readFileSync(resolve(root, path), 'utf8')

/**
 * The Minutes schema as the register serves it: the base register plus
 * every register.d fragment, reduced to the keywords a validator reads.
 *
 * @return {object} A JSON schema
 */
function minutesSchema() {
	const files = [
		'lib/Settings/decidesk_register.json',
		...readdirSync(resolve(root, 'lib/Settings/register.d'))
			.filter((f) => f.endsWith('.json'))
			.map((f) => `lib/Settings/register.d/${f}`),
	]
	let props = {}
	let required = []
	for (const file of files) {
		const schemas = JSON.parse(read(file))?.components?.schemas || {}
		for (const [name, schema] of Object.entries(schemas)) {
			if ((schema.slug || name) !== 'minutes') continue
			props = { ...props, ...(schema.properties || {}) }
			required = schema.required || required
		}
	}
	const keep = ['type', 'properties', 'items', 'enum', 'format', 'required']
	const clean = (node) => {
		if (!node || typeof node !== 'object') return node
		const out = {}
		for (const k of keep) {
			if (!(k in node)) continue
			if (k === 'properties') {
				out.properties = Object.fromEntries(
					Object.entries(node.properties).map(([p, v]) => [p, clean(v)]),
				)
			} else if (k === 'items') {
				out.items = clean(node.items)
			} else {
				out[k] = node[k]
			}
		}
		if (out.type === 'object' && out.properties) out.additionalProperties = false
		return out
	}
	return clean({ type: 'object', properties: props, required })
}

const ajv = addFormats(new Ajv({ allErrors: true, strict: false }))
const validMinutes = ajv.compile(minutesSchema())

const draft = {
	sections: [
		{ agendaItem: 'i-1', title: 'Opening', summary: 'De voorzitter opent.' },
		{
			agendaItem: 'i-2',
			title: 'Begroting',
			summary: 'De raad bespreekt de begroting.',
		},
		{
			agendaItem: 'i-3',
			title: 'Rondvraag',
			summary: 'Geen vragen.',
			discarded: true,
		},
		{ agendaItem: 'i-4', title: 'Moties', summary: 'Twee moties.' },
		{ agendaItem: 'i-5', title: 'Besluiten', summary: 'Vier besluiten.' },
		{ agendaItem: 'i-6', title: 'Sluiting', summary: 'De voorzitter sluit.' },
	],
}

describe('Draft from the meeting', () => {
	it('is offered while the minutes are a draft and writes the text into content', () => {
		expect(canDraft({ lifecycle: 'draft' })).toBe(true)
		expect(canDraft({ lifecycle: 'approved' })).toBe(false)
		expect(draftPath('min-14')).toBe(
			'/apps/decidiq/api/minutes/min-14/generate-draft',
		)
		const saved = withDraft(
			{ title: 'Notulen', lifecycle: 'draft' },
			'# Notulen\n\nAanwezig (27): ...',
		)
		expect(saved.content).toContain('Aanwezig (27)')
		expect(validMinutes(saved)).toBe(true)
	})
})

describe('Use as minutes', () => {
	it('keeps the five sections the secretary kept', () => {
		expect(keptSections(draft)).toHaveLength(5)
		const saved = minutesFromAiDraft(
			{ title: 'Notulen', lifecycle: 'draft' },
			draft,
		)
		expect(saved.content).toContain('## Begroting')
		expect(saved.content).not.toContain('Rondvraag')
		expect(saved.itemNotes.map((n) => n.agendaItem)).toEqual([
			'i-1',
			'i-2',
			'i-4',
			'i-5',
			'i-6',
		])
	})

	it('replaces the notes of a drafted item and keeps other notes', () => {
		const saved = minutesFromAiDraft(
			{
				title: 'Notulen',
				lifecycle: 'draft',
				itemNotes: [
					{ agendaItem: 'i-2', notes: 'oud' },
					{ agendaItem: 'i-9', notes: 'eigen aantekening' },
				],
			},
			draft,
		)
		expect(saved.itemNotes.find((n) => n.agendaItem === 'i-2').notes).toBe(
			'De raad bespreekt de begroting.',
		)
		expect(saved.itemNotes.find((n) => n.agendaItem === 'i-9').notes).toBe(
			'eigen aantekening',
		)
	})

	it('writes minutes the register accepts, also when it creates them for the meeting', () => {
		const created = newMinutesFor(
			'6f1c1c38-4d3c-4d0e-9a55-2b8b2b1f0e01',
			'Notulen',
		)
		const saved = minutesFromAiDraft(created, draft)
		expect(validMinutes(saved), JSON.stringify(validMinutes.errors)).toBe(true)
		expect(validMinutes({ ...saved, lifecycle: 'finished' })).toBe(false)
		expect(
			validMinutes({
				...saved,
				itemNotes: [{ agendaItem: 'i-1', note: 'x' }],
			}),
		).toBe(false)
	})

	it('is a button on the transcription widget draft', () => {
		const source = read('src/components/tabs/MeetingTranscriptionTab.vue')
		expect(source).toContain('minutesFromAiDraft')
		expect(source).toContain('draft-use-as-minutes')
	})
})

describe('Send to members', () => {
	it('is offered once the minutes are approved', () => {
		expect(canSend({ lifecycle: 'draft' })).toBe(false)
		expect(canSend({ lifecycle: 'review' })).toBe(false)
		expect(canSend({ lifecycle: 'approved' })).toBe(true)
		expect(canSend({ lifecycle: 'signed' })).toBe(true)
		expect(distributePath('min-14')).toBe(
			'/apps/decidiq/api/minutes/min-14/distribute',
		)
	})

	it('lives with Draft from the meeting on the minutes page', () => {
		const manifest = read('src/manifest.json')
		const registry = read('src/registry.js')
		expect(manifest).toContain('"widget-minutes-actions": "MinutesActionsTab"')
		expect(registry).toContain('MinutesActionsTab: page(MinutesActionsTab)')
		const tab = read('src/components/tabs/MinutesActionsTab.vue')
		expect(tab).toContain('minutes-draft-from-meeting')
		expect(tab).toContain('minutes-send-to-members')
	})
})
