// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * agenda-office-files-to-pdf (matrix row age-17): an Office paper shows once,
 * as its PDF; the chair and the secretariat also get the original and a Try
 * again; what ConvertPaperToPdfJob writes is accepted by the register.
 *
 * @spec openspec/specs/agenda-management/spec.md#requirement-req-opdf-003-members-read-and-download-the-pdf
 */
import { describe, expect, it, vi } from 'vitest'
import { validatorFor } from './helpers/registerSchema.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

const { conversionPayload, conversionSetting, paperEntries, retryUrl, splitName } = await import(
	'../../src/utils/paperRenditions.js'
)

// The two shapes ConvertPaperToPdfJob writes (ConvertPaperToPdfJobTest).
const converted = {
	sourceFileId: 4711,
	sourceName: 'Programmabegroting 2027.docx',
	pdfFileId: 4712,
	backend: 'office',
	convertedAt: '2026-09-30T09:00:00+00:00',
}
const failed = {
	sourceFileId: 5000,
	sourceName: 'Bijlage investeringen.xlsx',
	failedAt: '2026-09-30T09:00:00+00:00',
	failure: 'No backend could convert this file',
}

describe('a member reads the PDF (REQ-OPDF-003)', () => {
	it('shows a converted paper once, as its PDF, without the original', () => {
		const [entry] = paperEntries([converted], false)
		expect(entry).toMatchObject({
			title: 'Programmabegroting 2027',
			pdfUrl: '/index.php/f/4712',
			originalUrl: '',
			canRetry: false,
		})
	})

	it('offers the chair and the secretariat the original too', () => {
		const [entry] = paperEntries([converted], true)
		expect(entry.originalUrl).toBe('/index.php/f/4711')
		expect(entry.originalKind).toBe('Word')
	})
})

describe('a failed conversion is visible and the original stays (REQ-OPDF-002)', () => {
	it('shows the spreadsheet with the reason, and Try again for the secretariat', () => {
		const [entry] = paperEntries([failed], true)
		expect(entry).toMatchObject({
			pdfUrl: '',
			originalUrl: '/index.php/f/5000',
			failure: 'No backend could convert this file',
			canRetry: true,
		})
		expect(paperEntries([failed], false)[0].canRetry).toBe(false)
	})

	it('queues the retry on the paper of this page', () => {
		expect(retryUrl('agenda-item', 'item-1', 5000)).toBe(
			'/index.php/apps/decidiq/api/papers/agenda-item/item-1/5000/convert',
		)
	})

	it('names the kind of Office file', () => {
		expect(splitName('Bijlage investeringen.xlsx')).toEqual({
			title: 'Bijlage investeringen',
			kind: 'Excel',
		})
		expect(splitName('Notes')).toEqual({ title: 'Notes', kind: '' })
	})
})

describe('the register accepts what the job writes (REQ-OPDF-001)', () => {
	for (const slug of ['agenda-item', 'meeting']) {
		it(`accepts both rendition shapes on a ${slug}`, () => {
			const validate = validatorFor(slug)
			const ok = validate({ paperRenditions: [converted, failed] })
			const errors = (validate.errors || []).filter((error) =>
				error.instancePath.startsWith('/paperRenditions'),
			)
			expect(ok || errors.length === 0, JSON.stringify(errors)).toBe(true)
			// The job omits a key rather than writing null: null is refused.
			validate({ paperRenditions: [{ ...failed, pdfFileId: null }] })
			expect(
				(validate.errors || []).some((error) =>
					error.instancePath.startsWith('/paperRenditions'),
				),
			).toBe(true)
		})
	}
})

describe('the administrator switch (REQ-OPDF-004)', () => {
	it('reads conversion as on unless the setting says false', () => {
		expect(conversionSetting({}).on).toBe(true)
		expect(conversionSetting({ convert_office_papers: 'true' }).on).toBe(true)
		expect(conversionSetting({ convert_office_papers: 'false' }).on).toBe(false)
		expect(conversionSetting({ convert_office_papers: 'off' }).on).toBe(false)
	})

	it('says when filinq is missing', () => {
		expect(conversionSetting({ filinq: false }).needsFilinq).toBe(true)
		expect(conversionSetting({ filinq: true }).needsFilinq).toBe(false)
	})

	it('writes the switch as the listener reads it', () => {
		expect(conversionPayload(false)).toEqual({ convert_office_papers: 'false' })
		expect(conversionPayload(true)).toEqual({ convert_office_papers: 'true' })
	})
})
