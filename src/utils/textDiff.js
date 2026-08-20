/**
 * Pure word-level text diff helpers for the amendment diff view
 * (motion-amendment spec). LCS-based, dependency-free.
 *
 * - diffWords(original, proposed) → ordered segments
 *   { type: 'equal' | 'removed' | 'added', text } describing how the
 *   original motion text becomes the amendment's proposed text.
 * - changeMagnitude(original, proposed) → added + removed word count;
 *   the scope metric behind "most far-reaching first".
 * - suggestVotingOrder(amendments, motionText) → sorted copy, most
 *   far-reaching amendment first (the spec's suggested voting order).
 *
 * Unicode-aware: tokens are split on whitespace with the `u` flag so
 * diacritics (é, ë) and non-Latin scripts stay intact as single words.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/motion-amendment/spec.md
 */

/**
 * Guard for the LCS dynamic-programming table: above this many cells the
 * middle window is emitted as one removal + one addition block instead
 * (bounded memory; correctness preserved, granularity reduced).
 */
const LCS_CELL_LIMIT = 250000

/**
 * Split a text into word tokens (Unicode-aware, whitespace-delimited).
 *
 * @param {string} text Input text (null/undefined treated as empty).
 * @return {string[]} Word tokens, no empties.
 * @spec openspec/specs/motion-amendment/spec.md
 */
export function tokenizeWords(text) {
	if (typeof text !== 'string' || text === '') return []
	return text.split(/\s+/u).filter((token) => token !== '')
}

/**
 * Append a token run to the segment list, merging with the previous
 * segment when the type matches.
 *
 * @param {Array<{type: string, text: string}>} segments Accumulator.
 * @param {string} type Segment type: equal | removed | added.
 * @param {string[]} tokens Tokens to append.
 * @spec openspec/specs/motion-amendment/spec.md
 */
function pushSegment(segments, type, tokens) {
	if (!tokens.length) return
	const text = tokens.join(' ')
	const last = segments[segments.length - 1]
	if (last && last.type === type) {
		last.text += ' ' + text
	} else {
		segments.push({ type, text })
	}
}

/**
 * Compute the word-level diff between two texts.
 *
 * Strategy: trim the common prefix and suffix (typical amendments change a
 * single passage, making this near-linear), then run a classic LCS
 * backtrack on the remaining middle windows. When the DP table would
 * exceed LCS_CELL_LIMIT cells, the middle is emitted as a single
 * removed + added pair instead.
 *
 * @param {string} original The original (parent motion) text.
 * @param {string} proposed The proposed replacement text.
 * @return {Array<{type: 'equal'|'removed'|'added', text: string}>} Ordered segments.
 * @spec openspec/specs/motion-amendment/spec.md
 */
export function diffWords(original, proposed) {
	const a = tokenizeWords(original)
	const b = tokenizeWords(proposed)
	const segments = []

	if (!a.length && !b.length) return segments
	if (!a.length) {
		pushSegment(segments, 'added', b)
		return segments
	}
	if (!b.length) {
		pushSegment(segments, 'removed', a)
		return segments
	}

	// Common prefix.
	let start = 0
	while (start < a.length && start < b.length && a[start] === b[start]) start++

	// Common suffix (never overlapping the prefix).
	let endA = a.length
	let endB = b.length
	while (endA > start && endB > start && a[endA - 1] === b[endB - 1]) {
		endA--
		endB--
	}

	pushSegment(segments, 'equal', a.slice(0, start))

	const midA = a.slice(start, endA)
	const midB = b.slice(start, endB)

	if (midA.length && midB.length && midA.length * midB.length > LCS_CELL_LIMIT) {
		// Bounded fallback: whole middle replaced.
		pushSegment(segments, 'removed', midA)
		pushSegment(segments, 'added', midB)
	} else if (midA.length || midB.length) {
		appendLcsDiff(segments, midA, midB)
	}

	pushSegment(segments, 'equal', a.slice(endA))
	return segments
}

/**
 * LCS dynamic programming + backtrack over the trimmed middle windows,
 * appending segments to the accumulator.
 *
 * @param {Array<{type: string, text: string}>} segments Accumulator.
 * @param {string[]} a Original middle tokens.
 * @param {string[]} b Proposed middle tokens.
 * @spec openspec/specs/motion-amendment/spec.md
 */
function appendLcsDiff(segments, a, b) {
	const m = a.length
	const n = b.length

	// dp[i][j] = LCS length of a[i:] and b[j:].
	const dp = new Array(m + 1)
	for (let i = m; i >= 0; i--) {
		dp[i] = new Uint32Array(n + 1)
		if (i === m) continue
		for (let j = n - 1; j >= 0; j--) {
			if (a[i] === b[j]) {
				dp[i][j] = dp[i + 1][j + 1] + 1
			} else {
				dp[i][j] = Math.max(dp[i + 1][j], dp[i][j + 1])
			}
		}
	}

	let i = 0
	let j = 0
	const removed = []
	const added = []
	const flush = () => {
		pushSegment(segments, 'removed', removed.splice(0))
		pushSegment(segments, 'added', added.splice(0))
	}
	while (i < m && j < n) {
		if (a[i] === b[j]) {
			flush()
			pushSegment(segments, 'equal', [a[i]])
			i++
			j++
		} else if (dp[i + 1][j] >= dp[i][j + 1]) {
			removed.push(a[i])
			i++
		} else {
			added.push(b[j])
			j++
		}
	}
	while (i < m) removed.push(a[i++])
	while (j < n) added.push(b[j++])
	flush()
}

/**
 * Scope metric: how many words an amendment adds plus removes relative to
 * the original text. Higher = more far-reaching.
 *
 * @param {string} original The original (parent motion) text.
 * @param {string} proposed The proposed replacement text.
 * @return {number} Added + removed word count.
 * @spec openspec/specs/motion-amendment/spec.md
 */
export function changeMagnitude(original, proposed) {
	return diffWords(original, proposed).reduce(
		(sum, segment) =>
			segment.type === 'equal'
				? sum
				: sum + tokenizeWords(segment.text).length,
		0,
	)
}

/**
 * Suggest a voting order for a motion's amendments: most far-reaching
 * first (largest changeMagnitude against the motion text), ties broken by
 * earlier submittedAt, then id — mirroring the server-side deterministic
 * comparison so the suggestion is stable.
 *
 * @param {Array<object>} amendments Amendment objects ({ id, text, proposedText, submittedAt }).
 * @param {string} motionText The parent motion text.
 * @return {Array<object>} A sorted shallow copy (input is not mutated).
 * @spec openspec/specs/motion-amendment/spec.md
 */
export function suggestVotingOrder(amendments, motionText) {
	const items = (amendments || []).map((amendment) => ({
		amendment,
		magnitude: changeMagnitude(
			motionText || '',
			amendment?.proposedText || amendment?.text || '',
		),
	}))
	items.sort((x, y) => {
		if (x.magnitude !== y.magnitude) return y.magnitude - x.magnitude
		const subX = String(x.amendment?.submittedAt || '')
		const subY = String(y.amendment?.submittedAt || '')
		if (subX !== subY) return subX < subY ? -1 : 1
		const idX = String(x.amendment?.id || '')
		const idY = String(y.amendment?.id || '')
		return idX < idY ? -1 : idX > idY ? 1 : 0
	})
	return items.map((item) => item.amendment)
}
