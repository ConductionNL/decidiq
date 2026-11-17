// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Stable line numbers for a motion text (motion-change-recommendations).
 *
 * The same function numbers the line view and fills the recommendation
 * dialog, so a line number means the same thing everywhere. The server never
 * relies on the numbers: a recommendation stores the exact words of its lines
 * (`targetPassage`), and that is what accepting replaces.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-001-the-motion-text-can-be-read-with-stable-line-numbers
 */

/** The widest a line may be, in characters, for every motion. */
export const LINE_WIDTH = 80

/**
 * Break a text into numbered lines of at most `width` characters, at word
 * boundaries. A paragraph break always starts a new line; an empty paragraph
 * gets no number. A single word longer than the width is kept whole on its
 * own line rather than split. `start` and `end` are offsets into the text,
 * so `text.slice(start, end)` is the line's exact text.
 *
 * @param {string} text The motion text.
 * @param {number} width The widest line, in characters.
 *
 * @return {Array<{number: number, text: string, start: number, end: number}>} The lines, numbered from 1.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-001-the-motion-text-can-be-read-with-stable-line-numbers
 */
export function numberLines(text, width = LINE_WIDTH) {
	const source = typeof text === 'string' ? text : ''
	const lines = []
	const words = /\S+/g
	let paragraphStart = 0
	for (const paragraph of source.split('\n')) {
		let lineStart = -1
		let lineEnd = -1
		let match
		words.lastIndex = 0
		while ((match = words.exec(paragraph)) !== null) {
			const wordStart = paragraphStart + match.index
			const wordEnd = wordStart + match[0].length
			if (lineStart === -1) {
				lineStart = wordStart
				lineEnd = wordEnd
			} else if (wordEnd - lineStart <= width) {
				lineEnd = wordEnd
			} else {
				lines.push(lineAt(source, lines.length + 1, lineStart, lineEnd))
				lineStart = wordStart
				lineEnd = wordEnd
			}
		}
		if (lineStart !== -1) {
			lines.push(lineAt(source, lines.length + 1, lineStart, lineEnd))
		}
		paragraphStart += paragraph.length + 1
	}
	return lines
}

/**
 * The exact text from the start of line `from` to the end of line `to`, as
 * it stands in the motion, or '' for a range outside the lines.
 *
 * @param {string} text The motion text.
 * @param {number} from The first line, from 1.
 * @param {number} to The last line, inclusive.
 * @param {number} width The widest line, in characters.
 *
 * @return {string} The passage.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording
 */
export function passageOf(text, from, to, width = LINE_WIDTH) {
	const lines = numberLines(text, width)
	const first = lines[Number(from) - 1]
	const last = lines[Number(to) - 1]
	if (!first || !last || last.number < first.number) return ''
	return text.slice(first.start, last.end)
}

/**
 * One numbered line.
 *
 * @param {string} source The whole text.
 * @param {number} number The line number.
 * @param {number} start Offset of its first character.
 * @param {number} end Offset after its last character.
 *
 * @return {{number: number, text: string, start: number, end: number}} The line.
 */
function lineAt(source, number, start, end) {
	return { number, text: source.slice(start, end), start, end }
}
