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

	if (!a.length || !b.length) {
		pushSegment(segments, 'removed', a)
		pushSegment(segments, 'added', b)
		return segments
	}

	const start = prefixLength(a, b)
	const suffix = suffixLength(a.slice(start), b.slice(start))
	const endA = a.length - suffix

	pushSegment(segments, 'equal', a.slice(0, start))
	appendMiddle(segments, {
		a: a.slice(start, endA),
		b: b.slice(start, b.length - suffix),
	})
	pushSegment(segments, 'equal', a.slice(endA))
	return segments
}

/**
 * Number of leading tokens two token lists share.
 *
 * @param {string[]} a Original tokens.
 * @param {string[]} b Proposed tokens.
 * @return {number} Shared prefix length.
 * @spec openspec/specs/motion-amendment/spec.md
 */
function prefixLength(a, b) {
	let length = 0
	while (length < a.length && length < b.length && a[length] === b[length])
		length++
	return length
}

/**
 * Number of trailing tokens two token lists share.
 *
 * @param {string[]} a Original tokens.
 * @param {string[]} b Proposed tokens.
 * @return {number} Shared suffix length.
 * @spec openspec/specs/motion-amendment/spec.md
 */
function suffixLength(a, b) {
	let length = 0
	while (
		length < a.length
		&& length < b.length
		&& a[a.length - 1 - length] === b[b.length - 1 - length]
	)
		length++
	return length
}

/**
 * Append the diff of the middle windows: LCS-based, or one removal plus
 * one addition when the LCS table would exceed LCS_CELL_LIMIT cells.
 *
 * @param {Array<{type: string, text: string}>} segments Accumulator.
 * @param {{a: string[], b: string[]}} mid Original and proposed middle tokens.
 * @spec openspec/specs/motion-amendment/spec.md
 */
function appendMiddle(segments, mid) {
	if (mid.a.length * mid.b.length > LCS_CELL_LIMIT) {
		pushSegment(segments, 'removed', mid.a)
		pushSegment(segments, 'added', mid.b)
		return
	}
	appendLcsDiff(segments, mid)
}

/**
 * The LCS table of two token lists: dp[i][j] is the LCS length of a[i:]
 * and b[j:].
 *
 * @param {string[]} a Original middle tokens.
 * @param {string[]} b Proposed middle tokens.
 * @return {Uint32Array[]} The table, (a.length + 1) rows of b.length + 1.
 * @spec openspec/specs/motion-amendment/spec.md
 */
function lcsTable(a, b) {
	const m = a.length
	const n = b.length
	const dp = new Array(m + 1)
	for (let i = m; i >= 0; i--) {
		dp[i] = new Uint32Array(n + 1)
		if (i === m) continue
		for (let j = n - 1; j >= 0; j--) {
			dp[i][j] =
				a[i] === b[j]
					? dp[i + 1][j + 1] + 1
					: Math.max(dp[i + 1][j], dp[i][j + 1])
		}
	}
	return dp
}

/**
 * LCS backtrack over the trimmed middle windows, appending segments to the
 * accumulator.
 *
 * @param {Array<{type: string, text: string}>} segments Accumulator.
 * @param {{a: string[], b: string[]}} mid Original and proposed middle tokens.
 * @spec openspec/specs/motion-amendment/spec.md
 */
function appendLcsDiff(segments, mid) {
	const run = {
		a: mid.a,
		b: mid.b,
		dp: lcsTable(mid.a, mid.b),
		i: 0,
		j: 0,
		removed: [],
		added: [],
	}
	while (run.i < run.a.length && run.j < run.b.length) backtrackStep(segments, run)
	for (const token of run.a.slice(run.i)) run.removed.push(token)
	for (const token of run.b.slice(run.j)) run.added.push(token)
	flushChanges(segments, run)
}

/**
 * One backtrack step: an equal token, a removal or an addition.
 *
 * @param {Array<{type: string, text: string}>} segments Accumulator.
 * @param {object} run The backtrack state: tokens, table, positions and pending changes.
 * @spec openspec/specs/motion-amendment/spec.md
 */
function backtrackStep(segments, run) {
	const { a, b, dp, i, j } = run
	if (a[i] === b[j]) {
		flushChanges(segments, run)
		pushSegment(segments, 'equal', [a[i]])
		run.i++
		run.j++
	} else if (dp[i + 1][j] >= dp[i][j + 1]) {
		run.removed.push(a[i])
		run.i++
	} else {
		run.added.push(b[j])
		run.j++
	}
}

/**
 * Emit the pending removals, then the pending additions.
 *
 * @param {Array<{type: string, text: string}>} segments Accumulator.
 * @param {object} run The backtrack state.
 * @spec openspec/specs/motion-amendment/spec.md
 */
function flushChanges(segments, run) {
	pushSegment(segments, 'removed', run.removed.splice(0))
	pushSegment(segments, 'added', run.added.splice(0))
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
	items.sort(
		(x, y) =>
			y.magnitude - x.magnitude
			|| textOrder(orderKey(x, 'submittedAt'), orderKey(y, 'submittedAt'))
			|| textOrder(orderKey(x, 'id'), orderKey(y, 'id')),
	)
	return items.map((item) => item.amendment)
}

/**
 * An amendment field as sort text; a missing value sorts as ''.
 *
 * @param {{amendment: object}} item A sort item.
 * @param {string} field The amendment field.
 * @return {string} The field as text.
 * @spec openspec/specs/motion-amendment/spec.md
 */
function orderKey(item, field) {
	return String(item.amendment?.[field] || '')
}

/**
 * Compare two texts: -1, 0 or 1.
 *
 * @param {string} x The first text.
 * @param {string} y The second text.
 * @return {number} The order.
 * @spec openspec/specs/motion-amendment/spec.md
 */
function textOrder(x, y) {
	if (x === y) return 0
	return x < y ? -1 : 1
}
