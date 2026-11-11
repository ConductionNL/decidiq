// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Every detail tab below takes the object it shows as an `objectId` prop of
 * type String or Number, empty by default, and calls its own `load()` once on
 * creation and again whenever `objectId` changes. This pins that contract
 * tab by tab, whether a tab declares it inline or takes it from the shared
 * objectIdLoader mixin, and pins the mixin itself.
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const TABS = [
	'AgendaItemCaseTab',
	'AgendaItemTypeFieldsTab',
	'AgendaPaperRenditionsTab',
	'AgendaPaperSummariesTab',
	'CommitmentProgressTab',
	'DocumentMetadataTab',
	'GovernanceBodyRetentionTab',
	'MeetingBroadcastTab',
	'MeetingCaseSystemTab',
	'MeetingStageTab',
	'MeetingTechnicalQuestionsTab',
	'MeetingVideoCallTab',
	'MotionStageTab',
]

const INLINE_PROP = /\t\tobjectId: \{ type: \[String, Number\], default: '' \},\n/
const INLINE_WATCH = /\twatch: \{\n\t\tobjectId: \{\n\t\t\timmediate: true,\n(?:\t\t\t\/\*\*.*\*\/\n)?\t\t\thandler\(\) \{\n\t\t\t\tthis\.load\(\)\n\t\t\t\},\n\t\t\},\n\t\},\n/
const MIXIN = /\tmixins: \[objectIdLoader\],\n/
const MIXIN_IMPORT = "import { objectIdLoader } from './objectIdLoader.js'"

describe('detail tabs load their object by objectId', () => {
	for (const tab of TABS) {
		it(`${tab} declares the objectId prop and reloads on change`, () => {
			const source = read(`src/components/tabs/${tab}.vue`)
			const inline = INLINE_PROP.test(source) && INLINE_WATCH.test(source)
			const shared = MIXIN.test(source) && source.includes(MIXIN_IMPORT)
				&& !INLINE_PROP.test(source) && !/\twatch: \{\n\t\tobjectId:/.test(source)
			expect(inline || shared).toBe(true)
			expect(source).toMatch(/\n\t\tasync load\(\)|\n\t\tload\(\)/)
		})
	}
})
