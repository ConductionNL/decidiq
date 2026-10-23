// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Details of the files attached to a meeting or an agenda item
 * (platform-document-metadata-fields).
 *
 * A file's details are a `digital-document` record that points at the file by
 * its Nextcloud id, at the meeting or agenda item, and at a document type whose
 * fields the record fills in. A record is made the first time a clerk saves
 * details for a file.
 *
 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
 */

import { generateUrl } from '@nextcloud/router'

/**
 * The page a file list is shown on, and the record field that links it.
 */
export const TARGETS = {
	meeting: { schema: 'meeting', field: 'meeting' },
	'agenda-item': { schema: 'agenda-item', field: 'agendaItem' },
}

/**
 * The OpenRegister address listing an object's files.
 *
 * @param {string} target 'meeting' or 'agenda-item'.
 * @param {string} objectId The meeting or agenda item.
 * @return {string} The URL.
 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
 */
export function filesUrl(target, objectId) {
	const { schema } = TARGETS[target]
	return generateUrl(
		`/apps/openregister/api/objects/decidiq/${schema}/${objectId}/files`,
	)
}

/**
 * The document types a clerk may pick for a file on this page: in use and
 * offered for this kind of page.
 *
 * @param {Array<object>} types Document types.
 * @param {string} target 'meeting' or 'agenda-item'.
 * @return {Array<object>} The types to offer.
 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-001-an-administrator-declares-document-types-and-their-fields
 */
export function typesFor(types, target) {
	return (types || []).filter((type) => {
		if (type.active === false) return false
		const applies =
			Array.isArray(type.appliesTo) && type.appliesTo.length > 0
				? type.appliesTo
				: Object.keys(TARGETS)
		return applies.includes(target)
	})
}

/**
 * Each file with its details record, when it has one.
 *
 * @param {Array<object>} files Files from OpenRegister's files API.
 * @param {Array<object>} records `digital-document` records of the page.
 * @return {Array<{file: object, record: ?object}>} Rows in file order.
 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
 */
export function rowsFor(files, records) {
	const byFile = new Map(
		(records || []).map((record) => [Number(record.fileId), record]),
	)
	return (files || []).map((file) => ({
		file,
		record: byFile.get(Number(file.id)) || null,
	}))
}

/**
 * The first values a row shows beside its type, as "label: value".
 *
 * @param {?object} record The details record.
 * @param {?object} type Its document type.
 * @param {number} [count] How many values.
 * @return {Array<string>} Up to `count` filled-in values.
 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-003-the-clerk-fills-in-document-details-on-the-meeting-and-agenda-item-pages
 */
export function summary(record, type, count = 2) {
	if (!record || !type) return []
	const values = record.typeFields || {}
	return (type.fields || [])
		.filter(
			(field) =>
				values[field.key] !== undefined
				&& values[field.key] !== null
				&& values[field.key] !== '',
		)
		.slice(0, count)
		.map((field) => `${field.label || field.key}: ${values[field.key]}`)
}

/**
 * The record to save for a file's details.
 *
 * @param {object} options What to save.
 * @param {object} options.file The file (id, name, mimetype).
 * @param {string} options.target 'meeting' or 'agenda-item'.
 * @param {string} options.objectId The meeting or agenda item.
 * @param {?object} options.type The chosen document type.
 * @param {?object} options.typeFields The field values.
 * @param {?object} [options.existing] The record already saved, if any.
 * @return {object} The `digital-document` record.
 * @spec openspec/specs/document-metadata-fields/spec.md#requirement-req-dmf-002-a-document-record-links-a-file-to-its-type-and-values
 */
export function detailsPayload({
	file,
	target,
	objectId,
	type,
	typeFields,
	existing = null,
}) {
	const payload = {
		name: file.name || file.title || String(file.id),
		documentType: type?.name || existing?.documentType || 'Document',
		fileId: Number(file.id),
		[TARGETS[target].field]: objectId,
		typeFields: typeFields || {},
	}
	const typeId = type?.id ?? type?.['@self']?.id
	if (typeId) {
		payload.type = typeId
	}
	const mime = file.mimetype || file.mimeType
	if (mime) {
		payload.encodingFormat = mime
	}
	if (existing?.id) {
		payload.id = existing.id
	}
	return payload
}
