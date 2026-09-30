#!/usr/bin/env node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WCAG 2.1 AA audit report (platform-accessibility-audit-report, matrix row
 * plt-22). Combines the axe-core scan (tests/axe/report.json), the manual
 * checklist (docs/compliance/wcag-manual-checks.json) and the app version into
 * docs/compliance/wcag-2.1-aa-audit.md: per WCAG 2.1 A and AA success
 * criterion pass, fail, not applicable or not tested, with each failure's
 * page, rule and owner. A criterion nobody checked reads not tested, never
 * pass, so a green scan cannot pass for a full audit.
 *
 * Usage: node scripts/wcag-audit-report.mjs
 *
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-004-each-release-has-a-readable-wcag-21-aa-audit-report
 */
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

export const OUTCOMES = ['pass', 'fail', 'not-applicable', 'not-tested']

/**
 * The axe tag of a success criterion: 1.4.10 is `wcag1410`.
 *
 * @param {string} id The criterion number.
 * @return {string} The tag.
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-004-each-release-has-a-readable-wcag-21-aa-audit-report
 */
export function axeTag(id) {
	return `wcag${id.replace(/\./g, '')}`
}

/**
 * One row per criterion. A scan violation tagged with the criterion makes it
 * fail, whoever owns it. Otherwise a criterion axe decides passes when a scan
 * ran; every other criterion takes the checklist result, and an empty or
 * unknown result is not tested.
 *
 * @param {{criteria: {axe: string[], criteria: Array}, scan: object|null, checklist: {entries: Array}}} input The inputs.
 * @return {Array<{id: string, level: string, name: string, outcome: string, source: string, findings: Array, tester: string, date: string, note: string}>}
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-003-criteria-axe-cannot-decide-are-checked-by-hand-and-never-assumed
 */
export function auditRows({ criteria, scan, checklist }) {
	const violations = scan
		? [...(scan.violations || []), ...(scan.others || [])]
		: []
	const manual = new Map(
		(checklist?.entries || []).map((entry) => [entry.criterion, entry]),
	)
	return criteria.criteria.map((criterion) => {
		const findings = violations
			.filter((violation) =>
				(violation.tags || []).includes(axeTag(criterion.id)),
			)
			.map((violation) => ({
				page: violation.page || '',
				rule: violation.id,
				impact: violation.impact || '',
				owner: violation.owner || 'decidiq',
			}))
		const entry = manual.get(criterion.id) || {}
		const byAxe = criteria.axe.includes(criterion.id)
		let outcome = 'not-tested'
		let source = byAxe ? 'axe-core' : 'manual'
		if (findings.length > 0) {
			outcome = 'fail'
			source = 'axe-core'
		} else if (byAxe && scan) {
			outcome = 'pass'
		} else if (!byAxe && OUTCOMES.includes(entry.result)) {
			outcome = entry.result
		}
		return {
			...criterion,
			outcome,
			source,
			findings,
			tester: entry.tester || '',
			date: entry.date || '',
			note: entry.note || '',
		}
	})
}

/**
 * Counts per outcome, not tested included as a count of its own.
 *
 * @param {Array<{outcome: string}>} rows The rows.
 * @return {Record<string, number>} The counts.
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-004-each-release-has-a-readable-wcag-21-aa-audit-report
 */
export function summary(rows) {
	const counts = Object.fromEntries(OUTCOMES.map((outcome) => [outcome, 0]))
	for (const row of rows) counts[row.outcome] += 1
	return counts
}

const LABELS = {
	pass: 'pass',
	fail: 'fail',
	'not-applicable': 'not applicable',
	'not-tested': 'not tested',
}

/**
 * Escape what MDX would read as code: the docs site builds the report as MDX,
 * where `{id}` is a JavaScript expression and `<json>` a tag, and one such
 * character fails the whole docs build.
 *
 * @param {string} markdown The report.
 * @return {string} The report with braces escaped and `<` as an entity.
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-004-each-release-has-a-readable-wcag-21-aa-audit-report
 */
export function mdxSafe(markdown) {
	return markdown.replace(/[{}]/g, (brace) => `\\${brace}`).replace(/</g, '&lt;')
}

/**
 * The report in Markdown.
 *
 * @param {{version: string, date: string, sample: object, rows: Array, scanned: boolean, theme: string}} input What to write.
 * @return {string} The Markdown.
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-004-each-release-has-a-readable-wcag-21-aa-audit-report
 */
export function renderReport({ version, date, sample, rows, scanned, theme }) {
	const counts = summary(rows)
	const pages = [...(sample.pages || []), ...(sample.processPages || [])]
	const lines = [
		'# WCAG 2.1 AA audit report',
		'',
		`decidiq version ${version}, evaluated on ${date}.`,
		'',
		'This is a supplier self-evaluation following WCAG-EM 1.0 (Website Accessibility Conformance Evaluation Methodology). It is not an independent audit. An organisation cites it in its own accessibility statement (toegankelijkheidsverklaring).',
		'',
		scanned
			? `The automated scan ran axe-core with the WCAG 2.1 A and AA rules, theme: ${theme || 'default'}.`
			: 'The automated scan did not run for this report, so every criterion it decides reads not tested.',
		'',
		'## Summary',
		'',
		`- Pass: ${counts.pass}`,
		`- Fail: ${counts.fail}`,
		`- Not applicable: ${counts['not-applicable']}`,
		`- Not tested: ${counts['not-tested']}`,
		'',
		'## Sample',
		'',
		...pages.map(
			(page) =>
				`- ${page.id}: ${page.path || `first row of ${page.from}${page.suffix ? `, then ${page.suffix}` : ''}`} (${page.owner})`,
		),
		...(sample.processes || []).map(
			(process) => `- Process: ${process.title} (${process.pages.join(', ')})`,
		),
		...(sample.notApplicable || []).map(
			(entry) => `- Not sampled: ${entry.id}. ${entry.reason}`,
		),
		'',
		'## Success criteria',
		'',
		'| Criterion | Level | Outcome | Checked by | Findings |',
		'| --- | --- | --- | --- | --- |',
		...rows.map((row) => {
			const findings = row.findings
				.map(
					(finding) =>
						`${finding.page}: ${finding.rule} (${finding.impact}, ${finding.owner})`,
				)
				.join('; ')
			const checkedBy =
				row.source === 'manual' && row.tester
					? `${row.tester}, ${row.date}`
					: row.source
			return `| ${row.id} ${row.name} | ${row.level} | ${LABELS[row.outcome]} | ${checkedBy} | ${findings || row.note} |`
		}),
		'',
	]
	return mdxSafe(lines.join('\n'))
}

const self = fileURLToPath(import.meta.url)
if (process.argv[1] === self) {
	const root = join(dirname(self), '..')
	const read = (path) => JSON.parse(readFileSync(join(root, path), 'utf8'))
	const scanPath = join(root, 'tests/axe/report.json')
	const scan = existsSync(scanPath)
		? JSON.parse(readFileSync(scanPath, 'utf8'))
		: null
	const rows = auditRows({
		criteria: read('docs/compliance/wcag-2.1-criteria.json'),
		scan,
		checklist: read('docs/compliance/wcag-manual-checks.json'),
	})
	const markdown = renderReport({
		version: read('package.json').version,
		date: (scan?.timestamp || new Date().toISOString()).slice(0, 10),
		sample: read('tests/e2e/a11y/sample.json'),
		rows,
		scanned: scan !== null,
		theme: scan?.theme,
	})
	writeFileSync(join(root, 'docs/compliance/wcag-2.1-aa-audit.md'), markdown)
	console.log('Wrote docs/compliance/wcag-2.1-aa-audit.md', summary(rows))
}
