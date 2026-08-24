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
	// Strip a UTF-8 BOM (U+FEFF) if present.
	let input = String(text ?? '')
	if (input.charCodeAt(0) === 0xfeff) {
		input = input.slice(1)
	}
	const records = []
	let field = ''
	let record = []
	let inQuotes = false

	const endField = () => {
		record.push(field)
		field = ''
	}
	const endRecord = () => {
		endField()
		// Skip records that are entirely empty (blank lines).
		if (record.length > 1 || record[0].trim() !== '') {
			records.push(record)
		}
		record = []
	}

	for (let i = 0; i < input.length; i++) {
		const ch = input[i]
		if (inQuotes) {
			if (ch === '"') {
				if (input[i + 1] === '"') {
					field += '"'
					i++
				} else {
					inQuotes = false
				}
			} else {
				field += ch
			}
		} else if (ch === '"') {
			inQuotes = true
		} else if (ch === ',') {
			endField()
		} else if (ch === '\n') {
			endRecord()
		} else if (ch === '\r') {
			// Swallow; \r\n is handled by the \n branch, bare \r ends a record.
			if (input[i + 1] !== '\n') {
				endRecord()
			}
		} else {
			field += ch
		}
	}
	if (field !== '' || record.length > 0) {
		endRecord()
	}
	return records
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
