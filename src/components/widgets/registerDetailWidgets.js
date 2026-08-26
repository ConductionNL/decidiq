// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Registration module for decidiq's three register-detail catalog widgets
// (register-detail-optimisation, design.md D4). `registerDetailWidgets()`
// (bottom of this file) registers each widget's type into
// @conduction/nextcloud-vue's shared, consumer-extensible
// dashboardWidgetRegistry — the same extension point the library itself uses
// for `chart` / `stats-block` / `table` / `related` — scoped to
// `surfaces: ['detail-page']` and `form: null` (renderer-only, so none of
// the three ever appear in the dashboard Add-widget picker). Called once,
// awaited, from src/main.js before the app mounts.
//
// Also hosts the PURE / stateless algorithms the three widgets render from:
// version ordering, the ondermandaat ancestor-chain cycle-safety model, the
// confidentiality three-stage timeline build, and the shared object-label
// resolver. Kept here rather than inline in each .vue's <script> block so
// they are importable and unit-testable by Vitest without a Vue SFC
// transform — this repo's vitest.config.js runs on plain Vite with no
// @vitejs/plugin-vue registered (consistent with the existing pattern:
// src/views/dashboard/widgets/widgetLogic.js and
// src/components/tabs/useRelationStore.js are the same shape). Each widget
// imports and calls these exact functions — single source of truth, not a
// parallel copy.
//
// `registerDetailWidgets()` itself dynamically imports @conduction/nextcloud-vue
// and the three widget components (see its own docblock for why) so that
// importing THIS module for its pure exports never touches those imports.
//
// @spec openspec/changes/register-detail-optimisation/design.md#d4-declarative-vs-imperative-decision-adr-031

/**
 * Parse a value that's meant to be a date, tolerant of null/invalid input.
 *
 * @param {Date|string|number|null|undefined} value Candidate date-like value.
 * @return {number} Epoch ms, or `NaN` when unparseable/empty.
 */
export function toTime(value) {
	if (value === null || value === undefined || value === '') return NaN
	return new Date(value).getTime()
}

/**
 * Sort version rows ascending by their effective-date field (REQ-VOR-009 /
 * REQ-GDR-009). Rows with no parseable date sort last (stable), so a
 * concept version with no inwerkingtreding yet still renders instead of
 * disappearing.
 *
 * @param {Array<object>} versions Raw version objects.
 * @param {string} effectiveDateField The content-config field name.
 * @return {Array<object>} A NEW array, sorted ascending.
 */
export function sortVersionsByEffectiveDate(versions, effectiveDateField) {
	const list = Array.isArray(versions) ? versions.slice() : []
	return list.sort((a, b) => {
		const ta = toTime(a && a[effectiveDateField])
		const tb = toTime(b && b[effectiveDateField])
		const aValid = !Number.isNaN(ta)
		const bValid = !Number.isNaN(tb)
		if (aValid && bValid) return ta - tb
		if (aValid) return -1
		if (bValid) return 1
		return 0
	})
}

/**
 * Walk a self-referencing chain upward from `startId`, following
 * `item[parentField]` on each step, and return the ordered ancestor
 * breadcrumb ROOT-first (current object NOT included — callers append it).
 * Defensive against a cycle (never producible via normal save paths — the
 * delegatie-mandaatregister change's save-time guard is what actually
 * prevents one, REQ-DMR-008): a visited-id set stops the walk the moment an
 * id repeats, so a malformed chain renders the partial breadcrumb instead
 * of hanging. Pure / synchronous model of the same algorithm the live
 * widget runs asynchronously one fetch per level (DelegationChainWidget.vue
 * `walkAncestorsAsync`) — kept here so the cycle-safety shape is
 * unit-testable without a store/network mock.
 *
 * @param {Map<string, object>} byId Every candidate object, keyed by
 *   `String(id)`.
 * @param {string} startId The current object's id.
 * @param {string} parentField The self-reference field name.
 * @return {Array<object>} Ordered root-first ancestors (excludes `startId`).
 */
export function walkAncestors(byId, startId, parentField) {
	const chain = []
	const visited = new Set([String(startId)])
	let parentId = byId.has(String(startId))
		? byId.get(String(startId))[parentField]
		: null
	while (
		parentId
		&& byId.has(String(parentId))
		&& !visited.has(String(parentId))
	) {
		const node = byId.get(String(parentId))
		chain.unshift(node)
		visited.add(String(parentId))
		parentId = node[parentField]
	}
	return chain
}

/**
 * Direct children of `currentId` — every item whose `parentField` equals it.
 *
 * @param {Array<object>} all Every candidate object.
 * @param {string} parentField The self-reference field name.
 * @param {string} currentId The current object's id.
 * @return {Array<object>} The matching children, in the given order.
 */
export function findChildren(all, parentField, currentId) {
	const list = Array.isArray(all) ? all : []
	return list.filter(
		(item) => item && String(item[parentField]) === String(currentId),
	)
}

/**
 * Build a `geheimhouding` record's fixed three-stage timeline (design D3):
 * imposed → ratification (bekrachtiging) → dissolution. Each stage:
 * `{ key, populated, pending, overdue, date, ... }`. A stage with no
 * relevant fields set renders `pending: true` (never omitted, REQ-EMB-010)
 * so the reader sees the full expected sequence. The ratification stage is
 * `overdue` only while `lifecycle === 'imposed'` (an already-ratified or
 * dissolved record is never "overdue" regardless of its stored deadline)
 * and the deadline has passed with no ratification recorded yet.
 *
 * @param {object} record The geheimhouding object.
 * @param {number} [now] Epoch ms "now" (injectable for deterministic tests).
 * @return {Array<object>} The three ordered stage descriptors.
 */
export function buildConfidentialityStages(record, now = Date.now()) {
	const r = record || {}

	const imposed = {
		key: 'imposed',
		populated: Boolean(r.imposedAt),
		pending: !r.imposedAt,
		overdue: false,
		date: r.imposedAt || null,
	}

	const ratificationPopulated = Boolean(
		r.ratificationDate || r.ratificationDecision,
	)
	const deadlineTime = toTime(r.ratificationDeadline)
	const overdue =
		r.lifecycle === 'imposed'
		&& !ratificationPopulated
		&& !Number.isNaN(deadlineTime)
		&& deadlineTime < now
	const ratification = {
		key: 'ratification',
		populated: ratificationPopulated,
		pending: !ratificationPopulated,
		overdue,
		date: r.ratificationDate || null,
		deadline: r.ratificationDeadline || null,
		decisionId: r.ratificationDecision || null,
		agendaItemId: r.ratificationAgendaItem || null,
	}

	const dissolutionPopulated = Boolean(r.liftingDate || r.dissolutionDecision)
	const dissolution = {
		key: 'dissolution',
		populated: dissolutionPopulated,
		pending: !dissolutionPopulated,
		overdue: false,
		date: r.liftingDate || null,
		decisionId: r.dissolutionDecision || null,
		conditions: r.liftingConditions || null,
	}

	return [imposed, ratification, dissolution]
}

/**
 * Resolve a single reference id to a display label via the object store,
 * degrading to the raw id on any failure or missing store (never throws,
 * never blanks). Mirrors the resolution `CnFkResolveCell` uses internally
 * (that component is not part of the library's public barrel export, so
 * this is a small self-contained equivalent rather than a deep import of a
 * library-internal path).
 *
 * @param {object|null} store Object store instance (`fetchObject`,
 *   `registerObjectType`, `objectTypeRegistry`).
 * @param {string} typeSlug The store-registered type slug for this
 *   reference (a local slug; conventionally the schema slug itself).
 * @param {string} schemaSlug OpenRegister schema slug (used only if the
 *   type still needs registering).
 * @param {string} registerSlug OpenRegister register slug (used only if the
 *   type still needs registering).
 * @param {string} id The reference id.
 * @param {string} [labelField] Property to prefer as the label.
 * @return {Promise<string>} The resolved label, or the raw id when
 *   unresolvable.
 */
export async function resolveObjectLabel(
	store,
	typeSlug,
	schemaSlug,
	registerSlug,
	id,
	labelField = 'name',
) {
	if (!id) return ''
	if (!store) return String(id)
	try {
		if (!store.objectTypeRegistry || !store.objectTypeRegistry[typeSlug]) {
			store.registerObjectType(typeSlug, schemaSlug, registerSlug)
		}
		const obj = await store.fetchObject(typeSlug, id)
		if (!obj) return String(id)
		const self = obj['@self'] || {}
		const candidates = [obj[labelField], obj.title, obj.name, self.name]
		for (const raw of candidates) {
			if (typeof raw === 'string' && raw !== '') return raw
		}
		return String(id)
	} catch {
		return String(id)
	}
}

/**
 * Register the three widget types into the shared dashboardWidgetRegistry.
 * Called once from src/main.js, awaited before the app mounts.
 *
 * The `@conduction/nextcloud-vue` import and the three widget-component
 * imports are DYNAMIC (not static top-level imports) so this module stays
 * importable by Vitest for its pure helper functions above: this repo's
 * vitest.config.js runs on plain Vite with no @vitejs/plugin-vue
 * registered, and `@conduction/nextcloud-vue`'s bundle itself re-exports
 * `@nextcloud/vue`, whose package.json declares no root "exports" entry —
 * a static top-level import of either fails Vite's module resolution the
 * moment the file is loaded, even for a test that never touches the
 * registration call. A dynamic `import()` inside this function is only
 * evaluated when the function actually runs, so a spec that imports just
 * the pure exports above never triggers it (confirmed empirically; see
 * tests/vitest/registerDetailWidgets.spec.js). Webpack (this app's actual
 * build) code-splits a dynamic `import()` the same way it would a static
 * one — no behavioural difference in the shipped app.
 *
 * @return {Promise<void>}
 */
export async function registerDetailWidgets() {
	const [
		{ registerDashboardWidget },
		{ default: ConfidentialityStatusTimelineWidget },
		{ default: DelegationChainWidget },
		{ default: RegisterVersionTimelineWidget },
	] = await Promise.all([
		import('@conduction/nextcloud-vue'),
		import('./ConfidentialityStatusTimelineWidget.vue'),
		import('./DelegationChainWidget.vue'),
		import('./RegisterVersionTimelineWidget.vue'),
	])

	registerDashboardWidget('version-timeline', {
		renderer: RegisterVersionTimelineWidget,
		form: null,
		defaultContent: {},
		displayName: 'Version timeline',
		icon: 'Timeline',
		surfaces: ['detail-page'],
		ownsTitle: true,
	})

	registerDashboardWidget('delegation-chain', {
		renderer: DelegationChainWidget,
		form: null,
		defaultContent: {},
		displayName: 'Ondermandaat chain',
		icon: 'Sitemap',
		surfaces: ['detail-page'],
		ownsTitle: true,
	})

	registerDashboardWidget('confidentiality-status-timeline', {
		renderer: ConfidentialityStatusTimelineWidget,
		form: null,
		defaultContent: {},
		displayName: 'Confidentiality status timeline',
		icon: 'ShieldLockOutline',
		surfaces: ['detail-page'],
		ownsTitle: true,
	})
}
