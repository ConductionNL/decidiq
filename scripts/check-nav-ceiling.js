#!/usr/bin/env node

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Nav-ceiling gate — enforces ADR-004's six-item top-level navigation.
 *
 * WHAT THIS CHECKS, AND WHY IT LIVES HERE
 * ---------------------------------------
 * ADR-004 (openspec/architecture/adr-004-information-architecture.md) fixed
 * a 6-item top-level navigation in May 2026 (change `ia-six-item-nav`).
 * Nothing enforced it mechanically. Over the following months, 22
 * independent `src/manifest.d/*.json` fragments each added their own
 * top-level menu entry — every one individually reasonable, none
 * coordinated — and the rendered navigation grew back to 44 entries with no
 * single commit that "broke" the ADR. `src/menu-layout.json` is the
 * declared mechanism for re-homing fragment entries (relocations /
 * removals / a lift into the settings section, consumed by
 * `@conduction/nextcloud-vue`'s `buildManifest`/`applyMenuLayout`), but it
 * is just data — nothing stopped fragment #23 from reintroducing the same
 * defect. This gate is the enforcement `ia-six-item-nav` never got.
 *
 * WHAT IT DOES
 * ------------
 * Rebuilds the effective top-level menu the same way `src/main.js`'s
 * `buildManifest` pipeline does at boot — base `src/manifest.json` + every
 * `src/manifest.d/*.json` fragment (sorted, mirroring
 * `require.context('./manifest.d/', false, /\.json$/)`) + `src/menu-
 * layout.json` — then asserts two invariants:
 *
 *   R1 ceiling             the merged menu's PRIMARY top-level entry count
 *                          (entries carrying no `section`, i.e. neither
 *                          "footer" nor "settings") is at most CEILING (6).
 *   R2 fragment-placement  every top-level entry a fragment declares is
 *                          "placed": self-scoped to `section: "footer"`/
 *                          `"settings"`, OR its id is a key in
 *                          `menu-layout.json#relocations`, OR listed in
 *                          `#removals`, OR listed in `#settingsSection`.
 *                          An unplaced entry fails EVEN IF the ceiling
 *                          happens to still be at or under 6 that day —
 *                          the defect is silent accumulation, not just the
 *                          eventual overflow.
 *
 * WHY THE MERGE LOGIC IS VENDORED, NOT IMPORTED
 * -----------------------------------------------
 * `scripts/check-integration-parity.js`'s header comment documents a
 * measured failure mode: a hydra-gates CI context is not guaranteed to
 * have run `npm ci`, so a check resolving `@conduction/nextcloud-vue` out
 * of `node_modules` can silently report a pass having checked nothing when
 * the module is absent. This gate is wired into the `frontend-checks` CI
 * tier, which DOES run its own `npm ci` — but vendoring a small, stable
 * subset of `@conduction/nextcloud-vue/src/utils/buildManifest.js`
 * (`mergeMenuItems`, `applyMenuRelocations`, `applyMenuRemovals`,
 * `applySettingsSection` — menu-only, no page/template merging) costs
 * little and buys the gate the ability to run correctly from a bare
 * checkout, a pre-commit hook, or a future hydra-gates adoption without a
 * behavioural change. If the canonical implementation's menu semantics
 * change, cross-check against that file directly.
 *
 * Exit codes:
 *   0 — the merged menu is at or under the ceiling AND every fragment
 *       top-level entry is placed
 *   1 — at least one violation (each printed as a `✗` line)
 */

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = process.argv[2] ? path.resolve(process.argv[2]) : process.cwd()

/**
 * ADR-004's agreed top-level navigation ceiling. "Adding a 7th top-level
 * requires an ADR amendment that demonstrates the new surface can't
 * reasonably live under an existing item" (ADR-004, "Top-level ceiling") —
 * raising this constant is the mechanical follow-through of that amendment,
 * never a standalone change.
 *
 * @type {number}
 */
const CEILING = 6

/**
 * Merge an array of incoming menu items into a target array, keyed by `id`.
 * Mirrors `mergeMenuItems` in `@conduction/nextcloud-vue/src/utils/
 * buildManifest.js` (menu-only; that function also handles arbitrary
 * fields, reproduced here as-is since the ceiling/placement checks only
 * need `id`, `section`, and `children`).
 *
 * @param {Array<object>} target The accumulated menu (mutated in place).
 * @param {Array<object>} incoming Menu items from the base or a fragment.
 * @return {void}
 */
function mergeMenuItems(target, incoming) {
	for (const item of incoming) {
		const existing = target.find((t) => t.id === item.id)
		if (!existing) {
			target.push(copyMenuItem(item))
			continue
		}
		mergeIntoExisting(existing, item)
	}
}

/**
 * The fields an already-merged entry takes from a later one when it has
 * none of its own.
 *
 * @type {string[]}
 */
const MERGED_MENU_FIELDS = [
	'label',
	'icon',
	'route',
	'order',
	'section',
	'featureFlag',
	'permission',
	'visibleIf',
	'href',
	'action',
]

/**
 * A first-seen entry as it is added: a copy, with its own children list.
 *
 * @param {object} item The incoming entry.
 * @return {object} The copy.
 */
function copyMenuItem(item) {
	return {
		...item,
		children: Array.isArray(item.children) ? [...item.children] : item.children,
	}
}

/**
 * Give an already-merged entry the fields it lacks from a later one.
 *
 * @param {object} existing The merged entry (mutated in place).
 * @param {object} item The incoming entry.
 * @return {void}
 */
function fillMissingFields(existing, item) {
	for (const key of MERGED_MENU_FIELDS) {
		if (existing[key] === undefined && item[key] !== undefined) {
			existing[key] = item[key]
		}
	}
}

/**
 * Merge a later entry into the one already merged under its id: missing
 * fields first, then its children, recursively.
 *
 * @param {object} existing The merged entry (mutated in place).
 * @param {object} item The incoming entry.
 * @return {void}
 */
function mergeIntoExisting(existing, item) {
	fillMissingFields(existing, item)
	if (Array.isArray(item.children) && item.children.length > 0) {
		if (!Array.isArray(existing.children)) {
			existing.children = []
		}
		mergeMenuItems(existing.children, item.children)
	}
}

/**
 * Re-home merged menu entries per `menu-layout.json#relocations`
 * (`{ sourceId: targetGroupId }`). Mirrors `applyMenuRelocations` in
 * `buildManifest.js`.
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Record<string, string>|undefined} relocations Source-id → target-group-id map.
 * @return {Array<object>} The menu with relocations applied.
 */
function applyMenuRelocations(menu, relocations) {
	if (!relocations || typeof relocations !== 'object') return menu
	for (let pass = 0; pass < 5; pass++) {
		const moves = detachMoves(menu, relocations)
		if (moves.length === 0) break
		moves.forEach((move) => placeMove(menu, move))
	}
	return menu.filter(isRenderable)
}

/**
 * Detach every relocated top-level entry and child from the menu, last
 * first, and return the moves in that order.
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Record<string, string>} relocations Source-id → target-group-id map.
 * @return {Array<{node: object, target: string}>} The detached entries and their targets.
 */
function detachMoves(menu, relocations) {
	const moves = []
	for (let i = menu.length - 1; i >= 0; i--) {
		const node = menu[i]
		const target = relocations[node.id]
		if (target && target !== node.id) {
			menu.splice(i, 1)
			moves.push({ node, target })
			continue
		}
		moves.push(...detachChildMoves(node, relocations))
	}
	return moves
}

/**
 * Detach a top-level entry's relocated children, last first. A leaf child
 * relocated onto its own parent stays where it is.
 *
 * @param {object} node The top-level entry (mutated in place).
 * @param {Record<string, string>} relocations Source-id → target-group-id map.
 * @return {Array<{node: object, target: string}>} The detached children and their targets.
 */
function detachChildMoves(node, relocations) {
	if (!Array.isArray(node.children)) return []
	const moves = []
	for (let j = node.children.length - 1; j >= 0; j--) {
		const child = node.children[j]
		const move = { node: child, target: relocations[child.id] }
		if (!isRealMove(move, node.id)) continue
		node.children.splice(j, 1)
		moves.push(move)
	}
	return moves
}

/**
 * Whether a child's relocation moves it: it has a target, and that target is
 * not its own parent (unless the child is a group, which still re-merges).
 *
 * @param {{node: object, target: string|undefined}} move The child and its target group id.
 * @param {string} parentId The id of the group it sits in.
 * @return {boolean} True when it is detached.
 */
function isRealMove(move, parentId) {
	if (!move.target) return false
	return !(move.target === parentId && !Array.isArray(move.node.children))
}

/**
 * Place one detached entry: into its target group (a group's children are
 * merged in, a leaf is merged as itself), or back at the end of the menu
 * when no such group exists.
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {{node: object, target: string}} move The entry and its target group id.
 * @return {void}
 */
function placeMove(menu, { node, target }) {
	const group = menu.find((m) => m.id === target)
	if (!group) {
		menu.push(node)
		return
	}
	if (!Array.isArray(group.children)) group.children = []
	mergeMenuItems(
		group.children,
		Array.isArray(node.children) ? node.children : [node],
	)
}

/**
 * Whether a menu entry renders: it has a click target or children.
 *
 * @param {object} m The menu entry.
 * @return {boolean} True when it renders.
 */
function isRenderable(m) {
	return Boolean(
		m.route
		|| m.href
		|| m.action
		|| (Array.isArray(m.children) && m.children.length > 0),
	)
}

/**
 * Remove individual menu entries by id per `menu-layout.json#removals`.
 * Mirrors `applyMenuRemovals` in `buildManifest.js`.
 *
 * @param {Array<object>} menu The merged menu.
 * @param {Array<string>|undefined} removals Menu-entry ids to drop.
 * @return {Array<object>} The menu without the removed entries.
 */
function applyMenuRemovals(menu, removals) {
	if (!Array.isArray(removals) || removals.length === 0) return menu
	return pruneNodes(menu, new Set(removals))
}

/**
 * Whether an entry was a group: it has at least one child.
 *
 * @param {object} n The entry.
 * @return {boolean} True for a group.
 */
function wasGroup(n) {
	return Array.isArray(n.children) && n.children.length > 0
}

/**
 * Whether an entry does something itself when clicked.
 *
 * @param {object} n The entry.
 * @return {boolean} True when it has a route, href or action.
 */
function isClickable(n) {
	return n.route !== undefined || n.href !== undefined || n.action !== undefined
}

/**
 * Whether a group lost all its children and has nothing of its own to click,
 * so it is dropped.
 *
 * @param {object} n The entry as it was.
 * @param {Array<object>} children Its children as they are now.
 * @return {boolean} True when it is dropped.
 */
function isEmptiedGroup(n, children) {
	return children.length === 0 && wasGroup(n) && !isClickable(n)
}

/**
 * The entries left once the removed ids are pruned, at every depth.
 *
 * @param {Array<object>} nodes The entries.
 * @param {Set<string>} drop The ids to remove.
 * @return {Array<object>} The entries kept.
 */
function pruneNodes(nodes, drop) {
	return nodes
		.map((n) => prunedNode(n, drop))
		.filter((n) => n !== null)
}

/**
 * One entry once the removed ids are pruned, or null when it goes. A removed
 * group is kept (its children may remain) and goes only when emptied.
 *
 * @param {object} n The entry.
 * @param {Set<string>} drop The ids to remove.
 * @return {object|null} The entry kept, or null.
 */
function prunedNode(n, drop) {
	if (drop.has(n.id) && !wasGroup(n)) return null
	if (!Array.isArray(n.children)) return n
	const children = pruneNodes(n.children, drop)
	if (isEmptiedGroup(n, children)) return null
	return { ...n, children }
}

/**
 * One settings-section lift: the ids to lift and the entries lifted so far,
 * in the order they were met. Never leaves this module.
 */
class SettingsLift {

	/**
	 * @param {Array<string>} settingsIds Entry ids to move to the foldout.
	 */
	constructor(settingsIds) {
		this.want = new Set(settingsIds)
		this.lifted = []
	}

	/**
	 * The entries left once the wanted ids are lifted out, at every depth.
	 *
	 * @param {Array<object>} nodes The entries.
	 * @return {Array<object>} The entries kept.
	 */
	strip(nodes) {
		return nodes.map((n) => this.stripped(n)).filter((n) => n !== null)
	}

	/**
	 * One entry once the wanted ids are lifted out, or null when it goes.
	 *
	 * @param {object} n The entry.
	 * @return {object|null} The entry kept, or null.
	 */
	stripped(n) {
		if (this.want.has(n.id)) {
			this.liftOut(n)
			return null
		}
		if (!Array.isArray(n.children)) return n
		const children = this.strip(n.children)
		if (isEmptiedGroup(n, children)) return null
		return { ...n, children }
	}

	/**
	 * Lift an entry into the foldout: the node WITHOUT its children.
	 *
	 * @param {object} n The entry.
	 * @return {void}
	 */
	liftOut(n) {
		const leaf = { ...n }
		delete leaf.children
		this.lifted.push({ ...leaf, section: 'settings' })
	}

}

/**
 * Promote entries listed in `menu-layout.json#settingsSection` into the
 * settings foldout (tagged `section: "settings"`, flattened, appended to
 * the top level). Mirrors `applySettingsSection` in `buildManifest.js`.
 *
 * @param {Array<object>} menu The merged + relocated + pruned menu.
 * @param {Array<string>|undefined} settingsIds Entry ids to move to the foldout.
 * @return {Array<object>} The menu with the settings entries lifted out.
 */
function applySettingsSection(menu, settingsIds) {
	if (!Array.isArray(settingsIds) || settingsIds.length === 0) return menu
	const lift = new SettingsLift(settingsIds)
	const remaining = lift.strip(menu)
	return [...remaining, ...lift.lifted]
}

/**
 * Build the effective top-level menu from a base manifest, its fragments,
 * and its menu-layout — the menu-only subset of `buildManifest` +
 * `applyMenuLayout`.
 *
 * @param {{menu?: Array<object>}} base The base manifest.
 * @param {Array<{menu?: Array<object>}>} fragments Fragment objects.
 * @param {{relocations?: object, removals?: string[], settingsSection?: string[]}} menuLayout
 * @return {Array<object>} The effective merged + laid-out top-level menu.
 */
function buildEffectiveMenu(base, fragments = [], menuLayout = {}) {
	const menu = []
	mergeMenuItems(menu, (base && base.menu) || [])
	for (const frag of fragments) {
		if (frag && Array.isArray(frag.menu)) {
			mergeMenuItems(menu, frag.menu)
		}
	}
	let out = applyMenuRelocations(menu, menuLayout.relocations)
	out = applyMenuRemovals(out, menuLayout.removals)
	out = applySettingsSection(out, menuLayout.settingsSection)
	return out
}

/**
 * R1 — assert the merged menu's primary top-level entry count is at or
 * under the ceiling. Footer (`section: "footer"`) and settings
 * (`section: "settings"`) entries are excluded from the primary count —
 * neither renders in the main scrollable nav ADR-004 caps.
 *
 * @param {Array<object>} effectiveMenu The merged + laid-out top-level menu.
 * @param {number} [ceiling] The ceiling to enforce.
 * @return {{failures: string[], primary: object[], footer: object[], settings: object[]}}
 */
function evaluateCeiling(effectiveMenu, ceiling = CEILING) {
	const primary = effectiveMenu.filter(
		(m) => m.section !== 'footer' && m.section !== 'settings',
	)
	const footer = effectiveMenu.filter((m) => m.section === 'footer')
	const settings = effectiveMenu.filter((m) => m.section === 'settings')
	const failures = []
	if (primary.length > ceiling) {
		failures.push(
			`✗ [R1 nav-ceiling] primary top-level nav has ${primary.length} entries `
				+ `(ceiling: ${ceiling}) — ADR-004 requires an ADR amendment before a 7th `
				+ `top-level item. Entries: ${primary.map((m) => m.id).join(', ')}`,
		)
	}
	return { failures, primary, footer, settings }
}

/**
 * R2 — assert every fragment top-level menu entry is placed: self-scoped
 * to `section: "footer"`/`"settings"`, or covered by a `menu-layout.json`
 * relocation, removal, or settingsSection entry.
 *
 * @param {Array<{file?: string, menu?: Array<object>}>} fragments Fragment
 *   objects; `file` (relative path) is optional and used only for
 *   diagnostics.
 * @param {{relocations?: object, removals?: string[], settingsSection?: string[]}} menuLayout
 * @return {{failures: string[], checked: number}}
 */
function evaluateFragmentPlacement(fragments, menuLayout = {}) {
	const placements = placementSets(menuLayout)
	const failures = []
	let checked = 0
	for (const frag of fragments) {
		const items = fragmentMenu(frag)
		checked += items.length
		for (const item of unplacedItems(items, placements)) {
			failures.push(placementFailure(fragmentLabel(frag), item))
		}
	}
	return { failures, checked }
}

/**
 * The name a fragment is reported under.
 *
 * @param {{file?: string}|null} frag The fragment.
 * @return {string} Its file, or "(fragment)".
 */
function fragmentLabel(frag) {
	return frag && frag.file ? frag.file : '(fragment)'
}

/**
 * A fragment's top-level menu entries, or none when it has no menu list.
 *
 * @param {{menu?: Array<object>}|null} frag The fragment.
 * @return {Array<object>} The entries.
 */
function fragmentMenu(frag) {
	return frag && Array.isArray(frag.menu) ? frag.menu : []
}

/**
 * The entries no placement covers, in order.
 *
 * @param {Array<object>} items Fragment menu entries.
 * @param {Array<Set<string>>} placements The placing id sets.
 * @return {Array<object>} The unplaced entries.
 */
function unplacedItems(items, placements) {
	return items.filter((item) => !isPlaced(item, placements))
}

/**
 * The menu-layout id sets that place an entry: relocations, removals and
 * the settings section.
 *
 * @param {{relocations?: object, removals?: string[], settingsSection?: string[]}} menuLayout The menu layout.
 * @return {Array<Set<string>>} The three id sets.
 */
function placementSets(menuLayout) {
	return [
		new Set(Object.keys(menuLayout.relocations || {})),
		new Set(menuLayout.removals || []),
		new Set(menuLayout.settingsSection || []),
	]
}

/**
 * Whether a fragment's top-level entry is placed: self-scoped to the footer
 * or settings, or named in one of the menu-layout id sets.
 *
 * @param {object} item The fragment's top-level menu entry.
 * @param {Array<Set<string>>} placements The menu-layout id sets.
 * @return {boolean} True when placed.
 */
function isPlaced(item, placements) {
	const selfScoped = item.section === 'footer' || item.section === 'settings'
	return selfScoped || placements.some((ids) => ids.has(item.id))
}

/**
 * The R2 failure line for an unplaced fragment entry.
 *
 * @param {string} label The fragment file, or '(fragment)'.
 * @param {object} item The unplaced menu entry.
 * @return {string} The failure line.
 */
function placementFailure(label, item) {
	return (
		`✗ [R2 fragment-placement] ${label} declares top-level menu entry `
		+ `"${item.id}" with no placement in menu-layout.json (no relocation, `
		+ `removal, or settingsSection entry) and no self-declared `
		+ `section: "footer"/"settings" — it will surface as a new primary `
		+ `nav item with no deliberate placement decision.`
	)
}

/**
 * Load the manifest.d fragment files, sorted to mirror webpack's
 * `require.context('./manifest.d/', false, /\.json$/).keys().sort()`.
 *
 * @param {string} manifestDDir Absolute path to `src/manifest.d`.
 * @return {Array<{file: string, menu: Array<object>}>} Loaded fragments.
 */
function loadFragments(manifestDDir) {
	if (!fs.existsSync(manifestDDir)) return []
	const files = fs
		.readdirSync(manifestDDir)
		.filter((f) => f.endsWith('.json'))
		.sort()
	return files.map((f) => {
		const rel = path.join('src', 'manifest.d', f)
		try {
			const doc = JSON.parse(
				fs.readFileSync(path.join(manifestDDir, f), 'utf8'),
			)
			return { file: rel, menu: Array.isArray(doc.menu) ? doc.menu : [] }
		} catch (e) {
			return { file: rel, menu: [], parseError: e.message }
		}
	})
}

/**
 * Print a gate error and stop with exit code 1.
 *
 * @param {string} message The error line.
 * @return {void}
 */
function fail(message) {
	console.error(message)
	process.exit(1)
}

/**
 * Parse a JSON file.
 *
 * @param {string} file Absolute path.
 * @return {object|Array|string|number|boolean|null} The parsed document.
 */
function readJson(file) {
	return JSON.parse(fs.readFileSync(file, 'utf8'))
}

/**
 * Load the base manifest; a missing or unparsable one stops the gate,
 * because nothing could be checked.
 *
 * @param {string} manifestPath Absolute path to `src/manifest.json`.
 * @return {object} The base manifest.
 */
function loadBase(manifestPath) {
	if (!fs.existsSync(manifestPath)) {
		fail(
			`✗ nav-ceiling: base manifest not found at ${manifestPath} — nothing was checked. Refusing to report a pass.`,
		)
	}
	try {
		return readJson(manifestPath)
	} catch (e) {
		return fail(
			`✗ nav-ceiling: ${manifestPath} is not valid JSON (${e.message})`,
		)
	}
}

/**
 * The menu-layout parts the gate reads, each defaulted when absent or of
 * the wrong type.
 *
 * @param {object} doc The parsed `menu-layout.json`.
 * @return {{relocations: object, removals: string[], settingsSection: string[]}}
 */
function menuLayoutOf(doc) {
	return {
		relocations:
			doc.relocations && typeof doc.relocations === 'object'
				? doc.relocations
				: {},
		removals: Array.isArray(doc.removals) ? doc.removals : [],
		settingsSection: Array.isArray(doc.settingsSection)
			? doc.settingsSection
			: [],
	}
}

/**
 * Load `menu-layout.json`, or an empty layout when there is none; an
 * unreadable one stops the gate.
 *
 * @param {string} menuLayoutPath Absolute path to `src/menu-layout.json`.
 * @return {object} The menu layout.
 */
function loadMenuLayout(menuLayoutPath) {
	if (!fs.existsSync(menuLayoutPath)) {
		return {}
	}
	try {
		return menuLayoutOf(readJson(menuLayoutPath))
	} catch (e) {
		return fail(
			`✗ nav-ceiling: ${menuLayoutPath} is not valid JSON (${e.message})`,
		)
	}
}

/**
 * Run both rules over the loaded inputs.
 *
 * @param {{base: object, fragments: Array<object>, menuLayout: object}} inputs The loaded repo files.
 * @return {{failures: string[], scope: string}} Every violation, and what was checked.
 */
function evaluateAll(inputs) {
	const { base, fragments, menuLayout } = inputs
	const parseErrors = fragments.filter((f) => f.parseError)
	for (const f of parseErrors) {
		console.error(`✗ nav-ceiling: ${f.file} is not valid JSON (${f.parseError})`)
	}

	const effectiveMenu = buildEffectiveMenu(base, fragments, menuLayout)
	const ceilingResult = evaluateCeiling(effectiveMenu, CEILING)
	const placementResult = evaluateFragmentPlacement(fragments, menuLayout)

	const failures = [
		...parseErrors.map((f) => `✗ [parse] ${f.file}`),
		...ceilingResult.failures,
		...placementResult.failures,
	]
	const scope =
		`${fragments.length} fragment(s), ${placementResult.checked} fragment top-level menu entr(y/ies), `
		+ `${ceilingResult.primary.length} primary / ${ceilingResult.footer.length} footer / `
		+ `${ceilingResult.settings.length} settings top-level entries in the merged menu`
	return { failures, scope }
}

/**
 * Print the verdict and exit: 0 when nothing failed, 1 otherwise.
 *
 * @param {{failures: string[], scope: string}} result The evaluation.
 * @return {void}
 */
function report(result) {
	const { failures, scope } = result
	if (failures.length === 0) {
		console.log(
			`✓ nav-ceiling: ${scope} — at or under the ADR-004 ceiling (${CEILING}), every fragment entry placed.`,
		)
		process.exit(0)
	}

	console.error(
		`nav-ceiling gate FAILED — ${failures.length} violation(s) over ${scope}:`,
	)
	for (const f of failures) {
		console.error(f)
	}
	process.exit(1)
}

/**
 * Run the gate against the real repo files and report.
 *
 * @return {void}
 */
function main() {
	const src = path.join(REPO_ROOT, 'src')
	const base = loadBase(path.join(src, 'manifest.json'))
	const menuLayout = loadMenuLayout(path.join(src, 'menu-layout.json'))
	const fragments = loadFragments(path.join(src, 'manifest.d'))
	report(evaluateAll({ base, fragments, menuLayout }))
}

if (require.main === module) {
	main()
}

module.exports = {
	mergeMenuItems,
	applyMenuRelocations,
	applyMenuRemovals,
	applySettingsSection,
	buildEffectiveMenu,
	evaluateCeiling,
	evaluateFragmentPlacement,
	loadFragments,
	CEILING,
}
