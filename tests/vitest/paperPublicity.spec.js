// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
// @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public

import { describe, expect, it } from 'vitest'
import { isPaperPublic } from '../../src/utils/paperPublicity.js'

describe('isPaperPublic', () => {
	it('is public without a record, or with one that does not say otherwise', () => {
		expect(isPaperPublic(null)).toBe(true)
		expect(isPaperPublic({ title: 'Raadsvoorstel' })).toBe(true)
		expect(isPaperPublic({ public: true })).toBe(true)
	})

	it('is closed when its record says public false', () => {
		expect(isPaperPublic({ public: false })).toBe(false)
		expect(isPaperPublic({ public: false }, { public: true })).toBe(false)
	})

	it('is closed on a non-public item, whatever its record says', () => {
		expect(isPaperPublic({ public: true }, { public: false })).toBe(false)
		expect(isPaperPublic(null, { public: false })).toBe(false)
	})
})
