/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Document accessibility check in the browser: the badge text and colour,
 * the guidance per finding, the current report of a file, and the answer a
 * publisher gives the publication gate.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-002-per-document-accessibility-status-stored-and-badged
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-003-publication-gate-on-failing-documents-with-recorded-override
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-004-remediation-guidance-per-finding
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	canPublishPastGate,
	currentReports,
	findingRows,
	gateAnswer,
	gateRefusal,
	guidanceFor,
	statusBadge,
	STATUSES,
} from '../../src/utils/documentAccessibility.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')

describe('the accessibility badge', () => {
	it('gives every status its own text, labelled as a heuristic, in a theme colour', () => {
		const labels = STATUSES.map((status) => statusBadge(status).label)
		expect(new Set(labels).size).toBe(4)
		for (const status of ['pass', 'warnings', 'fail']) {
			expect(statusBadge(status).label).toContain('heuristic')
		}
		expect(statusBadge('pass').color).toBe('var(--color-success)')
		expect(statusBadge('warnings').color).toBe('var(--color-warning)')
		expect(statusBadge('fail').color).toBe('var(--color-error)')
	})

	it('reads an unknown or missing status as not checked yet', () => {
		expect(statusBadge(undefined)).toEqual(statusBadge('not-scanned'))
		expect(statusBadge('bogus').status).toBe('not-scanned')
	})
})

describe('the guidance per finding', () => {
	it('has a fix for every code the server can report, and nothing for others', () => {
		const server = read('lib', 'Service', 'DocumentAccessibilityScanService.php')
		const codes = [...server.matchAll(/^\t\t'([a-z-]+)' => '/gm)].map((m) => m[1])
		expect(codes.length).toBeGreaterThanOrEqual(9)
		for (const code of new Set(codes)) {
			expect(guidanceFor(code), code).not.toBe('')
		}
		expect(guidanceFor('nonsense')).toBe('')
	})

	it('uses the server text as its catalogue key, so both read the same translation', () => {
		const server = read('lib', 'Service', 'DocumentAccessibilityScanService.php')
			.replace(/'\s*\n\s*\.\s*'/g, '')
		expect(server).toContain(guidanceFor('no-text-layer'))
		expect(server).toContain(guidanceFor('not-tagged'))
	})

	it('shows findings with evidence and the guidance in the reader language', () => {
		expect(findingRows({
			findings: [
				{ code: 'no-text-layer', severity: 'error', evidence: '0 chars/14 pages', guidance: 'stale text' },
				{ code: 'custom-code', guidance: 'Server text' },
				null,
			],
		})).toEqual([
			{ code: 'no-text-layer', severity: 'error', evidence: '0 chars/14 pages', guidance: guidanceFor('no-text-layer') },
			{ code: 'custom-code', severity: 'info', evidence: '', guidance: 'Server text' },
		])
		expect(findingRows(null)).toEqual([])
	})
})

describe('the current report of a file', () => {
	it('is the newest by scannedAt', () => {
		const current = currentReports([
			{ fileId: 11, status: 'not-scanned', scannedAt: '2026-06-01T09:00:00Z' },
			{ fileId: 11, status: 'fail', scannedAt: '2026-06-02T09:00:00Z' },
			{ fileId: '12', status: 'pass', scannedAt: '2026-06-01T09:00:00Z' },
			{ fileId: 0, status: 'pass' },
		])
		expect(current.get(11).status).toBe('fail')
		expect(current.get(12).status).toBe('pass')
		expect(current.size).toBe(2)
	})
})

describe('the publication gate', () => {
	it('reads only the gate refusal out of a publish answer', () => {
		const documents = [{ fileId: 11, status: 'fail' }]
		expect(gateRefusal(409, { error: 'accessibility-gate', mode: 'block', documents })).toEqual({ mode: 'block', documents })
		expect(gateRefusal(409, { error: 'accessibility-gate', mode: 'warn' })).toEqual({ mode: 'warn', documents: [] })
		expect(gateRefusal(409, { message: 'Conflict' })).toBeNull()
		expect(gateRefusal(422, { error: 'accessibility-gate' })).toBeNull()
		expect(gateRefusal(undefined, undefined)).toBeNull()
	})

	it('needs a reason in block mode and nothing more in warn mode', () => {
		expect(canPublishPastGate('block', '')).toBe(false)
		expect(canPublishPastGate('block', '   ')).toBe(false)
		expect(canPublishPastGate('block', 'Wettelijke termijn')).toBe(true)
		expect(canPublishPastGate('warn', '')).toBe(true)
	})

	it('answers with the fields the server reads', () => {
		expect(gateAnswer('warn', '')).toEqual({ accessibilityAcknowledged: true })
		expect(gateAnswer('block', '  Termijn ')).toEqual({ accessibilityOverride: { reason: 'Termijn' } })
	})

	it('opens from both publish flows, from a dialog in its own file', () => {
		for (const tab of ['MeetingAgendaTab.vue', 'PublicationActionsTab.vue']) {
			const source = read('src', 'components', 'tabs', tab)
			expect(source, tab).toContain("import AccessibilityGateDialog from '../../dialogs/AccessibilityGateDialog.vue'")
			expect(source, tab).toContain('@confirm="answerAccessibilityGate"')
		}
		expect(read('src', 'dialogs', 'AccessibilityGateDialog.vue')).toContain('<NcDialog')
	})
})

describe('the accessibility tab', () => {
	it('sits on the meeting and agenda item pages and in the simple structure', () => {
		const manifest = JSON.parse(read('src', 'manifest.json'))
		const widgets = manifest.pages
			.flatMap((page) => page.config?.widgets || [])
			.filter((widget) => widget.component === 'DocumentAccessibilityTab')
			.map((widget) => widget.id)
		expect(widgets.sort()).toEqual(['agenda-document-accessibility', 'meeting-document-accessibility'])
		expect(read('src', 'registry.js')).toContain('DocumentAccessibilityTab: page(DocumentAccessibilityTab),')
		const simple = read('src', 'menu-layout.simple.json')
		expect(simple).toContain('"widgetId": "meeting-document-accessibility"')
		expect(simple).toContain('"widgetId": "agenda-document-accessibility"')
	})
})
