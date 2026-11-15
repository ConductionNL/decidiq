// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Registers one of decidiq's integration leaves on the shared OpenRegister
// integration registry, load-order safe: decidiq's bundle may load before or
// after OpenRegister installs the real registry. When it is installed the
// leaf registers live; otherwise a `{ _queue, register }` stub collects it
// and OpenRegister replays the queue when it calls installIntegrationRegistry().

/**
 * The global the registry hangs off: the given one, else `window`, else null.
 *
 * @param {object} [globalRef] Global to attach to.
 * @return {object|null} The global.
 */
function leafTarget(globalRef) {
	return globalRef || (typeof window !== 'undefined' ? window : null)
}

/**
 * Whether the real registry is installed: it has register() and no queue.
 *
 * @param {object|null|undefined} current The registry slot.
 * @return {boolean} True when a leaf can register live.
 */
function isLiveRegistry(current) {
	return (
		Boolean(current)
		&& typeof current.register === 'function'
		&& current._queue === undefined
	)
}

/**
 * Register on the live registry; a duplicate id (AD-13: it throws in dev) is
 * not fatal to boot and only warns.
 *
 * @param {object} registry The live registry.
 * @param {{descriptor: object, name: string}} leaf The leaf.
 * @return {void}
 */
function registerLive(registry, leaf) {
	try {
		registry.register(leaf.descriptor)
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(`[decidiq] ${leaf.name} leaf already registered`, e)
	}
}

/**
 * A queue stub that collects registrations until OpenRegister replays them.
 *
 * @return {{_queue: Array<object>, register: (entry: object) => void}} The stub.
 */
function queueStub() {
	return {
		_queue: [],
		register(entry) {
			this._queue.push(entry)
		},
	}
}

/**
 * The OpenRegister namespace on the global, created when it is missing.
 *
 * @param {object} target The global.
 * @return {object} `target.OCA.OpenRegister`.
 */
function openRegisterNamespace(target) {
	target.OCA = target.OCA || {}
	target.OCA.OpenRegister = target.OCA.OpenRegister || {}
	return target.OCA.OpenRegister
}

/**
 * The registry to queue on: the one already there, else a new queue stub.
 *
 * @param {object} namespace The OpenRegister namespace.
 * @return {object} The registry or stub.
 */
function queueingRegistry(namespace) {
	if (namespace.integrations === undefined || namespace.integrations === null) {
		namespace.integrations = queueStub()
	}
	return namespace.integrations
}

/**
 * Register a leaf on the shared integration registry.
 *
 * @param {object} [globalRef] Global to attach to (defaults to `window`).
 * @param {{descriptor: object, name: string}} leaf The leaf descriptor and
 *   the name its duplicate warning uses.
 * @return {void}
 */
export function registerLeaf(globalRef, leaf) {
	const target = leafTarget(globalRef)
	if (target === null) {
		return
	}
	const namespace = openRegisterNamespace(target)
	if (isLiveRegistry(namespace.integrations)) {
		registerLive(namespace.integrations, leaf)
		return
	}
	queueingRegistry(namespace).register(leaf.descriptor)
}
