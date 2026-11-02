// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Member-import helpers — client-side CSV parsing, row validation, and
// duplicate detection for the governance-body CSV import dialog.
//
// The CSV is parsed and previewed entirely client-side; only the
// email→Nextcloud-account matching round-trips to the (admin-gated)
// /api/member-import/match endpoint, which mirrors MAX_IMPORT_ROWS
// server-side. @spec openspec/specs/admin-settings/spec.md

/** Maximum rows accepted per CSV import (server mirrors this cap). */
export const MAX_IMPORT_ROWS = 500

/** Valid participant roles (Participant schema enum + treasurer). */
export const MEMBER_ROLES = [
	'chair',
	'vice-chair',
	'secretary',
	'treasurer',
	'member',
	'observer',
	'guest',
]

/** Default role applied when a CSV row leaves the role column empty. */
export const DEFAULT_ROLE = 'member'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/**
 * Parse CSV text into an array of string-array records.
 *
 * Handles RFC-4180 quoting (quoted fields, doubled escaped quotes,
 * embedded commas/newlines), CRLF/LF line endings, and a UTF-8 BOM.
 * Empty lines are skipped.
 *
 * @param {string} text Raw CSV file contents.
 * @return {string[][]} Records (rows of fields).
 * @spec openspec/specs/admin-settings/spec.md
 */
export function parseCsv(text) {
	const csv = {
		input: withoutBom(text),
		records: [],
		field: '',
		record: [],
		inQuotes: false,
	}
	for (let i = 0; i < csv.input.length; i++) {
		i = csv.inQuotes ? readQuoted(csv, i) : readPlain(csv, i)
	}
	if (csv.field !== '' || csv.record.length > 0) {
		endRecord(csv)
	}
	return csv.records
}

/**
 * The text as a string, without a leading UTF-8 BOM (U+FEFF).
 *
 * @param {string} text Raw CSV file contents.
 * @return {string} The text to parse.
 * @spec openspec/specs/admin-settings/spec.md
 */
function withoutBom(text) {
	const input = String(text ?? '')
	return input.charCodeAt(0) === 0xfeff ? input.slice(1) : input
}

/**
 * Close the current field of the parse.
 *
 * @param {object} csv The parse state.
 * @return {void}
 * @spec openspec/specs/admin-settings/spec.md
 */
function endField(csv) {
	csv.record.push(csv.field)
	csv.field = ''
}

/**
 * Close the current record; a record that is entirely empty (a blank line)
 * is skipped.
 *
 * @param {object} csv The parse state.
 * @return {void}
 * @spec openspec/specs/admin-settings/spec.md
 */
function endRecord(csv) {
	endField(csv)
	if (csv.record.length > 1 || csv.record[0].trim() !== '') {
		csv.records.push(csv.record)
	}
	csv.record = []
}

/**
 * Read one character inside a quoted field; a doubled quote is an escaped
 * quote, a single one ends the quoting.
 *
 * @param {object} csv The parse state.
 * @param {number} i The position of the character.
 * @return {number} The position of the last character read.
 * @spec openspec/specs/admin-settings/spec.md
 */
function readQuoted(csv, i) {
	const ch = csv.input[i]
	if (ch !== '"') {
		csv.field += ch
		return i
	}
	if (csv.input[i + 1] === '"') {
		csv.field += '"'
		return i + 1
	}
	csv.inQuotes = false
	return i
}

// What an unquoted character does to the parse, per character that is not
// plain field text. \r\n is ended by the \n; a bare \r ends a record.
const PLAIN_ACTIONS = new Map([
	[
		'"',
		(csv) => {
			csv.inQuotes = true
		},
	],
	[',', endField],
	['\n', endRecord],
	[
		'\r',
		(csv, i) => {
			if (csv.input[i + 1] !== '\n') {
				endRecord(csv)
			}
		},
	],
])

/**
 * Read one character outside quotes.
 *
 * @param {object} csv The parse state.
 * @param {number} i The position of the character.
 * @return {number} The position of the last character read.
 * @spec openspec/specs/admin-settings/spec.md
 */
function readPlain(csv, i) {
	const action = PLAIN_ACTIONS.get(csv.input[i])
	if (action) {
		action(csv, i)
	} else {
		csv.field += csv.input[i]
	}
	return i
}

/**
 * Parse a member CSV (header: name,email,role — case-insensitive, any
 * order) into row objects.
 *
 * @param {string} text Raw CSV file contents.
 * @return {{rows: Array<{name: string, email: string, role: string, line: number}>, error: string|null}}
 *         Parsed rows, or a fatal error (missing header / no rows / over cap).
 * @spec openspec/specs/admin-settings/spec.md
 */
export function parseMemberCsv(text) {
	const records = parseCsv(text)
	if (records.length === 0) {
		return { rows: [], error: 'empty' }
	}

	const header = records[0].map((h) => h.trim().toLowerCase())
	const nameIdx = header.indexOf('name')
	const emailIdx = header.indexOf('email')
	const roleIdx = header.indexOf('role')
	if (nameIdx === -1 || emailIdx === -1) {
		return { rows: [], error: 'header' }
	}

	const dataRecords = records.slice(1)
	if (dataRecords.length > MAX_IMPORT_ROWS) {
		return { rows: [], error: 'too-many-rows' }
	}

	const rows = dataRecords.map((rec, i) => ({
		name: (rec[nameIdx] ?? '').trim(),
		email: (rec[emailIdx] ?? '').trim(),
		role: roleIdx === -1 ? '' : (rec[roleIdx] ?? '').trim().toLowerCase(),
		line: i + 2,
	}))
	return { rows, error: null }
}

/**
 * Validate parsed member rows and detect duplicates.
 *
 * A row is:
 * - `invalid` when the name is empty, the email is malformed, or the role
 *   is not in MEMBER_ROLES (empty role defaults to DEFAULT_ROLE instead);
 * - `duplicate` when its email matches an existing body member or an
 *   earlier row in the same file (case-insensitive);
 * - `ok` otherwise.
 *
 * @param {Array<{name: string, email: string, role: string, line: number}>} rows Parsed CSV rows.
 * @param {Array<{email?: string, nextcloudUserId?: string}>} existingMembers Current body members.
 * @return {Array<{name: string, email: string, role: string, line: number, status: string, reason: string}>}
 * @spec openspec/specs/admin-settings/spec.md
 */
export function validateMemberRows(rows, existingMembers = []) {
	const existingEmails = new Set(
		existingMembers
			.map((m) => (m.email || '').trim().toLowerCase())
			.filter((e) => e !== ''),
	)
	const seenEmails = new Set()

	return rows.map((row) => {
		const out = {
			...row,
			role: row.role === '' ? DEFAULT_ROLE : row.role,
			status: 'ok',
			reason: '',
		}
		const email = row.email.toLowerCase()

		if (row.name === '') {
			out.status = 'invalid'
			out.reason = 'missing-name'
		} else if (row.email === '' || !EMAIL_RE.test(row.email)) {
			out.status = 'invalid'
			out.reason = 'invalid-email'
		} else if (!MEMBER_ROLES.includes(out.role)) {
			out.status = 'invalid'
			out.reason = 'invalid-role'
		} else if (existingEmails.has(email)) {
			out.status = 'duplicate'
			out.reason = 'already-member'
		} else if (seenEmails.has(email)) {
			out.status = 'duplicate'
			out.reason = 'duplicate-in-file'
		}

		if (out.status === 'ok') {
			seenEmails.add(email)
		}
		return out
	})
}

/**
 * Detect which group members are already members of the body.
 *
 * Matches by Nextcloud uid first, then by email (case-insensitive).
 *
 * @param {Array<{uid: string, displayName: string, email: string}>} groupMembers Group rows.
 * @param {Array<{email?: string, nextcloudUserId?: string}>} existingMembers Current body members.
 * @return {Array<{uid: string, displayName: string, email: string, duplicate: boolean}>}
 * @spec openspec/specs/admin-settings/spec.md
 */
export function markGroupDuplicates(groupMembers, existingMembers = []) {
	const existingUids = new Set(
		existingMembers.map((m) => m.nextcloudUserId).filter(Boolean),
	)
	const existingEmails = new Set(
		existingMembers
			.map((m) => (m.email || '').trim().toLowerCase())
			.filter((e) => e !== ''),
	)
	return groupMembers.map((m) => ({
		...m,
		duplicate:
			existingUids.has(m.uid)
			|| (m.email !== '' && existingEmails.has(m.email.trim().toLowerCase())),
	}))
}
