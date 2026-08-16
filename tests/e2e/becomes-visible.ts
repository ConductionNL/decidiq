/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A POLLING visibility probe, for use as a `test.skip()` condition.
 *
 * ⚠️ WHY THIS FILE EXISTS — `locator.isVisible()` DOES NOT WAIT.
 * ------------------------------------------------------------------
 * `Locator.isVisible()` is an *immediate* predicate: it answers "is this
 * element visible on this tick". Its `timeout` option is **ignored** — passing
 * `{ timeout: 10_000 }` buys nothing at all, which is precisely why Playwright
 * deprecated the option. Only `expect(...).toBeVisible()` and
 * `locator.waitFor()` poll.
 *
 * So this shape, which this repo used in seven spec files:
 *
 *     await page.goto(url)
 *     const has = await thing.isVisible({ timeout: 10_000 }).catch(() => false)
 *     test.skip(!has, 'Agenda tab not present — deployed build predates …')
 *
 * asks the question before the SPA has issued a single XHR. It answers "no"
 * essentially always, and the test skips **with a reason that is false**.
 *
 * 🔑 A SKIP WHOSE STATED REASON IS UNTRUE IS AN INVISIBLE PASS — and a worse
 * one than a stub assertion, because it renders in the report as "not
 * applicable" rather than as a gap, and the reason looks investigated. It also
 * inflates the skip count, which is the number that separates a flake from a
 * regression.
 *
 * `waitFor` polls. **The skip that survives it is a real one.**
 *
 * The `test.skip()` calls are deliberately KEPT in place: the fix is not to
 * unskip, it is to make the gate tell the truth. A test that still skips after
 * this change is skipping for the reason it states.
 */
import type { Locator } from '@playwright/test'

/**
 * Wait up to `timeout` for a locator to become visible; return whether it did.
 *
 * @param locator The locator to poll. `.first()` is applied so a strict-mode
 *                violation on a multi-match selector cannot masquerade as an
 *                absence.
 * @param timeout Milliseconds to poll for. Default 10s — enough for a
 *                Nextcloud SPA route to mount and fetch.
 * @return `true` when the element became visible within `timeout`, else
 *         `false`. Never throws.
 */
export async function becomesVisible(
	locator: Locator,
	timeout = 10_000,
): Promise<boolean> {
	return await locator
		.first()
		.waitFor({ state: 'visible', timeout })
		.then(() => true)
		.catch(() => false)
}
