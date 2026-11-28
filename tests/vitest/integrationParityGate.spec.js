/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Both server leaves (decidesk-decisions, decidiq-approval-chain) have a JS
 * registration the parity check (gate-24) can read at its call site. When the
 * registration moved behind a shared helper the check saw zero JS
 * registrations and reported both server leaves as phantom.
 *
 * @spec openspec/changes/leaf-integrations/tasks.md
 */
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

describe('integration parity check', () => {
	it('finds a JS registration for every server leaf and passes', () => {
		const out = execFileSync(
			process.execPath,
			[path.join(ROOT, 'scripts/check-integration-parity.js'), ROOT],
			{ encoding: 'utf8' },
		)
		expect(out).toContain('2 server leaf face(s), 2 JS registration(s)')
		expect(out).toContain('all rules pass')
	})
})
