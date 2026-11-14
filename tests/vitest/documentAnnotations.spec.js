/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Document annotations (document-annotations tasks 5 to 7): the geometry that
 * turns a selection, a click or a stroke into page-relative anchors and back,
 * what is drawn on a page and listed in the panel, the PDF filter of the
 * annotate tab, the visibility choices, the agenda counts, and the store's
 * round trips. global fetch is mocked; @nextcloud/auth + router are stubbed.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-003-annotation-view-with-overlay-and-panel-on-meeting-and-agenda-item-documents
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAnnotationStore } from '../../src/store/annotation.js'
import {
	annotationsUrl,
	annotationUrl,
	authorsOf,
	countOf,
	countsUrl,
	davUrl,
	drawnOnPage,
	exportUrl,
	isPdf,
	otherVersionLabel,
	ownUrl,
	panelList,
	pdfRows,
	quadRect,
	quadsFromRects,
	relativePoint,
	strokePath,
	thinStroke,
	versionUrl,
	visibilityOptions,
} from '../../src/utils/annotations.js'

const PAGE = { left: 100, top: 50, width: 400, height: 800 }
const t = (_app, text) => text

describe('annotation geometry', () => {
	it('turns a pointer position into a point relative to the page, clamped to it', () => {
		expect(relativePoint(300, 450, PAGE)).toEqual({ x: 0.5, y: 0.5 })
		expect(relativePoint(50, 900, PAGE)).toEqual({ x: 0, y: 1 })
		expect(
			relativePoint(300, 450, { left: 0, top: 0, width: 0, height: 10 }),
		).toEqual({ x: 0, y: 0 })
	})

	it('turns the rectangles of a selection into quads, skipping empty ones', () => {
		const quads = quadsFromRects(
			[
				{ left: 140, top: 130, right: 260, bottom: 150 },
				{ left: 140, top: 130, right: 140, bottom: 150 },
			],
			PAGE,
		)
		expect(quads).toEqual([[0.1, 0.1, 0.4, 0.1, 0.1, 0.125, 0.4, 0.125]])
	})

	it('draws a quad back as a rectangle at the size the page is shown', () => {
		expect(
			quadRect([0.1, 0.1, 0.4, 0.1, 0.1, 0.125, 0.4, 0.125], 800, 1600),
		).toEqual({
			x: 80,
			y: 160,
			width: 240,
			height: 40,
		})
	})

	it('draws a stroke as an SVG path and thins it before saving', () => {
		expect(
			strokePath(
				[
					[0.1, 0.2],
					[0.5, 0.25],
				],
				400,
				800,
			),
		).toBe('M40 160 L200 200')
		expect(strokePath(null, 400, 800)).toBe('')
		expect(
			thinStroke([
				[0.1, 0.1],
				[0.1001, 0.1001],
				[0.2, 0.2],
				[1.4, -1],
			]),
		).toEqual([
			[0.1, 0.1],
			[0.2, 0.2],
			[1, 0],
		])
	})
})

describe('what is drawn and listed', () => {
	const annotations = [
		{
			id: 'b',
			page: 7,
			fileVersion: '200',
			versionLabel: 'v2',
			visibility: 'body',
			author: 'secretary',
			createdAt: '2026-11-02',
		},
		{
			id: 'a',
			page: 3,
			fileVersion: '200',
			versionLabel: 'v2',
			visibility: 'private',
			author: 'alice',
			createdAt: '2026-11-03',
		},
		{
			id: 'c',
			page: 3,
			fileVersion: '100',
			versionLabel: 'v1',
			visibility: 'faction',
			author: 'bob',
			createdAt: '2026-11-01',
		},
	]

	it('draws on a page only the marks made on the version shown', () => {
		expect(drawnOnPage(annotations, 3, '200').map((a) => a.id)).toEqual(['a'])
		expect(drawnOnPage(annotations, 3, '100').map((a) => a.id)).toEqual(['c'])
		expect(drawnOnPage(annotations, 5, '200')).toEqual([])
	})

	it('lists by page, then by date, filtered by visibility and author', () => {
		expect(panelList(annotations).map((a) => a.id)).toEqual(['c', 'a', 'b'])
		expect(
			panelList(annotations, { visibility: 'body' }).map((a) => a.id),
		).toEqual(['b'])
		expect(panelList(annotations, { author: 'alice' }).map((a) => a.id)).toEqual(
			['a'],
		)
		expect(authorsOf(annotations)).toEqual(['secretary', 'alice', 'bob'])
	})

	it('labels an annotation of another version with its version', () => {
		expect(otherVersionLabel(annotations[2], '200')).toBe('v1')
		expect(otherVersionLabel(annotations[1], '200')).toBeNull()
	})
})

describe('the annotate entry and the agenda counts', () => {
	it('offers only PDFs', () => {
		expect(isPdf({ mimetype: 'application/pdf' })).toBe(true)
		expect(isPdf({ title: 'Notulen.PDF' })).toBe(true)
		expect(
			isPdf({
				mimetype:
					'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				title: 'x.docx',
			}),
		).toBe(false)
		expect(isPdf({ mimetype: 'text/plain', title: 'disguised.pdf' })).toBe(false)
		expect(
			pdfRows([
				{ id: 12, title: 'Raadsvoorstel.pdf', mimetype: 'application/pdf' },
				{ id: 13, title: 'Begroting.docx', mimetype: 'application/msword' },
				{ id: 0, title: 'broken.pdf', mimetype: 'application/pdf' },
			]),
		).toEqual([{ id: 12, name: 'Raadsvoorstel.pdf' }])
	})

	it('offers the faction only to someone in one', () => {
		expect(visibilityOptions(false, t).map((o) => o.id)).toEqual([
			'private',
			'body',
		])
		expect(visibilityOptions(true, t).map((o) => o.id)).toEqual([
			'private',
			'faction',
			'body',
		])
	})

	it('counts nothing for an item without annotations', () => {
		expect(countOf({ i1: 3 }, 'i1')).toBe(3)
		expect(countOf({ i1: 3 }, 'i2')).toBe(0)
		expect(countOf(null, 'i1')).toBe(0)
	})

	it('builds the API addresses', () => {
		expect(annotationsUrl('42')).toBe(
			'/index.php/apps/decidiq/api/documents/42/annotations',
		)
		expect(annotationUrl('a/b')).toBe(
			'/index.php/apps/decidiq/api/annotations/a%2Fb',
		)
		expect(countsUrl('m1')).toBe(
			'/index.php/apps/decidiq/api/meetings/m1/annotation-counts',
		)
		expect(exportUrl('csv')).toBe(
			'/index.php/apps/decidiq/api/annotations/export?format=csv',
		)
		expect(exportUrl('xml')).toBe(
			'/index.php/apps/decidiq/api/annotations/export?format=json',
		)
		expect(ownUrl()).toBe('/index.php/apps/decidiq/api/annotations/own')
		expect(davUrl('anna', '/Raad/Stuk 1.pdf')).toBe(
			'http://localhost/remote.php/dav/files/anna/Raad/Stuk%201.pdf',
		)
		expect(versionUrl('anna', 42, '1750000000')).toBe(
			'http://localhost/remote.php/dav/versions/anna/versions/42/1750000000',
		)
	})
})

describe('annotation store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.fetch
	})

	function answer(json, ok = true, status = 200) {
		return { ok, status, json: async () => json }
	}

	it('loads a document and keeps what the server said about it', async () => {
		globalThis.fetch = vi
			.fn()
			.mockResolvedValueOnce(
				answer({
					results: [{ id: 'a' }],
					fileVersion: '200',
					versionLabel: 'v2',
					name: 'Stuk.pdf',
					path: '/Stuk.pdf',
					canShareWithFaction: true,
				}),
			)
		const store = useAnnotationStore()
		await store.load('42')
		expect(globalThis.fetch).toHaveBeenCalledWith(
			'/index.php/apps/decidiq/api/documents/42/annotations',
			expect.objectContaining({ method: 'GET' }),
		)
		expect(store.annotations).toEqual([{ id: 'a' }])
		expect(store.fileVersion).toBe('200')
		expect(store.versionLabel).toBe('v2')
		expect(store.path).toBe('/Stuk.pdf')
		expect(store.canShareWithFaction).toBe(true)
		expect(store.error).toBe('')
	})

	it('shows the server message when a document cannot be read', async () => {
		globalThis.fetch = vi
			.fn()
			.mockResolvedValueOnce(
				answer({ message: 'Document not found.' }, false, 404),
			)
		const store = useAnnotationStore()
		await store.load(42)
		expect(store.annotations).toEqual([])
		expect(store.error).toBe('Document not found.')
		expect(store.loading).toBe(false)
	})

	it('adds, changes and removes an annotation', async () => {
		const store = useAnnotationStore()
		store.fileId = 42
		store.fileVersion = '200'
		globalThis.fetch = vi
			.fn()
			.mockResolvedValueOnce(
				answer(
					{
						annotation: {
							id: 'n1',
							bodyText: 'Vraag',
							fileVersion: '200',
						},
					},
					true,
					201,
				),
			)
			.mockResolvedValueOnce(
				answer({
					annotation: {
						id: 'n1',
						bodyText: 'Andere vraag',
						fileVersion: '200',
					},
				}),
			)
			.mockResolvedValueOnce(answer({ deleted: true }))

		await store.create({
			annotationType: 'sticky-note',
			page: 2,
			point: { x: 0.5, y: 0.5 },
			bodyText: 'Vraag',
		})
		expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toEqual({
			annotationType: 'sticky-note',
			page: 2,
			point: { x: 0.5, y: 0.5 },
			bodyText: 'Vraag',
		})
		expect(store.annotations.map((a) => a.id)).toEqual(['n1'])

		await store.update('n1', { bodyText: 'Andere vraag' })
		expect(globalThis.fetch.mock.calls[1][1].method).toBe('PUT')
		expect(store.annotations[0]).toEqual({
			id: 'n1',
			bodyText: 'Andere vraag',
			fileVersion: '200',
			current: true,
		})

		await store.remove('n1')
		expect(globalThis.fetch.mock.calls[2][0]).toBe(
			'/index.php/apps/decidiq/api/annotations/n1',
		)
		expect(store.annotations).toEqual([])
	})

	it('refuses a write the server refuses, keeping the list', async () => {
		const store = useAnnotationStore()
		store.annotations = [{ id: 'a' }]
		globalThis.fetch = vi
			.fn()
			.mockResolvedValueOnce(
				answer(
					{ message: 'Only the author can change this annotation.' },
					false,
					403,
				),
			)
		await expect(store.remove('a')).rejects.toThrow(
			'Only the author can change this annotation.',
		)
		expect(store.annotations).toEqual([{ id: 'a' }])
	})

	it('reads the counts in one request and forgets them on failure', async () => {
		const store = useAnnotationStore()
		globalThis.fetch = vi
			.fn()
			.mockResolvedValueOnce(answer({ counts: { i1: 3 } }))
			.mockRejectedValueOnce(new Error('offline'))
		expect(await store.loadCounts('m1')).toEqual({ i1: 3 })
		expect(globalThis.fetch).toHaveBeenCalledTimes(1)
		expect(await store.loadCounts('m1')).toEqual({})
	})

	it('deletes all own annotations and clears what it holds', async () => {
		const store = useAnnotationStore()
		store.annotations = [{ id: 'a' }]
		store.counts = { i1: 1 }
		globalThis.fetch = vi.fn().mockResolvedValueOnce(answer({ deleted: 12 }))
		expect(await store.deleteOwn()).toBe(12)
		expect(globalThis.fetch.mock.calls[0][1].method).toBe('DELETE')
		expect(store.annotations).toEqual([])
		expect(store.counts).toEqual({})
	})
})
