/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for parseCsv: fixed inputs plus generated strings over the
 * characters the parser branches on, with every result snapshotted.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { describe, expect, it } from 'vitest'
import { parseCsv } from '../../src/utils/memberImport.js'

/**
 * A deterministic string of `count` characters drawn from the CSV alphabet.
 *
 * @param {number} count - number of characters
 * @param {number} seed - the start of the sequence
 * @return {string} the text
 */
function csvText(count, seed) {
	const alphabet = ['a', 'b', ' ', '"', '"', ',', ',', '\n', '\r', '\r\n', '﻿', '']
	let out = ''
	let state = seed
	for (let i = 0; i < count; i++) {
		state = (state * 1103515245 + 12345) % 2147483648
		out += alphabet[state % alphabet.length]
	}
	return out
}

const INPUTS = {
	empty: '',
	null: null,
	undefined,
	number: 42,
	'bom only': '﻿',
	'bom then data': '﻿name,email\na,b',
	'bom in the middle': 'a﻿,b',
	'single field': 'abc',
	'trailing newline': 'a,b\n',
	'trailing comma': 'a,b,',
	'only commas': ',,,\n,',
	'blank lines': '\n\n  \n\r\n',
	'whitespace record': '   \nx',
	'crlf records': 'a,b\r\nc,d\r\n',
	'bare cr records': 'a,b\rc,d\r',
	'cr at the end': 'a\r',
	'quoted comma': '"a,b",c',
	'quoted newline': '"a\nb",c\nd',
	'escaped quote': '"say ""hi""",x',
	'quote at the end': '"abc"',
	'unterminated quote': '"abc,def\nghi',
	'quote inside a field': 'ab"c,d"e',
	'double quote empty field': '"",a',
	'escaped quote at the end': '"a"""',
	'cr inside quotes': '"a\rb"\r',
}
for (let n = 0; n < 60; n++) {
	INPUTS['generated ' + n] = csvText(5 + ((n * 7) % 40), n + 1)
}

describe('parseCsv golden', () => {
	for (const [name, input] of Object.entries(INPUTS)) {
		it(name, () => {
			expect(parseCsv(input)).toMatchSnapshot()
		})
	}
})
