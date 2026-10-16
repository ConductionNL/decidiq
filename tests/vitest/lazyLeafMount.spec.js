/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The leaf mount pair loads its component (and Vue) lazily, so that
 * `decidiq-integration-init.js`, loaded on every Nextcloud page, carries only
 * the descriptors. The async gap between `mount` and the chunk arriving is
 * where the races live: these tests hold the loader open and act in between.
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { createLazyMountPair } from '../../src/integrations/createLazyMountPair.js'

/**
 * A promise with its resolve/reject handles exposed.
 *
 * @return {{promise: Promise, resolve: Function, reject: Function}}
 */
function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => {
		resolve = res
		reject = rej
	})
	return { promise, resolve, reject }
}

/**
 * A fake Vue module recording every app it creates.
 *
 * @return {{createApp: Function, apps: Array}}
 */
function fakeVue() {
	const apps = []
	return {
		apps,
		createApp: vi.fn((component, props) => {
			const app = {
				component,
				props,
				config: { globalProperties: {} },
				mount: vi.fn(),
				unmount: vi.fn(),
			}
			apps.push(app)
			return app
		}),
	}
}

describe('createLazyMountPair', () => {
	it('mounts the loaded component with the forwarded props and t installed', async () => {
		const vue = fakeVue()
		const component = { name: 'Widget' }
		const loader = vi.fn(() => Promise.resolve(component))
		const { mount } = createLazyMountPair(loader, 'test', () =>
			Promise.resolve(vue),
		)
		const el = {}

		await mount(el, { surface: 'detail-page', objectId: 'abc' })

		expect(loader).toHaveBeenCalledWith('detail-page')
		expect(vue.apps).toHaveLength(1)
		expect(vue.apps[0].component).toBe(component)
		expect(vue.apps[0].props).toEqual({
			surface: 'detail-page',
			objectId: 'abc',
		})
		expect(typeof vue.apps[0].config.globalProperties.t).toBe('function')
		expect(vue.apps[0].mount).toHaveBeenCalledWith(el)
	})

	it('does not mount when unmount runs before the chunk resolves', async () => {
		const vue = fakeVue()
		const chunk = deferred()
		const { mount, unmount } = createLazyMountPair(
			() => chunk.promise,
			'test',
			() => Promise.resolve(vue),
		)
		const el = {}

		const pending = mount(el, {})
		unmount(el)
		chunk.resolve({ name: 'Tab' })
		await pending

		expect(vue.createApp).not.toHaveBeenCalled()
	})

	it('ignores a second mount of the same element, while loading and after', async () => {
		const vue = fakeVue()
		const chunk = deferred()
		const loader = vi.fn(() => chunk.promise)
		const { mount } = createLazyMountPair(loader, 'test', () =>
			Promise.resolve(vue),
		)
		const el = {}

		const first = mount(el, {})
		expect(mount(el, {})).toBeUndefined()
		chunk.resolve({ name: 'Tab' })
		await first
		expect(mount(el, {})).toBeUndefined()

		expect(loader).toHaveBeenCalledTimes(1)
		expect(vue.apps).toHaveLength(1)
	})

	it('mounts only the latest cycle when unmount and mount interleave during a load', async () => {
		const vue = fakeVue()
		const chunks = [deferred(), deferred()]
		let call = 0
		const { mount, unmount } = createLazyMountPair(
			() => chunks[call++].promise,
			'test',
			() => Promise.resolve(vue),
		)
		const el = {}

		const first = mount(el, { cycle: 1 })
		unmount(el)
		const second = mount(el, { cycle: 2 })
		chunks[0].resolve({ name: 'Tab' })
		chunks[1].resolve({ name: 'Tab' })
		await Promise.all([first, second])

		expect(vue.apps).toHaveLength(1)
		expect(vue.apps[0].props).toEqual({ cycle: 2 })
	})

	it('unmounts a mounted app once and tolerates a double or unknown unmount', async () => {
		const vue = fakeVue()
		const { mount, unmount } = createLazyMountPair(
			() => Promise.resolve({}),
			'test',
			() => Promise.resolve(vue),
		)
		const el = {}

		await mount(el, {})
		unmount(el)
		unmount(el)
		unmount({})

		expect(vue.apps[0].unmount).toHaveBeenCalledTimes(1)
	})

	it('reports a failed chunk load and lets the element mount again', async () => {
		const vue = fakeVue()
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		const loader = vi
			.fn()
			.mockReturnValueOnce(Promise.reject(new Error('chunk 404')))
			.mockReturnValueOnce(Promise.resolve({}))
		const { mount } = createLazyMountPair(loader, 'test', () =>
			Promise.resolve(vue),
		)
		const el = {}

		await mount(el, {})
		expect(error).toHaveBeenCalled()
		expect(vue.apps).toHaveLength(0)

		await mount(el, {})
		expect(vue.apps).toHaveLength(1)
		error.mockRestore()
	})

	it('ignores a missing element', () => {
		const loader = vi.fn()
		const { mount } = createLazyMountPair(loader, 'test', () =>
			Promise.resolve(fakeVue()),
		)

		expect(mount(null, {})).toBeUndefined()
		expect(mount(undefined, {})).toBeUndefined()
		expect(loader).not.toHaveBeenCalled()
	})
})

describe('the init-script leaves stay free of static Vue imports', () => {
	// A static `import ... from 'vue'` or `'./X.vue'` in a leaf module pulls Vue
	// and the component library back into the every-page init script.
	const files = [
		'src/integrations/registerDecisionsLeaf.js',
		'src/integrations/registerApprovalChainLeaf.js',
		'src/integrations/createLazyMountPair.js',
		'src/integration-init.js',
	]

	for (const file of files) {
		it(`${file} has no static vue or .vue import`, () => {
			const source = fs.readFileSync(
				path.resolve(__dirname, '../..', file),
				'utf8',
			)
			expect(source).not.toMatch(/^import[^\n]*from\s+'vue'/m)
			expect(source).not.toMatch(/^import[^\n]*from\s+'[^']*\.vue'/m)
		})
	}
})
