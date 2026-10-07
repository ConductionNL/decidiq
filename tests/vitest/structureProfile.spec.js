/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The structure profiles: one manifest, a simple menu and the full one.
 *
 * Every assertion here builds the menu with the library's REAL
 * `buildManifest`, reached by its subpath so the spec does not load the
 * component barrel. The profile leans on three of its behaviours (first
 * definition of a key wins, a missing `relocations` leaves the menu alone, a
 * removal drops a leaf), and a fake would only prove that the fake agrees
 * with this file.
 *
 * What has to stay true:
 *   - the full profile is exactly what it was before profiles existed;
 *   - the simple menu is the eight entries of the design, in order, under
 *     three captions;
 *   - nothing is lost: every entry the full menu offers is in the simple menu
 *     or its settings, or a page the simple menu opens links to it.
 *
 * `scripts/check-nav-ceiling.js` and the gates read `src/menu-layout.json`
 * only, so the no-loss rule for the simple file is held here and nowhere else.
 *
 * @spec openspec/changes/simple-structure-profile/specs/app-navigation/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { validateManifest } from '@conduction/nextcloud-vue/src/utils/validateManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { MODE_LABELS } from '../../src/config/modeLabels.js'
import { saveMenuStructure } from '../../src/services/menuStructureSetting.js'
import {
	applyPageDefaults,
	applyPageOverlay,
	buildProfiledManifest,
	navTheming,
	resolveStructureProfile,
	STRUCTURE_FULL,
	STRUCTURE_SETTING,
	STRUCTURE_SIMPLE,
} from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const fullFile = readJson('src', 'menu-layout.json')
const simpleFile = readJson('src', 'menu-layout.simple.json')
const iconsSource = read('src', 'icons.js')
const mainSource = read('src', 'main.js')
const dutch = readJson('l10n', 'nl.json').translations

/** A fresh manifest each time: buildManifest merges into what it is given. */
const manifest = () => readJson('src', 'manifest.json')
function build(file) {
	return buildProfiledManifest(buildManifest, manifest(), fragments, file)
}

/**
 * Every entry of a built menu, children included.
 *
 * @param {Array<object>} menu The built menu.
 * @return {Array<object>} The flat list.
 */
function flat(menu) {
	return menu.flatMap((entry) => [entry, ...flat(entry.children || [])])
}

/**
 * The top-level entries of one section, in the order the navigation draws them.
 *
 * @param {Array<object>} menu The built menu.
 * @param {string} name `main`, `footer` or `settings`.
 * @return {Array<object>} The entries, by `order`.
 */
function section(menu, name) {
	return menu
		.filter((entry) => (entry.section || 'main') === name)
		.sort((a, b) => (a.order ?? Infinity) - (b.order ?? Infinity))
}

/**
 * The pages a reader reaches from one page in a single step.
 *
 * An index page links with a header action (`handler: "navigate"`), a
 * dashboard with a tile (`content.route.name`). `open-page` is the detail
 * page's word for the same thing.
 *
 * @param {object} page A built page.
 * @return {Array<string>} The page ids it links to.
 */
function linksOf(page) {
	const out = []
	for (const action of page.config?.headerActions ?? []) {
		if (action.handler === 'navigate' && action.route) {
			out.push(action.route)
		}
		if (action.type === 'open-page' && action.target) {
			out.push(action.target)
		}
	}
	for (const widget of page.config?.widgets ?? []) {
		if (widget.content?.route?.name) {
			out.push(widget.content.route.name)
		}
	}
	return out
}

describe('the full profile', () => {
	it('is exactly what buildManifest made before profiles existed', () => {
		const before = buildManifest(manifest(), fragments, fullFile)
		// The one difference: every index page that did not choose keeps the
		// plain header row it had before nextcloud-vue 2.62.0 gave every
		// header a sort and filter control (menu-layout.json pageDefaults).
		expect(build(fullFile)).toEqual(applyPageDefaults(before, fullFile.pageDefaults))
		expect(fullFile.pageDefaults).toEqual({ index: { headerFilters: false } })
		expect(simpleFile.pageDefaults).toBeUndefined()
		const after = build(fullFile)
		for (const page of before.pages) {
			const now = after.pages.find((item) => item.id === page.id)
			const held = page.type === 'index' && page.config?.headerFilters === undefined
			expect(now, page.id).toEqual(held ? { ...page, config: { ...page.config, headerFilters: false } } : page)
		}
	})

	it('still counts 44 entries: 24 main, 4 footer, 16 settings', () => {
		const menu = build(fullFile).menu
		const count = (name) =>
			flat(menu.filter((entry) => (entry.section || 'main') === name)).length
		expect(flat(menu)).toHaveLength(44)
		expect(count('main')).toBe(24)
		expect(count('footer')).toBe(4)
		expect(count('settings')).toBe(16)
	})

	it('still shows six entries at the top, the ones ADR-004 names', () => {
		expect(
			section(build(fullFile).menu, 'main').map((entry) => entry.id),
		).toEqual([
			'Dashboard',
			'Meetings',
			'Decisions',
			'ActionItems',
			'GovernanceBodies',
			'Registers',
		])
	})

	it('has no page overlay, so no page can drift from the manifest', () => {
		expect(fullFile.pages).toBeUndefined()
		expect(fullFile.menu).toBeUndefined()
	})
})

describe('the simple profile', () => {
	const built = build(simpleFile)
	const main = section(built.menu, 'main')

	it('shows eight entries under two captions, in the order of the design', () => {
		// DcDashboard: Dashboard and My actions sit at the top with no caption
		// above them; the board's first caption is Besluitvorming.
		expect(main.map((entry) => entry.id)).toEqual([
			'Dashboard',
			'ActionItems',
			'DecisionMakingCaption',
			'Proposals',
			'Meetings',
			'Decisions',
			'Commitments',
			'OrganisationCaption',
			'GovernanceBodies',
			'Registers',
		])
		const captions = main.filter((entry) => entry.type === 'caption')
		expect(captions.map((entry) => entry.label)).toEqual([
			'Decision making',
			'Your organisation',
		])
		expect(main.filter((entry) => entry.type !== 'caption')).toHaveLength(8)
	})

	it('reads in Dutch as the design writes it', () => {
		expect(main.map((entry) => dutch[entry.label])).toEqual([
			'Dashboard',
			'Mijn acties',
			'Besluitvorming',
			'Voorstellen',
			'Vergaderingen',
			'Besluiten',
			'Toezeggingen',
			'Organisatie',
			'Organen en leden',
			'Registers',
		])
	})

	it('ends the navigation in Settings and Help, as the board does, and counts My actions', () => {
		// DcDashboard: the footer holds Instellingen and Hulp en uitleg; the
		// rest of the footer moves into the settings foldout. The full
		// profile declares no footer and keeps its own.
		expect(simpleFile.nav.footer).toEqual(['settings', 'help'])
		expect(simpleFile.nav.help.href).toBe('https://decidiq.conduction.nl')
		expect(dutch[simpleFile.nav.help.label]).toBe('Hulp en uitleg')
		expect(fullFile.nav).toBeUndefined()
		expect(main.find((entry) => entry.id === 'ActionItems').count).toMatchObject({
			register: 'decidiq',
			schema: 'action-item',
			filter: { assignee: '@me' },
		})
	})

	it('is flat: no entry holds another', () => {
		for (const entry of built.menu) {
			expect(entry.children ?? [], entry.id).toEqual([])
		}
	})

	it('keeps relocations out of the file, because any relocation step drops the captions', () => {
		// The library's relocation step ends by filtering out every entry with
		// no route, href, action or children. That is a caption. `{}` is enough
		// to run it, so the key has to be absent.
		expect(Object.hasOwn(simpleFile, 'relocations')).toBe(false)
		const withRelocations = build({ ...simpleFile, relocations: {} })
		expect(
			withRelocations.menu.filter((entry) => entry.type === 'caption'),
			'the library now keeps captions through relocations; the note in the profile file is stale',
		).toEqual([])
	})

	it('gives every entry a label, an icon the app registers and a page that exists', () => {
		const pageIds = new Set(built.pages.map((page) => page.id))
		for (const entry of main.filter((item) => item.type !== 'caption')) {
			expect(entry.label, entry.id).toBeTruthy()
			expect(entry.icon, entry.id).toBeTruthy()
			expect(
				iconsSource,
				`${entry.id} names an icon src/icons.js lacks`,
			).toContain(`\n\t${entry.icon},\n`)
			expect(pageIds.has(entry.route), `${entry.id} -> ${entry.route}`).toBe(
				true,
			)
		}
	})

	it('takes labels, icons and routes from the manifest and only the order from the profile', () => {
		const source = [
			...manifest().menu,
			...fragments.flatMap((fragment) => fragment.menu ?? []),
		]
		for (const id of ['Dashboard', 'Meetings', 'Decisions', 'Commitments']) {
			const original = source.find((entry) => entry.id === id)
			const shown = main.find((entry) => entry.id === id)
			expect(shown.label, id).toBe(original.label)
			expect(shown.route, id).toBe(original.route)
		}
		// Three entries take the board's line icon (DcDashboard): a house, an
		// inbox and a calendar. The rest keep the manifest's icon.
		expect(main.find((entry) => entry.id === 'Dashboard').icon).toBe('HomeOutline')
		expect(main.find((entry) => entry.id === 'ActionItems').icon).toBe('InboxOutline')
		expect(main.find((entry) => entry.id === 'Meetings').icon).toBe('CalendarBlankOutline')
		for (const id of ['Decisions', 'Commitments']) {
			const original = source.find((entry) => entry.id === id)
			expect(main.find((entry) => entry.id === id).icon, id).toBe(original.icon)
		}
		// Two entries are worded for the simple menu. Their page is the same.
		expect(main.find((entry) => entry.id === 'ActionItems')).toMatchObject({
			label: 'My actions',
			route: 'ActionItems',
		})
		expect(main.find((entry) => entry.id === 'GovernanceBodies')).toMatchObject({
			label: 'Bodies and members',
			route: 'GovernanceBodies',
		})
	})

	it('opens Proposals on a page of its own, so it does not light up with Decisions', () => {
		// The navigation decides "active" by route name. Two entries on one
		// route would both be marked as the page somebody is on.
		const routes = main
			.filter((entry) => entry.type !== 'caption')
			.map((entry) => entry.route)
		expect(new Set(routes).size).toBe(routes.length)

		const proposals = built.pages.find((page) => page.id === 'Motions')
		expect(main.find((entry) => entry.id === 'Proposals').route).toBe('Motions')
		expect(proposals.config.schema).toBe('decision')
	})

	it('gives Registers a page: one tile for each register, each opening a list that exists', () => {
		const registers = main.find((entry) => entry.id === 'Registers')
		expect(registers.route).toBe('RegistersHub')
		// The manifest's own entry has no route: it is a group shell there.
		expect(
			manifest().menu.find((entry) => entry.id === 'Registers').route,
		).toBeUndefined()

		const hub = built.pages.find((page) => page.id === 'RegistersHub')
		const pagesById = new Map(built.pages.map((page) => [page.id, page]))
		expect(
			hub.config.widgets.map((widget) => widget.content.route.name),
		).toEqual([
			'GoverningDocuments',
			'AuthorityDelegations',
			'ConfidentialityRestrictions',
			'DeclaredGifts',
			'AncillaryPositions',
			'ProxyAuthorizations',
			'MemberOnboarding',
			'MemberOffboarding',
			'AuditStatements',
			'Goals',
			'ArchiveDashboard',
		])
		for (const widget of hub.config.widgets) {
			const target = pagesById.get(widget.content.route.name)
			expect(target, widget.id).toBeDefined()
			// A tile counts the records of the list it opens, with no filter:
			// the count on the tile is the number of rows behind it.
			expect(widget.content.source.filter, widget.id).toBeUndefined()
			if (target.type === 'index') {
				expect(widget.content.source.schema, widget.id).toBe(
					target.config.schema,
				)
			}
			expect(iconsSource, widget.id).toContain(`\n\t${widget.content.icon},\n`)
			expect(dutch[widget.content.label], widget.content.label).toBeTruthy()
		}

		// Every tile has a place on the grid, and no two share a cell.
		const cells = new Set()
		for (const item of hub.config.layout) {
			expect(
				hub.config.widgets.some((widget) => widget.id === item.widgetId),
				item.widgetId,
			).toBe(true)
			for (let x = item.gridX; x < item.gridX + item.gridWidth; x++) {
				for (let y = item.gridY; y < item.gridY + item.gridHeight; y++) {
					expect(
						cells.has(`${x}:${y}`),
						`${item.widgetId} at ${x}:${y}`,
					).toBe(false)
					cells.add(`${x}:${y}`)
				}
			}
			expect(item.gridX + item.gridWidth).toBeLessThanOrEqual(12)
		}
		expect(hub.config.layout).toHaveLength(hub.config.widgets.length)
	})

	it('keeps settings and the footer exactly as the full profile has them', () => {
		const full = build(fullFile).menu
		const ids = (menu, name) => section(menu, name).map((entry) => entry.id)
		expect(ids(built.menu, 'settings')).toEqual(ids(full, 'settings'))
		expect(ids(built.menu, 'footer')).toEqual(ids(full, 'footer'))
		expect(simpleFile.settingsSection).toEqual(fullFile.settingsSection)
	})

	it('removes exactly the entries the full profile nests, except the one it shows itself', () => {
		// The full profile removes nothing and nests eighteen. Without a
		// relocation step those would sit at the top level here.
		expect(fullFile.removals).toEqual([])
		const nested = Object.keys(fullFile.relocations)
		expect([...simpleFile.removals].sort()).toEqual(
			nested.filter((id) => id !== 'Commitments').sort(),
		)
		// Every removal names an entry that exists (the drift
		// menuLayoutIsCurrent.spec.js catches for the full file).
		const declared = new Set(
			[
				...manifest().menu,
				...fragments.flatMap((fragment) => fragment.menu ?? []),
			].map((entry) => entry.id),
		)
		for (const id of simpleFile.removals) {
			expect(declared.has(id), id).toBe(true)
		}
	})

	it('loses nothing: every entry of the full menu is shown, or a page the simple menu opens links to it', () => {
		const shown = new Set(flat(built.menu).map((entry) => entry.id))
		const openedPages = new Set(
			flat(built.menu)
				.map((entry) => entry.route)
				.filter(Boolean),
		)
		const linked = new Set()
		for (const page of built.pages) {
			if (openedPages.has(page.id)) {
				linksOf(page).forEach((id) => linked.add(id))
			}
		}
		const lost = flat(build(fullFile).menu)
			.filter((entry) => !shown.has(entry.id))
			.filter((entry) => !linked.has(entry.route))
			.map((entry) => entry.id)
		expect(lost).toEqual([])

		// The control: the seventeen entries this profile takes out are really
		// out, so the check above passes on the links and not on the menu.
		for (const id of simpleFile.removals) {
			expect(shown.has(id), id).toBe(false)
		}
		expect(simpleFile.removals).toHaveLength(17)
	})

	it('reaches the consultation lists and the urgent decisions from Proposals', () => {
		const proposals = built.pages.find((page) => page.id === 'Motions')
		expect(linksOf(proposals)).toEqual([
			'GovernanceConsultations',
			'WorksCouncilConsultations',
			'Consultations',
			'UrgentDecisions',
		])
		expect(linksOf(built.pages.find((page) => page.id === 'Meetings'))).toEqual([
			'PlannedAgenda',
			'PlanningCycles',
		])
		expect(
			linksOf(built.pages.find((page) => page.id === 'GovernanceBodies')),
		).toEqual(['PositionHolds'])
	})

	it('adds the links in the simple profile only, with a label in Dutch and an icon the app registers', () => {
		const pageIds = new Set(built.pages.map((page) => page.id))
		const fullPages = new Map(
			build(fullFile).pages.map((page) => [page.id, page]),
		)
		// The three list pages that gain links. The decision page and the
		// motion page have overlays of their own kind (simpleDecisionPage.spec.js).
		const linkOverlays = simpleFile.pages.filter((overlay) =>
			['Motions', 'Meetings', 'GovernanceBodies'].includes(overlay.id),
		)
		expect(linkOverlays).toHaveLength(3)
		for (const overlay of linkOverlays) {
			expect(
				fullPages.get(overlay.id).config.headerActions ?? [],
				overlay.id,
			).toEqual([])
			const ids = overlay.configAppend.headerActions.map((action) => action.id)
			expect(new Set(ids).size, overlay.id).toBe(ids.length)
			for (const action of overlay.configAppend.headerActions) {
				expect(action.handler, action.id).toBe('navigate')
				expect(pageIds.has(action.route), action.id).toBe(true)
				expect(iconsSource, action.id).toContain(`\n\t${action.icon},\n`)
				expect(dutch[action.label], action.label).toBeTruthy()
				// The index page keeps these ids for its own buttons and drops
				// a declared action that uses one.
				expect(
					['refresh', 'import', 'export', 'copy', 'delete'],
					action.id,
				).not.toContain(action.id)
			}
		}
	})

	it('builds the same 103 pages as the full profile, so every route stays', () => {
		const ids = (source) => source.pages.map((page) => page.id)
		expect(ids(built)).toEqual(ids(build(fullFile)))
		expect(built.pages).toHaveLength(103)
	})

	it('is a manifest the library accepts, captions and links included', () => {
		// `npm run check:manifest` reads src/manifest.json only. The built
		// simple manifest is what the browser gets, so it is checked here.
		const result = validateManifest(JSON.parse(JSON.stringify(built)))
		expect(result.errors).toEqual([])
		expect(result.valid).toBe(true)
		// The control: the validator does refuse a manifest that is wrong.
		const broken = JSON.parse(JSON.stringify(built))
		broken.pages[0].type = 'no-such-page-type'
		expect(validateManifest(broken).valid).toBe(false)
	})
})

describe('the organisation mode', () => {
	// src/App.vue rewords a label per organisation mode before translating it
	// (MODE_LABELS). A label the simple menu introduces must not collide with
	// a key of that map by accident, and must follow the mode where it names
	// the same thing.
	const main = section(build(simpleFile).menu, 'main')
	const labelIn = (mode, label) => (MODE_LABELS[mode] || {})[label] || label

	it('leaves the caption alone in every mode', () => {
		const caption = main.find((entry) => entry.id === 'OrganisationCaption')
		for (const mode of Object.keys(MODE_LABELS)) {
			expect(labelIn(mode, caption.label), mode).toBe('Your organisation')
		}
		expect(dutch['Your organisation']).toBe('Organisatie')
	})

	it('calls the bodies entry a board for a company and teams for a team', () => {
		const bodies = main.find((entry) => entry.id === 'GovernanceBodies')
		expect(labelIn('gov', bodies.label)).toBe('Bodies and members')
		expect(labelIn('assoc', bodies.label)).toBe('Bodies and members')
		expect(labelIn('corp', bodies.label)).toBe('Board and members')
		expect(labelIn('ops', bodies.label)).toBe('Teams and members')
		for (const mode of Object.keys(MODE_LABELS)) {
			expect(dutch[labelIn(mode, bodies.label)], mode).toBeTruthy()
		}
	})

	it('still calls decisions resolutions for a company', () => {
		const decisions = main.find((entry) => entry.id === 'Decisions')
		expect(labelIn('corp', decisions.label)).toBe('Resolutions')
	})
})

describe('a page overlay', () => {
	it('replaces config keys, appends to lists and leaves the original alone', () => {
		const page = { id: 'P', title: 'P', config: { a: 1, list: [{ id: 'x' }] } }
		const out = applyPageOverlay(page, {
			id: 'P',
			config: { a: 2, b: 3 },
			configAppend: { list: [{ id: 'y' }], fresh: [{ id: 'z' }] },
		})
		expect(out).toEqual({
			id: 'P',
			title: 'P',
			config: {
				a: 2,
				b: 3,
				list: [{ id: 'x' }, { id: 'y' }],
				fresh: [{ id: 'z' }],
			},
		})
		expect(page).toEqual({
			id: 'P',
			title: 'P',
			config: { a: 1, list: [{ id: 'x' }] },
		})
	})

	it('patches list items by name, takes one out with null, and orders last', () => {
		const page = {
			id: 'P',
			config: { list: [{ id: 'a', n: 1 }, { key: 'b' }, 'c', { label: 'd' }] },
		}
		const out = applyPageOverlay(page, {
			id: 'P',
			configPatch: { list: { a: { n: 2 }, b: null } },
			configAppend: { list: [{ id: 'e' }] },
			configOrder: { list: ['e', 'd'] },
		})
		expect(out.config.list).toEqual([
			{ id: 'e' },
			{ label: 'd' },
			{ id: 'a', n: 2 },
			'c',
		])
	})

	it('adds slots beside the config, for a custom widget the overlay brings', () => {
		const out = applyPageOverlay(
			{ id: 'P', slots: { one: 'One' }, config: {} },
			{ id: 'P', slots: { two: 'Two' } },
		)
		expect(out.slots).toEqual({ one: 'One', two: 'Two' })
	})

	it('is skipped and reported when it names a page the manifest does not have', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const out = build({
			...simpleFile,
			pages: [{ id: 'NoSuchPage', config: { a: 1 } }],
		})
		expect(out.pages.some((page) => page.id === 'NoSuchPage')).toBe(false)
		expect(warn).toHaveBeenCalledTimes(1)
		warn.mockRestore()
	})
})

describe('the structure setting', () => {
	it('reads anything that is not the word full as simple', () => {
		for (const raw of [
			undefined,
			null,
			'',
			'simple',
			'Full',
			'uitgebreid',
			1,
			true,
		]) {
			expect(resolveStructureProfile(raw)).toBe(STRUCTURE_SIMPLE)
		}
		expect(resolveStructureProfile('full')).toBe(STRUCTURE_FULL)
	})

	it('is what main.js picks the layout file by', () => {
		expect(mainSource).toContain(
			"import menuLayoutFull from './menu-layout.json'",
		)
		expect(mainSource).toContain(
			"import menuLayoutSimple from './menu-layout.simple.json'",
		)
		expect(mainSource).toContain("loadState('decidiq', STRUCTURE_SETTING, '')")
		expect(mainSource).toContain(
			'structureProfile === STRUCTURE_FULL ? menuLayoutFull : menuLayoutSimple',
		)
		expect(mainSource.replace(/\s+/g, '')).toContain(
			'buildProfiledManifest(buildManifest,bundledManifest,fragments,menuLayout,{theming:navTheming(getCapabilities())},)',
		)
		// And nothing builds the manifest past the profile.
		expect(mainSource).not.toMatch(/[^(]buildManifest\(/)
	})

	it('is saved under its own key, through the settings write', async () => {
		const calls = []
		const fetchImpl = async (url, init) => {
			calls.push({ url, init })
			return {
				ok: true,
				status: 200,
				json: async () => ({
					success: true,
					config: { [STRUCTURE_SETTING]: 'full' },
				}),
			}
		}
		const stored = await saveMenuStructure('full', {
			url: '/apps/decidiq/api/settings',
			requestToken: 'token',
			fetchImpl,
		})
		expect(stored).toBe('full')
		expect(calls).toHaveLength(1)
		expect(calls[0].init.method).toBe('POST')
		expect(calls[0].init.headers.requesttoken).toBe('token')
		expect(JSON.parse(calls[0].init.body)).toEqual({ menu_structure: 'full' })
	})

	it('does not report a refused save as saved', async () => {
		const refused = async () => ({
			ok: false,
			status: 403,
			json: async () => ({}),
		})
		await expect(
			saveMenuStructure('full', {
				url: '/x',
				requestToken: 't',
				fetchImpl: refused,
			}),
		).rejects.toThrow('403')
	})

	it('does not report a save the server dropped as saved', async () => {
		// The settings write answers success for a key it does not know, and
		// hands back an empty string for a key nobody stored.
		for (const config of [{}, { [STRUCTURE_SETTING]: '' }]) {
			const dropped = async () => ({
				ok: true,
				status: 200,
				json: async () => ({ success: true, config }),
			})
			for (const structure of ['full', 'simple']) {
				await expect(
					saveMenuStructure(structure, {
						url: '/x',
						requestToken: 't',
						fetchImpl: dropped,
					}),
				).rejects.toThrow('did not store')
			}
		}
	})
})

describe('the navigation of the simple profile', () => {
	// DcDashboard and AppZijbalk: a brand block (logo, app name, the
	// instance's name) and one primary button. The instance's name and logo
	// are read from the theming capabilities at boot: the app names none.
	const theming = {
		name: 'Gemeente Zuiddrecht',
		logo: '/apps/theming/image/logo?v=1',
	}
	const withTheming = buildProfiledManifest(
		buildManifest,
		manifest(),
		fragments,
		simpleFile,
		{ theming },
	)

	it('opens with the brand block, named after the instance', () => {
		expect(withTheming.nav.brand).toEqual({
			name: 'decidiq',
			caption: 'Gemeente Zuiddrecht',
			logo: '/apps/theming/image/logo?v=1',
		})
	})

	it('shows the emblem, not the whole wordmark, when the set ships one', () => {
		// The board's brand block holds the shield only; the theming logo is
		// the full "Zuid Drecht" wordmark, drawn twice beside the app name
		// (seen live, 6 October 2026).
		const withEmblem = buildProfiledManifest(
			buildManifest,
			manifest(),
			fragments,
			simpleFile,
			{
				theming: navTheming({
					theming,
					nldesign: { logos: { emblem: '/apps/thematiq/img/emblem.svg' } },
				}),
			},
		)
		expect(simpleFile.nav.brand.logo).toBe('@theming.emblem|@theming.logo')
		expect(withEmblem.nav.brand.logo).toBe('/apps/thematiq/img/emblem.svg')
		expect(withEmblem.nav.brand.caption).toBe('Gemeente Zuiddrecht')
	})

	it('falls back to the theming logo when the set has no emblem', () => {
		const fallback = buildProfiledManifest(
			buildManifest,
			manifest(),
			fragments,
			simpleFile,
			{ theming: navTheming({ theming, nldesign: { logos: {} } }) },
		)
		expect(fallback.nav.brand.logo).toBe('/apps/theming/image/logo?v=1')
	})

	it('reads the emblem from thematiq, and an empty string when there is none', () => {
		expect(
			navTheming({ nldesign: { logos: { emblem: '/e.svg' } } }).emblem,
		).toBe('/e.svg')
		expect(navTheming({ theming }).emblem).toBe('')
		expect(navTheming({ nldesign: { logos: { emblem: 42 } } }).emblem).toBe('')
		expect(navTheming(null)).toEqual({ emblem: '' })
		expect(navTheming({ theming })).toMatchObject(theming)
	})

	it('leaves a value the instance does not answer empty, never a guess', () => {
		expect(build(simpleFile).nav.brand).toEqual({
			name: 'decidiq',
			caption: '',
			logo: '',
		})
		const partial = buildProfiledManifest(
			buildManifest,
			manifest(),
			fragments,
			simpleFile,
			{ theming: { name: 'Gemeente Zuiddrecht' } },
		)
		expect(partial.nav.brand.caption).toBe('Gemeente Zuiddrecht')
		// Neither an emblem nor a logo: empty.
		expect(partial.nav.brand.logo).toBe('')
	})

	it('has one primary button, New proposal, that opens the proposals list', () => {
		const action = withTheming.nav.primaryAction
		// No icon of its own: the library draws its plus, the board's "+".
		expect(action).toEqual({ label: 'New proposal', route: 'Motions' })
		expect(withTheming.pages.find((page) => page.id === action.route).type).toBe(
			'index',
		)
		expect(dutch[action.label]).toBe('Nieuw voorstel')
	})

	it('reads the theming capabilities in main.js and writes no municipality itself', () => {
		expect(mainSource).toContain('navTheming(getCapabilities())')
		expect(JSON.stringify(simpleFile.nav)).not.toMatch(/Zuiddrecht|Gemeente/)
	})

	it('is a manifest the library accepts, brand and button included', () => {
		const result = validateManifest(JSON.parse(JSON.stringify(withTheming)))
		expect(result.errors).toEqual([])
	})

	it('is absent from the full profile', () => {
		expect(fullFile.nav).toBeUndefined()
		expect(build(fullFile).nav).toEqual(manifest().nav ?? undefined)
	})
})
