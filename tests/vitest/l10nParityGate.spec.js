// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * l10n-parity-ci-gate: the Dutch parity check runs as a CI leg and the
 * Dutch catalogue is at full parity with the English source.
 *
 * @spec openspec/specs/l10n-locale-parity/spec.md
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

describe('the Dutch parity gate (REQ-LPCG-001, REQ-LPCG-002)', () => {
	it('the npm script scopes the checker to nl', () => {
		const scripts = JSON.parse(read('package.json')).scripts
		expect(scripts['test:l10n:parity:nl']).toBe(
			'L10N_REQUIRED_LOCALES=nl node tests/l10n/check-l10n-parity.js',
		)
	})

	it('code-quality.yml runs it as a frontend check leg', () => {
		const line = read('.github/workflows/code-quality.yml')
			.split('\n')
			.find((l) => l.trim().startsWith('frontend-checks:'))
		const legs = JSON.parse(
			line.slice(line.indexOf("'") + 1, line.lastIndexOf("'")),
		)
		expect(legs).toContain('test:l10n:parity:nl')
	})
})

describe('nl.json is at full parity with en.json (REQ-LPCG-003)', () => {
	it('every English key has a non-empty Dutch translation', () => {
		const en = JSON.parse(read('l10n/en.json')).translations
		const nl = JSON.parse(read('l10n/nl.json')).translations
		const missing = Object.keys(en).filter((k) => !String(nl[k] ?? '').trim())
		expect(missing).toEqual([])
	})
})
