// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * platform-document-metadata-fields (matrix row plt-20): files on a meeting or
 * agenda item carry details of a declared document type.
 *
 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
 */
import { describe, expect, it, vi } from 'vitest'
import { validatorFor } from './helpers/registerSchema.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

const { detailsPayload, filesUrl, rowsFor, summary, typesFor } =
	await import('../../src/utils/documentMetadata.js')

const raadsvoorstel = {
	id: 'type-rv',
	name: 'Raadsvoorstel',
	appliesTo: ['agenda-item'],
	active: true,
	fields: [
		{
			key: 'zaaknummer',
			label: 'Zaaknummer',
			fieldType: 'string',
			required: true,
		},
		{
			key: 'portefeuillehouder',
			label: 'Portefeuillehouder',
			fieldType: 'string',
		},
		{
			key: 'status',
			label: 'Status',
			fieldType: 'enum',
			enumValues: ['concept', 'definitief'],
		},
	],
}
const presentatie = {
	id: 'type-pr',
	name: 'Presentatie',
	appliesTo: ['meeting'],
	active: true,
	fields: [{ key: 'spreker', label: 'Spreker', fieldType: 'string' }],
}
const retired = {
	id: 'type-old',
	name: 'Oud',
	appliesTo: ['meeting', 'agenda-item'],
	active: false,
	fields: [],
}

describe('document types are offered where they apply (REQ-DMF-001)', () => {
	it('offers a meeting-only type on meeting files and not on agenda item files', () => {
		const types = [raadsvoorstel, presentatie, retired]
		expect(typesFor(types, 'meeting').map((t) => t.id)).toEqual(['type-pr'])
		expect(typesFor(types, 'agenda-item').map((t) => t.id)).toEqual(['type-rv'])
	})

	it('accepts the document type the settings page saves', () => {
		const validate = validatorFor('document-type')
		expect(
			validate({
				name: 'Raadsvoorstel',
				appliesTo: ['agenda-item'],
				active: true,
				fields: raadsvoorstel.fields,
			}),
		).toBe(true)
	})
})

describe('a record describes one file (REQ-DMF-002)', () => {
	it('builds a record the register accepts', () => {
		const payload = detailsPayload({
			file: {
				id: 412,
				name: 'Raadsvoorstel omgevingsvisie.pdf',
				mimetype: 'application/pdf',
			},
			target: 'agenda-item',
			objectId: '5d0c7a3e-3b1e-4c1a-9f0a-1a2b3c4d5e6f',
			type: raadsvoorstel,
			typeFields: { zaaknummer: 'Z-2026-00412', status: 'definitief' },
		})
		expect(payload).toMatchObject({
			fileId: 412,
			agendaItem: '5d0c7a3e-3b1e-4c1a-9f0a-1a2b3c4d5e6f',
			type: 'type-rv',
			documentType: 'Raadsvoorstel',
		})
		const validate = validatorFor('digital-document')
		expect(
			validate({ ...payload, type: '7a1f0000-0000-4000-a000-000000000001' }),
			JSON.stringify(validate.errors),
		).toBe(true)
	})

	it('updates the existing record instead of making a second one', () => {
		const payload = detailsPayload({
			file: { id: 7, name: 'x.pdf' },
			target: 'meeting',
			objectId: 'm-1',
			type: null,
			typeFields: {},
			existing: { id: 'doc-1', documentType: 'Notitie' },
		})
		expect(payload.id).toBe('doc-1')
		expect(payload.meeting).toBe('m-1')
		expect(payload.documentType).toBe('Notitie')
	})
})

describe('the clerk sees files with their details (REQ-DMF-003)', () => {
	it('joins files with their records by file id', () => {
		const rows = rowsFor(
			[
				{ id: 412, name: 'a.pdf' },
				{ id: 413, name: 'b.pdf' },
			],
			[{ id: 'doc-1', fileId: 412, type: 'type-rv' }],
		)
		expect(rows.map((row) => row.record?.id ?? null)).toEqual(['doc-1', null])
	})

	it('shows the type and the first two filled-in values', () => {
		expect(
			summary(
				{ typeFields: { zaaknummer: 'Z-2026-00412', status: 'definitief' } },
				raadsvoorstel,
			),
		).toEqual(['Zaaknummer: Z-2026-00412', 'Status: definitief'])
	})

	it('reads the files of the page from OpenRegister', () => {
		expect(filesUrl('agenda-item', 'item-1')).toBe(
			'/index.php/apps/openregister/api/objects/decidiq/agenda-item/item-1/files',
		)
	})
})
