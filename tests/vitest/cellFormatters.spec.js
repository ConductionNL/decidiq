/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Unit tests for src/utils/cellFormatters.js — the app-registered
 * `cnFormatters` registry passed to CnAppRoot (see src/App.vue). Covers the
 * `plainYear` formatter, which exists solely so year/boekjaar columns render
 * "2026" instead of the `Intl.NumberFormat`-grouped "2,026" that every other
 * numeric-column path in @conduction/nextcloud-vue produces.
 *
 * @spec openspec/changes/ux-debt-rendering/design.md#decision-2-year-formatting-via-a-new-app-registered-formatter-not-a-nc-vue-change
 */

import { describe, expect, it } from 'vitest'
import cellFormatters from '../../src/utils/cellFormatters.js'

describe('plainYear', () => {
	it('renders a 4-digit year without a thousands separator', () => {
		expect(cellFormatters.plainYear(2026)).toBe('2026')
		expect(cellFormatters.plainYear(1999)).toBe('1999')
	})

	it('accepts a numeric string and strips it to plain digits', () => {
		expect(cellFormatters.plainYear('2026')).toBe('2026')
	})

	it('truncates a non-integer numeric value', () => {
		expect(cellFormatters.plainYear(2026.7)).toBe('2026')
	})

	it('passes through null/undefined/empty as an empty string, never "NaN"', () => {
		expect(cellFormatters.plainYear(null)).toBe('')
		expect(cellFormatters.plainYear(undefined)).toBe('')
		expect(cellFormatters.plainYear('')).toBe('')
	})

	it('falls back to the stringified original value for a non-numeric input', () => {
		expect(cellFormatters.plainYear('not-a-year')).toBe('not-a-year')
	})
})
