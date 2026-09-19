/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An e2e context that CLAIMS a least-privileged caller must BUILD one.
 *
 * 🔴 A PROBE THAT SILENTLY ESCALATES ITSELF CAN ONLY MISLEAD.
 * `playwright.request.newContext()` and `browser.newContext()` inherit
 * `use.storageState` from playwright.config.ts, which in this repo is the
 * ADMINISTRATOR's session. So a context created with no `storageState` and
 * described as "anonymous" is signed in as a superuser.
 *
 * Measured on a live instance, 2026-09-19: two specs asserted that
 * /api/approval-routes/clearance and /api/approval-routes/actions refuse an
 * anonymous caller. A real anonymous caller gets 401 "Current user is not
 * logged in" from both. Those two probes got admin's 400, and reported the
 * product as broken. That direction is merely noisy. The other direction is
 * the dangerous one: publish either route tomorrow and the same probe passes
 * while a passer-by reads who is holding up somebody else's file, because the
 * probe can never be the principal it names.
 *
 * 🔑 IT READS THE CLAIM, NOT THE CALL. A context that never says it is
 * unprivileged is none of this file's business. The admin fixture-seeding
 * contexts elsewhere in tests/e2e are deliberate and stay untouched. What is
 * checked is the pair: a call site that says "anonymous" or "non-admin" and
 * does not say `storageState`.
 *
 * @spec exclude Structural invariant of the e2e suite; no behavioural spec.
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const E2E = path.resolve(__dirname, '../../tests/e2e')

/**
 * Words that assert a caller with less than the project's admin session.
 */
const CLAIMS =
	/anonymous|unauthenticated|not logged in|no session at all|least privileg|non-admin|unprivileged|logged out|signed out|without an account|passer-by/i

/**
 * How far back a claim may sit from the call it describes. The claim is
 * normally the comment directly above, or the name being assigned.
 */
const CLAIM_WINDOW = 14

/**
 * Every `.spec.ts` under tests/e2e, recursively.
 *
 * @param {string} dir Directory to walk.
 * @return {string[]} Absolute paths.
 */
function specFiles(dir) {
	return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			return specFiles(full)
		}
		return entry.name.endsWith('.spec.ts') ? [full] : []
	})
}

/**
 * The file with every comment and string body replaced by spaces of the same
 * length, so offsets and line numbers still line up with the original.
 *
 * Without this the scan reads its OWN explanatory comments: the fixed specs
 * describe the trap in prose that names `.newContext()`, and a naive search
 * flagged the very call sites that no longer exist. Strings are blanked for
 * the same reason a URL must not end the line.
 *
 * @param {string} source The file.
 * @return {string} Code-only text of identical length.
 */
function codeOnly(source) {
	const out = source.split('')
	let i = 0
	const blank = (from, to) => {
		for (let k = from; k < to && k < out.length; k += 1) {
			if (out[k] !== '\n') out[k] = ' '
		}
	}

	while (i < source.length) {
		const two = source.slice(i, i + 2)
		if (two === '//') {
			const end = source.indexOf('\n', i)
			blank(i, end === -1 ? source.length : end)
			i = end === -1 ? source.length : end
		} else if (two === '/*') {
			const end = source.indexOf('*/', i + 2)
			const stop = end === -1 ? source.length : end + 2
			blank(i, stop)
			i = stop
		} else if (source[i] === "'" || source[i] === '"' || source[i] === '`') {
			const quote = source[i]
			let j = i + 1
			while (j < source.length && source[j] !== quote) {
				j += source[j] === '\\' ? 2 : 1
			}
			blank(i + 1, j)
			i = j + 1
		} else {
			i += 1
		}
	}

	return out.join('')
}

/**
 * The source text of a `newContext(...)` call, from the opening paren to its
 * balanced close.
 *
 * @param {string} source The file.
 * @param {number} open   Index of the opening paren.
 * @return {string} The argument text, parens included.
 */
function callArguments(source, open) {
	let depth = 0
	for (let i = open; i < source.length; i += 1) {
		if (source[i] === '(') depth += 1
		if (source[i] === ')') {
			depth -= 1
			if (depth === 0) return source.slice(open, i + 1)
		}
	}
	return source.slice(open)
}

/**
 * Call sites that name an unprivileged principal and do not build one.
 *
 * @param {string} file Absolute path to a spec.
 * @return {string[]} Human-readable offences.
 */
function offencesIn(file) {
	const source = fs.readFileSync(file, 'utf8')
	// Claims live in comments, so they are read off the ORIGINAL text; calls
	// are found in the code-only copy, which has the same offsets.
	const lines = source.split('\n')
	const code = codeOnly(source)
	const offences = []

	const call = /\.newContext\s*\(/g
	let match
	while ((match = call.exec(code)) !== null) {
		const args = callArguments(code, match.index + match[0].length - 1)
		if (args.includes('storageState')) {
			continue
		}

		const line = code.slice(0, match.index).split('\n').length
		const claim = lines.slice(Math.max(0, line - CLAIM_WINDOW), line).join('\n')
		if (!CLAIMS.test(claim)) {
			continue
		}

		offences.push(
			`${path.relative(E2E, file)}:${line} names an unprivileged caller `
				+ 'but creates the context with no `storageState`, so it inherits '
				+ "the admin session from playwright.config.ts's `use`. Build it "
				+ 'with anonymousActor/actorFor from tests/e2e/support/api-actors.ts, '
				+ 'or pass `storageState: { cookies: [], origins: [] }` explicitly.',
		)
	}

	return offences
}

describe('a least-privileged e2e probe is really least-privileged', () => {
	it('finds the e2e specs, so the check below is not vacuous', () => {
		expect(specFiles(E2E).length, 'no e2e specs found').toBeGreaterThan(10)
	})

	it('no spec claims an unprivileged caller while inheriting the admin session', () => {
		const offences = specFiles(E2E).sort().flatMap(offencesIn)

		expect(offences, offences.join('\n')).toEqual([])
	})
})
