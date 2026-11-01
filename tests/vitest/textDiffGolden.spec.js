/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for diffWords: a fixed set of text pairs, including generated
 * ones around the LCS cell limit, with every result snapshotted.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */
import { describe, expect, it } from 'vitest'
import { changeMagnitude, diffWords } from '../../src/utils/textDiff.js'

/**
 * A deterministic text of `count` words drawn from a small vocabulary.
 *
 * @param {number} count - number of words
 * @param {number} seed - the start of the sequence
 * @return {string} the text
 */
function words(count, seed) {
	const vocabulary = [
		'de',
		'raad',
		'besluit',
		'motie',
		'het',
		'college',
		'vraagt',
		'om',
		'een',
		'plan',
	]
	const out = []
	let state = seed
	for (let i = 0; i < count; i++) {
		state = (state * 1103515245 + 12345) % 2147483648
		out.push(vocabulary[state % vocabulary.length])
	}
	return out.join(' ')
}

const PAIRS = {
	'both empty': ['', ''],
	'both null': [null, undefined],
	'original empty': ['', 'nieuwe tekst hier'],
	'proposed empty': ['oude tekst hier', ''],
	identical: ['de raad besluit', 'de raad besluit'],
	'whitespace only differs': ['de  raad\tbesluit', ' de raad besluit '],
	'one word changed': ['de raad besluit vandaag', 'de raad besluit morgen'],
	'word inserted in the middle': ['de raad besluit', 'de gehele raad besluit'],
	'word removed at the start': ['niet de raad besluit', 'de raad besluit'],
	'all different': ['een twee drie', 'vier vijf zes'],
	'prefix only shared': ['de raad besluit a b c', 'de raad besluit x y'],
	'suffix only shared': ['a b de raad', 'x y z de raad'],
	'repeated words': ['a a b a a', 'a b a b a'],
	'proposed is a prefix': ['de raad besluit vandaag', 'de raad'],
	'original is a suffix': ['raad', 'de raad'],
	'unicode words': ['café één naïef', 'café twee naïef'],
	interleaved: ['a b c d e f', 'a x c y e z'],
	'generated small': [words(40, 1), words(45, 2)],
	'generated equal limit': [
		'start ' + words(500, 3) + ' end',
		'start ' + words(500, 4) + ' end',
	],
	'generated over limit': [
		'start ' + words(500, 5) + ' end',
		'start ' + words(501, 6) + ' end',
	],
}

describe('diffWords golden', () => {
	for (const [name, [original, proposed]] of Object.entries(PAIRS)) {
		it(name, () => {
			expect({
				segments: diffWords(original, proposed),
				magnitude: changeMagnitude(original, proposed),
			}).toMatchSnapshot()
		})
	}
})
