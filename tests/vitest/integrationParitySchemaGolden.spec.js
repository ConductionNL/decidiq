/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `scripts/check-integration-parity.js` over fixture
 * repositories aimed at how it reads schema declarations and provider faces:
 * a slug declared in two files, a slug that differs from its key, files that
 * do not parse or declare no schemas, and provider getters that return empty
 * literals. The script runs as a child process, so the exit code and both
 * output streams are part of the snapshot.
 *
 * @spec exclude test of the gate-24 parity script, which has no spec of its own
 */
import { spawnSync } from 'child_process'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { afterAll, describe, expect, it } from 'vitest'

const SCRIPT = path.resolve(__dirname, '../../scripts/check-integration-parity.js')
const roots = []

/**
 * Write a fixture repository and return its root.
 *
 * @param {Object<string, string>} files Relative path to file contents.
 *
 * @return {string} The fixture root.
 */
function fixture(files) {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'decidiq-parity-schema-'))
	roots.push(root)
	for (const [rel, text] of Object.entries(files)) {
		fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true })
		fs.writeFileSync(path.join(root, rel), text)
	}
	return root
}

/**
 * Run the parity script over a fixture, with the fixture root made neutral.
 *
 * @param {string} root The fixture root.
 *
 * @return {{status: number, stdout: string, stderr: string}} The run.
 */
function run(root) {
	const p = spawnSync(process.execPath, [SCRIPT, root], { encoding: 'utf8' })
	const clean = (text) => text.split(root).join('<root>')
	return { status: p.status, stdout: clean(p.stdout), stderr: clean(p.stderr) }
}

const LEAVES = `<?php
class Leaves {
	public function all() {
		return [
			new LeafDescriptor(
				id: 'alpha',
				kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
				renderMode: LeafDescriptor::RENDER_MODE_COMPONENT,
				label: 'Alpha',
				surfaces: ['tab', 'widget'],
			),
		];
	}
}
`

const SCHEMA_FILES = {
	'lib/Settings/a.json': JSON.stringify({
		components: {
			schemas: { plan: { slug: 'plan', properties: { title: {} } } },
		},
	}),
	'lib/Settings/b.json': JSON.stringify({
		components: {
			schemas: {
				other: { slug: 'plan', properties: { date: {} } },
				keyed: { properties: { name: {} } },
				bare: { slug: 5, properties: { size: {} } },
				flat: { properties: 'x' },
				nul: null,
			},
		},
	}),
	'lib/Settings/c.json': '0',
	'lib/Settings/d.json': '"x"',
	'lib/Settings/e.json': 'not json',
	'lib/Settings/f.json': JSON.stringify({ components: { schemas: [] } }),
	'lib/Settings/g.json': JSON.stringify({ components: { schemas: null } }),
	'lib/Settings/h.json': JSON.stringify({ components: 0 }),
}

/**
 * One registration of the alpha leaf with an offline config.
 *
 * @param {string} offline The offlineConfig object literal.
 *
 * @return {string} The JS source.
 */
function alpha(offline) {
	return `import { registerIntegration } from '@conduction/nextcloud-vue'
registerIntegration({
	id: 'alpha',
	label: 'Alpha',
	surfaces: ['widget', 'tab'],
	tab: AlphaTab,
	widget: AlphaWidget,
	offlineConfig: ${offline},
})
`
}

describe('integration parity schema and provider reading', () => {
	afterAll(() => {
		for (const root of roots) {
			fs.rmSync(root, { recursive: true, force: true })
		}
	})

	it('joins the properties of a slug declared in two files', () => {
		expect(
			run(
				fixture({
					'lib/Leaf/Leaves.php': LEAVES,
					...SCHEMA_FILES,
					'src/alpha.js': alpha(
						"{ plannedSchema: 'plan', referenceSchema: 'keyed', titleField: 'title', dateField: 'date' }",
					),
				}),
			),
		).toMatchSnapshot()
	})

	it('reads a schema under its slug, not its key', () => {
		expect(
			run(
				fixture({
					'lib/Leaf/Leaves.php': LEAVES,
					...SCHEMA_FILES,
					'src/alpha.js': alpha(
						"{ plannedSchema: 'other', referenceSchema: 'bare', titleField: 'size', dateField: 'date' }",
					),
				}),
			),
		).toMatchSnapshot()
	})

	it('keeps provider getters that return empty literals', () => {
		expect(
			run(
				fixture({
					'lib/Provider/EmptyProvider.php': `<?php
class EmptyProvider extends AbstractIntegrationProvider {
	public function getId(): string { return 'empty'; }
	public function getLabel(): string { return ''; }
	public function getGroup(): string { return '0'; }
}
`,
					'lib/Provider/BlankIdProvider.php': `<?php
class BlankIdProvider extends AbstractIntegrationProvider {
	public function getId(): string { return ''; }
}
`,
					'src/empty.js': `const empty = { id: 'empty', label: 'Shown', group: '1', tab: T, widget: W }
window.OCA.OpenRegister.integrations.register(empty)
const blank = { id: '', label: 'Blank', tab: T, widget: W }
window.OCA.OpenRegister.integrations.register(blank)
`,
				}),
			),
		).toMatchSnapshot()
	})
})
