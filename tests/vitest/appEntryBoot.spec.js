/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The app entry (src/main.js) and the small entries beside it, run for real
 * with the library, Nextcloud globals, the Vue app, the router and fetch
 * replaced. Each test imports the entry afresh, so its top-level boot runs
 * once per test.
 *
 * main.js: registers the integrations, icons and translations; picks the
 * menu layout from the structure setting; builds the router under the app
 * base; mounts once the stores are loaded, also when they fail; and sends
 * a user on `/` to the default view they chose.
 */
import nodeModule from 'node:module'
import { afterAll, afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// webpack's require.context reads the manifest fragments. The test runner
// hands each module a require from node:module createRequire, so the
// fragments are supplied by giving those require functions a context.
const fragmentContext = vi.hoisted(() => ({ current: null }))
const originalCreateRequire = nodeModule.createRequire
nodeModule.createRequire = (...args) => {
	const scopedRequire = originalCreateRequire(...args)
	scopedRequire.context = (...contextArgs) => fragmentContext.current(...contextArgs)
	return scopedRequire
}
nodeModule.syncBuiltinESMExports()

afterAll(() => {
	nodeModule.createRequire = originalCreateRequire
	nodeModule.syncBuiltinESMExports()
})

const lib = vi.hoisted(() => ({
	buildManifest: vi.fn((base, fragments, layout) => ({ ...base, fragments, layout })),
	CnPageRenderer: { name: 'CnPageRenderer' },
	defaultPageTypes: { index: 'IndexType' },
	installIntegrationRegistry: vi.fn(),
	registerBuiltinIntegrations: vi.fn(),
	registerIcons: vi.fn(),
	registerLeafIntegrations: vi.fn(),
	registerTranslations: vi.fn(),
}))
const state = vi.hoisted(() => ({ values: {} }))
const l10n = vi.hoisted(() => ({ loadTranslations: vi.fn() }))
const boot = vi.hoisted(() => ({
	initializeStores: vi.fn(),
	registerDetailWidgets: vi.fn(),
	registerDecisionsLeaf: vi.fn(),
	registerApprovalChainLeaf: vi.fn(),
}))
const vueApp = vi.hoisted(() => ({ created: [] }))
const routing = vi.hoisted(() => ({ router: null, createRouter: vi.fn(), createWebHistory: vi.fn((base) => ({ base })) }))

vi.mock('@conduction/nextcloud-vue', () => lib)
vi.mock('@nextcloud/capabilities', () => ({ getCapabilities: () => ({}) }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app, key, fallback) => (key in state.values ? state.values[key] : fallback),
}))
vi.mock('@nextcloud/l10n', () => ({
	loadTranslations: l10n.loadTranslations,
	translate: (_app, text) => text,
	translatePlural: (_app, one, many, count) => (count === 1 ? one : many),
}))
vi.mock('vue', async (importOriginal) => ({
	...(await importOriginal()),
	createApp: vi.fn((component, props) => {
		const app = {
			component,
			props,
			config: { globalProperties: {} },
			use: vi.fn(),
			mount: vi.fn(),
		}
		vueApp.created.push(app)
		return app
	}),
}))
vi.mock('vue-router', () => ({
	createRouter: routing.createRouter,
	createWebHistory: routing.createWebHistory,
}))
vi.mock('../../src/store/store.js', () => ({ initializeStores: boot.initializeStores }))
vi.mock('../../src/components/widgets/registerDetailWidgets.js', () => ({
	registerDetailWidgets: boot.registerDetailWidgets,
}))
vi.mock('../../src/integrations/registerDecisionsLeaf.js', () => ({
	registerDecisionsLeaf: boot.registerDecisionsLeaf,
}))
vi.mock('../../src/integrations/registerApprovalChainLeaf.js', () => ({
	registerApprovalChainLeaf: boot.registerApprovalChainLeaf,
}))

/**
 * Let the entry's async boot and the preference fetch chain settle.
 */
async function settle() {
	for (let i = 0; i < 10; i++) {
		await new Promise((resolve) => setTimeout(resolve, 0))
	}
}

/**
 * Import src/main.js afresh and wait for it to mount.
 *
 * @return {Promise<object>} the created Vue app.
 */
async function bootMain() {
	await import('../../src/main.js')
	await settle()
	return vueApp.created.at(-1)
}

let fragmentKeys

beforeEach(() => {
	vi.resetModules()
	vi.clearAllMocks()
	vueApp.created = []
	state.values = {}
	fragmentKeys = ['./b.json', './a.json']
	const context = Object.assign((key) => ({ fragment: key }), { keys: () => fragmentKeys })
	fragmentContext.current = vi.fn(() => context)
	globalThis.window = { location: { pathname: '/index.php/apps/decidiq/meetings/12' } }
	globalThis.fetch = vi.fn(() => new Promise(() => {}))
	routing.router = {
		beforeEach: vi.fn(),
		currentRoute: { value: { path: '/meetings/12' } },
		replace: vi.fn(() => Promise.resolve()),
	}
	routing.createRouter.mockImplementation(() => routing.router)
	boot.initializeStores.mockResolvedValue()
	boot.registerDetailWidgets.mockResolvedValue()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	delete globalThis.window
	vi.restoreAllMocks()
})

describe('src/main.js boot', () => {
	it('registers the integrations, icons and translations before mounting', async () => {
		const app = await bootMain()

		expect(lib.installIntegrationRegistry).toHaveBeenCalledTimes(1)
		expect(lib.registerBuiltinIntegrations).toHaveBeenCalledTimes(1)
		expect(lib.registerLeafIntegrations).toHaveBeenCalledTimes(1)
		expect(boot.registerDecisionsLeaf).toHaveBeenCalledTimes(1)
		expect(lib.registerIcons).toHaveBeenCalledWith(expect.any(Object))
		expect(lib.registerTranslations).toHaveBeenCalledTimes(1)
		expect(l10n.loadTranslations).toHaveBeenCalledWith('decidiq', expect.any(Function))
		expect(boot.initializeStores).toHaveBeenCalledTimes(1)
		expect(boot.registerDetailWidgets).toHaveBeenCalledTimes(1)
		expect(app.mount).toHaveBeenCalledWith('#content')
	})

	it('mounts App with the merged manifest, registry, page types and permissions', async () => {
		state.values.isAdmin = true
		const app = await bootMain()

		expect(app.props.registry.LiveMeetingView).toEqual({ kind: 'page', component: expect.any(Object) })
		expect(app.props.pageTypes).toEqual({ index: 'IndexType' })
		expect(app.props.permissions).toHaveLength(2)
		expect(app.props.manifest.fragments).toEqual([{ fragment: './a.json' }, { fragment: './b.json' }])
		expect(fragmentContext.current).toHaveBeenCalledWith('./manifest.d/', false, /\.json$/)
		expect(app.config.globalProperties.t('decidiq', 'Hi')).toBe('Hi')
		expect(app.config.globalProperties.n('decidiq', 'item', 'items', 2)).toBe('items')
		expect(app.use).toHaveBeenCalledTimes(2)
		expect(app.use).toHaveBeenCalledWith(routing.router)
	})

	it('grants only the base permission to a non-admin', async () => {
		const app = await bootMain()
		expect(app.props.permissions).toHaveLength(1)
	})

	it('builds the router under the app base found in the URL, else the generated one', async () => {
		await bootMain()
		expect(routing.createWebHistory).toHaveBeenLastCalledWith('/index.php/apps/decidiq')

		vi.resetModules()
		globalThis.window = { location: { pathname: '/somewhere/else' } }
		await bootMain()
		expect(routing.createWebHistory).toHaveBeenLastCalledWith('/index.php/apps/decidiq')

		vi.resetModules()
		globalThis.window = { location: { pathname: '/apps/decidiq' } }
		await bootMain()
		expect(routing.createWebHistory).toHaveBeenLastCalledWith('/apps/decidiq')
	})

	it('routes every manifest page through a copy of the page renderer and guards permissions', async () => {
		await bootMain()
		const { routes } = routing.createRouter.mock.calls[0][0]

		expect(routes.at(-1)).toEqual({ path: '/:pathMatch(.*)*', redirect: '/' })
		const pageRoute = routes.find((route) => route.component)
		expect(pageRoute.component).toEqual(lib.CnPageRenderer)
		expect(pageRoute.component).not.toBe(lib.CnPageRenderer)

		const guard = routing.router.beforeEach.mock.calls[0][0]
		expect(guard({ meta: { permission: null } })).toBe(true)
	})

	it('picks the full or the simple menu layout from the structure setting', async () => {
		state.values.menu_structure = 'full'
		const full = await bootMain()

		vi.resetModules()
		state.values.menu_structure = 'simple'
		const simple = await bootMain()

		expect(full.props.manifest.menu).not.toEqual(simple.props.manifest.menu)
	})

	it('still mounts when loading the stores or the detail widgets fails', async () => {
		boot.initializeStores.mockRejectedValue(new Error('stores'))
		boot.registerDetailWidgets.mockRejectedValue(new Error('widgets'))

		const app = await bootMain()

		expect(app.mount).toHaveBeenCalledWith('#content')
		expect(console.error).toHaveBeenCalledWith(
			'Boot: initializeStores() failed; mounting anyway',
			expect.any(Error),
		)
		expect(console.error).toHaveBeenCalledWith('Boot: registerDetailWidgets() failed', expect.any(Error))
	})

	it('falls back to English when the catalogue cannot register', async () => {
		lib.registerTranslations.mockImplementationOnce(() => {
			throw new Error('no catalogue')
		})
		l10n.loadTranslations.mockImplementationOnce(() => {
			throw new Error('no l10n')
		})

		const app = await bootMain()

		expect(console.warn).toHaveBeenCalledWith(
			'[decidiq] registerTranslations failed; falling back to English',
			expect.any(Error),
		)
		expect(app.mount).toHaveBeenCalled()
	})

	it('swallows a rejected translation load', async () => {
		l10n.loadTranslations.mockReturnValueOnce(Promise.reject(new Error('404')))
		const app = await bootMain()
		expect(app.mount).toHaveBeenCalled()
	})

	it('logs Vue errors with where they happened', async () => {
		const app = await bootMain()
		const failure = new Error('render')

		app.config.errorHandler(failure, null, 'render function')

		expect(console.error).toHaveBeenCalledWith('[decidiq] Vue error (render function):', failure)
	})
})

describe('src/main.js default view', () => {
	const respond = (body, ok = true) =>
		vi.fn(() => Promise.resolve({ ok, json: () => Promise.resolve(body) }))

	beforeEach(() => {
		routing.router.currentRoute.value.path = '/'
	})

	it.each([
		['meetings', '/meetings'],
		['decisions', '/decisions'],
	])('sends a user on / to their %s preference', async (value, target) => {
		globalThis.fetch = respond({ value })
		await bootMain()

		expect(globalThis.fetch).toHaveBeenCalledWith(
			'/index.php/apps/decidiq/api/preferences/default-view',
			expect.objectContaining({ headers: { Accept: 'application/json' } }),
		)
		expect(routing.router.replace).toHaveBeenCalledWith(target)
	})

	it.each([
		['an unknown preference', respond({ value: 'calendar' })],
		['a failed response', respond({ value: 'meetings' }, false)],
		['a network error', vi.fn(() => Promise.reject(new Error('offline')))],
	])('stays on / for %s', async (_case, fetchMock) => {
		globalThis.fetch = fetchMock
		await bootMain()
		expect(routing.router.replace).not.toHaveBeenCalled()
	})

	it('does not ask when the user opened another page', async () => {
		routing.router.currentRoute.value.path = '/decisions/4'
		await bootMain()
		expect(globalThis.fetch).not.toHaveBeenCalled()
	})

	it('does not redirect when the user navigated away before the answer', async () => {
		let answer
		globalThis.fetch = vi.fn(() => new Promise((resolve) => { answer = resolve }))
		await bootMain()

		routing.router.currentRoute.value.path = '/meetings/3'
		answer({ ok: true, json: () => Promise.resolve({ value: 'decisions' }) })
		await settle()

		expect(routing.router.replace).not.toHaveBeenCalled()
	})

	it('gives up on the preference after three seconds', async () => {
		vi.useFakeTimers()
		try {
			let signal
			globalThis.fetch = vi.fn((_url, options) => {
				signal = options.signal
				return new Promise(() => {})
			})
			const done = import('../../src/main.js')
			for (let i = 0; i < 10; i++) {
				await vi.advanceTimersByTimeAsync(0)
			}
			await done

			expect(signal.aborted).toBe(false)
			await vi.advanceTimersByTimeAsync(2999)
			expect(signal.aborted).toBe(false)
			await vi.advanceTimersByTimeAsync(1)
			expect(signal.aborted).toBe(true)
		} finally {
			vi.useRealTimers()
		}
	})
})

describe('the other entries', () => {
	it('src/settings.js mounts the admin root once its translations load', async () => {
		await import('../../src/settings.js')

		expect(lib.registerTranslations).toHaveBeenCalledTimes(1)
		const [app, onLoaded] = l10n.loadTranslations.mock.calls[0]
		expect(app).toBe('decidiq')
		expect(vueApp.created).toHaveLength(0)

		onLoaded()

		const admin = vueApp.created[0]
		expect(admin.config.globalProperties.t('decidiq', 'Save')).toBe('Save')
		expect(admin.config.globalProperties.n('decidiq', 'one', 'many', 1)).toBe('one')
		expect(admin.use).toHaveBeenCalledTimes(1)
		expect(admin.mount).toHaveBeenCalledWith('#decidiq-settings')
	})

	it('src/integration-init.js registers the decisions and approval chain leaves', async () => {
		await import('../../src/integration-init.js')
		expect(boot.registerDecisionsLeaf).toHaveBeenCalledTimes(1)
		expect(boot.registerApprovalChainLeaf).toHaveBeenCalledTimes(1)
	})

	it('src/pinia.js shares one store instance', async () => {
		const first = (await import('../../src/pinia.js')).default
		const again = (await import('../../src/pinia.js')).default
		expect(first).toBe(again)
		expect(typeof first.use).toBe('function')
	})

	it('src/registry.js gives widgets their sizes and slots and pages their kind', async () => {
		const registry = (await import('../../src/registry.js')).default

		expect(registry.MeetingAgendaTab).toEqual({ kind: 'page', component: expect.any(Object) })
		expect(registry.PendingVotesKpiWidget).toEqual({
			kind: 'widget',
			component: expect.any(Object),
			defaultSize: { w: 3, h: 2 },
			minSize: { w: 2, h: 2 },
			maxSize: { w: 4, h: 3 },
			allowedSlots: ['dashboard', 'kpi'],
			propsSchema: {},
		})
		for (const entry of Object.values(registry)) {
			expect(['page', 'widget']).toContain(entry.kind)
		}
	})
})
