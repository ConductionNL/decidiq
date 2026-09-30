#!/usr/bin/env node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WCAG sample check (platform-accessibility-audit-report, matrix row plt-22).
 * The accessibility audit evaluates a structured sample of pages. A page type
 * added to a manifest without a sample entry would never be evaluated, so this
 * check fails and names the page. It also fails on a sample entry that points
 * at a page no manifest declares any more.
 *
 * Usage: node scripts/wcag-sample-check.mjs
 *
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-001-the-evaluated-pages-are-a-structured-sample
 */
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Every page the manifests declare: the base manifest and each fragment.
 *
 * @param {string} root The repository root.
 * @return {Array<{id: string, type: string, route: string, file: string}>}
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-001-the-evaluated-pages-are-a-structured-sample
 */
export function manifestPages(root) {
	const files = ['src/manifest.json'].concat(
		readdirSync(join(root, 'src/manifest.d'))
			.filter((name) => name.endsWith('.json'))
			.sort()
			.map((name) => `src/manifest.d/${name}`),
	)
	return files.flatMap((file) =>
		(JSON.parse(readFileSync(join(root, file), 'utf8')).pages || []).map(
			(page) => ({
				id: page.id,
				type: page.type,
				route: page.route,
				file,
			}),
		),
	)
}

/**
 * What the sample misses: a page type with no entry (named by its first
 * page), and an entry whose page no manifest declares.
 *
 * @param {Array<{id: string, type: string, route: string, file: string}>} pages The declared pages.
 * @param {{pages?: Array, processPages?: Array}} sample The sample file.
 * @return {string[]} One line per problem; empty when the sample is complete.
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-001-the-evaluated-pages-are-a-structured-sample
 */
export function sampleProblems(pages, sample) {
	const entries = [...(sample.pages || []), ...(sample.processPages || [])]
	const byId = new Map(pages.map((page) => [page.id, page]))
	const sampledTypes = new Set(
		entries.map((entry) => byId.get(entry.pageId)?.type).filter(Boolean),
	)
	const problems = []
	const reported = new Set()
	for (const page of pages) {
		if (sampledTypes.has(page.type) || reported.has(page.type)) continue
		reported.add(page.type)
		problems.push(
			`Page ${page.id} (${page.route}, ${page.file}) is of type "${page.type}", which the WCAG sample does not cover. Add it to tests/e2e/a11y/sample.json.`,
		)
	}
	for (const entry of entries) {
		if (!byId.has(entry.pageId)) {
			problems.push(
				`Sample entry ${entry.id} names page ${entry.pageId}, which no manifest declares.`,
			)
		}
	}
	return problems
}

const self = fileURLToPath(import.meta.url)
if (process.argv[1] === self) {
	const root = join(dirname(self), '..')
	const sample = JSON.parse(
		readFileSync(join(root, 'tests/e2e/a11y/sample.json'), 'utf8'),
	)
	const problems = sampleProblems(manifestPages(root), sample)
	for (const line of problems) console.error(line)
	if (problems.length > 0) process.exit(1)
	console.log('WCAG sample covers every page type.')
}
