<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 DelegationChainWidget — content-driven `delegation-chain` catalog widget
 (register-detail-optimisation, design D2). Renders the ondermandaat chain
 for a `bevoegdheidstoedeling`: the ancestor breadcrumb walked up via
 `content.parentRefField` (root → … → current), the direct-child
 ondermandaten walked down (one filtered list query), the source
 `decision` link, and the resolved delegans/delegataris display.

 The ancestor walk fetches one object per level (bounded, de-duplicated via
 a visited-id set — see `walkAncestorsAsync`), never re-fetching an id and
 terminating safely on a defensive cycle (never producible via normal save
 flows; the sibling delegatie-mandaatregister change's save-time guard is
 what actually prevents one). Registered as type "delegation-chain"
 (renderer-only, surfaces: ['detail-page']) by registerDetailWidgets.js.

 @spec openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
-->
<template>
	<CnWidgetWrapper
		:title="title"
		:widgetId="widgetId"
		:refreshing="loading"
		titleIconPosition="left"
		flush>
		<template #title-icon>
			<CnIcon :name="icon" :size="20" />
		</template>

		<div class="cn-delegation-chain">
			<p v-if="loading && !hasLoaded" class="cn-delegation-chain__loading">
				{{ t('decidiq', 'Loading chain…') }}
			</p>

			<CnNoteCard
				v-else-if="error"
				type="error"
				:heading="t('decidiq', 'Could not load the ondermandaat chain')"
				data-testid="delegation-chain-error">
				{{ error }}
			</CnNoteCard>

			<template v-else>
				<nav
					v-if="breadcrumb.length > 1"
					class="cn-delegation-chain__breadcrumb"
					:aria-label="t('decidiq', 'Ondermandaat chain')"
					data-testid="delegation-chain-breadcrumb">
					<template v-for="(entry, index) in breadcrumb" :key="entry.id">
						<NcButton
							v-if="index < breadcrumb.length - 1"
							variant="tertiary"
							class="cn-delegation-chain__crumb"
							data-testid="delegation-chain-ancestor"
							@click="openToedeling(entry.id)">
							{{ entry.label }}
						</NcButton>
						<span
							v-else
							class="cn-delegation-chain__crumb-current"
							aria-current="page">
							{{ entry.label }}
						</span>
						<span
							v-if="index < breadcrumb.length - 1"
							class="cn-delegation-chain__crumb-sep"
							aria-hidden="true"
							>→</span
						>
					</template>
				</nav>

				<div class="cn-delegation-chain__meta">
					<span v-if="subject" class="cn-delegation-chain__subject">{{
						subject
					}}</span>
					<span v-if="delegansLabel" class="cn-delegation-chain__party">
						{{
							t('decidiq', 'Delegans: {who}', { who: delegansLabel })
						}}
					</span>
					<span v-if="delegatarisLabel" class="cn-delegation-chain__party">
						{{
							t('decidiq', 'Delegataris: {who}', {
								who: delegatarisLabel,
							})
						}}
					</span>
					<NcButton
						v-if="decisionId"
						variant="tertiary"
						data-testid="delegation-chain-decision-link"
						@click="openDecision(decisionId)">
						{{ decisionLabel || t('decidiq', 'View decision') }}
					</NcButton>
				</div>

				<div
					v-if="children.length"
					class="cn-delegation-chain__children"
					data-testid="delegation-chain-children">
					<h4 class="cn-delegation-chain__children-title">
						{{ t('decidiq', 'Ondermandaten') }}
					</h4>
					<ul class="cn-delegation-chain__children-list">
						<li v-for="child in children" :key="child.id">
							<NcButton
								variant="tertiary"
								data-testid="delegation-chain-child"
								@click="openToedeling(child.id)">
								{{ child.label }}
							</NcButton>
						</li>
					</ul>
				</div>

				<p
					v-if="breadcrumb.length <= 1 && !children.length"
					class="cn-delegation-chain__standalone"
					data-testid="delegation-chain-standalone">
					{{
						t(
							'decidiq',
							'This delegation/mandate has no parent or sub-mandates.',
						)
					}}
				</p>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import {
	CnIcon,
	CnNoteCard,
	CnWidgetWrapper,
	useObjectStore,
} from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { resolveObjectLabel } from './registerDetailWidgets.js'

export default {
	name: 'DelegationChainWidget',

	components: { CnWidgetWrapper, CnIcon, CnNoteCard, NcButton },

	props: {
		/**
		 * `{ register?, schema?, parentRefField?, decisionRefField?,
		 * subjectField?, labelField?, detailRoute?, decisionRoute? }`
		 *
		 * @type {object}
		 */
		content: { type: Object, default: () => ({}) },
		objectId: { type: [String, Number], default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
		objectData: { type: Object, default: () => ({}) },
		objectType: { type: String, default: '' },
		store: { type: Object, default: null },
		title: { type: String, default: () => t('decidiq', 'Ondermandaat chain') },
		icon: { type: String, default: 'Sitemap' },
	},

	data() {
		return {
			loading: false,
			hasLoaded: false,
			error: '',
			ancestors: [],
			children: [],
			delegansLabel: '',
			delegatarisLabel: '',
			decisionLabel: '',
		}
	},

	computed: {
		/** @spec exclude config-merge helper (schema/route defaults), no behavioural requirement of its own */
		cfg() {
			const c = this.content || {}
			return {
				register: c.register || this.register || 'decidesk',
				schema: c.schema || this.schema || 'bevoegdheidstoedeling',
				parentRefField: c.parentRefField || 'parentAllocation',
				decisionRefField: c.decisionRefField || 'decision',
				subjectField: c.subjectField || 'subject',
				labelField: c.labelField || 'subject',
				detailRoute: c.detailRoute || 'BevoegdheidstoedelingDetail',
				decisionRoute: c.decisionRoute || 'DecisionDetail',
			}
		},

		/** @spec exclude widget-id plumbing for CnWidgetWrapper, no behavioural requirement of its own */
		widgetId() {
			return this.cfg.schema || 'delegation-chain'
		},

		/** @spec exclude defensive object-id accessor, no behavioural requirement of its own */
		resolvedObjectId() {
			const data =
				this.objectData && typeof this.objectData === 'object'
					? this.objectData
					: {}
			const self = data['@self'] || {}
			return this.objectId || data.id || self.id || ''
		},

		/** @spec exclude defensive objectData accessor, no behavioural requirement of its own */
		safeObjectData() {
			return this.objectData && typeof this.objectData === 'object'
				? this.objectData
				: {}
		},

		/** @spec exclude presentation field display, not itself named by REQ-DMR-008 */
		subject() {
			return this.safeObjectData[this.cfg.subjectField] || ''
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
		 */
		decisionId() {
			return this.safeObjectData[this.cfg.decisionRefField] || null
		},

		/**
		 * Root → … → current, current LAST (REQ-DMR-008 breadcrumb order).
		 *
		 * @spec openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
		 */
		breadcrumb() {
			const current = {
				id: this.resolvedObjectId,
				label: this.rowLabel(this.safeObjectData) || this.subject,
			}
			return [...this.ancestors, current]
		},
	},

	watch: {
		resolvedObjectId: {
			immediate: true,
			/**
			 * @spec openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/** @spec exclude store-resolution plumbing, no behavioural requirement of its own */
		getStore() {
			if (this.store) return this.store
			try {
				return useObjectStore()
			} catch {
				return null
			}
		},

		/**
		 * @spec exclude store-registration plumbing, no behavioural requirement of its own
		 * @param {object} store The object store.
		 * @return {void}
		 */
		ensureRegistered(store) {
			const { schema, register } = this.cfg
			if (!store.objectTypeRegistry || !store.objectTypeRegistry[schema]) {
				store.registerObjectType(schema, schema, register)
			}
		},

		/**
		 * A display label for a toedeling row: prefer the configured
		 * `labelField` (default `subject`), fall back to the raw id.
		 *
		 * @spec openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
		 * @param {object} obj The toedeling record.
		 * @return {string}
		 */
		rowLabel(obj) {
			if (!obj) return ''
			const value = obj[this.cfg.labelField]
			return typeof value === 'string' && value !== '' ? value : obj.id || ''
		},

		/**
		 * Walk `parentRefField` upward one fetch at a time, de-duplicating
		 * visited ids so a defensive cycle terminates instead of hanging
		 * (REQ-DMR-008 scenario "never infinite-loops on malformed data").
		 *
		 * @spec openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
		 * @param {object} store The object store.
		 * @param {string} typeSlug The registered type slug.
		 * @param {object} startObj The current toedeling record.
		 * @return {Promise<Array<object>>} Ancestors, ROOT-first.
		 */
		async walkAncestorsAsync(store, typeSlug, startObj) {
			const { parentRefField } = this.cfg
			const chain = []
			const visited = new Set()
			if (startObj && startObj.id) visited.add(String(startObj.id))
			let parentId = startObj ? startObj[parentRefField] : null
			while (parentId && !visited.has(String(parentId))) {
				visited.add(String(parentId))
				const parent = await store.fetchObject(typeSlug, parentId)
				if (!parent) break
				chain.unshift(parent)
				parentId = parent[parentRefField]
			}
			return chain
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
		 */
		async load() {
			const id = this.resolvedObjectId
			if (!id) {
				this.ancestors = []
				this.children = []
				return
			}
			const store = this.getStore()
			if (!store) {
				this.error = t('decidiq', 'No object store available')
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.ensureRegistered(store)
				const { schema, parentRefField, decisionRefField, register } =
					this.cfg
				let current = this.safeObjectData
				if (!current || !current.id) {
					current = await store.fetchObject(schema, id)
				}
				const parentSlug =
					(current && current['@self'] && current['@self'].slug)
					|| (current && current.slug)
					|| ''
				const [ancestorObjs, childObjsById] = await Promise.all([
					this.walkAncestorsAsync(store, schema, current),
					store.fetchCollection(schema, {
						[parentRefField]: id,
						_limit: 100,
					}),
				])
				// OpenRegister's seed importer stores `$ref` values as raw
				// SLUG strings rather than resolved UUIDs, so a seeded
				// ondermandaat points at its parent's slug, not its id.
				// Live-verified 2026-08-19 on the sibling version-timeline
				// widget: filtering on the id alone returned nothing and the
				// widget rendered an empty shell. Retry once on the slug.
				let childObjs = childObjsById
				if (
					Array.isArray(childObjs)
					&& childObjs.length === 0
					&& parentSlug
				) {
					childObjs = await store.fetchCollection(schema, {
						[parentRefField]: parentSlug,
						_limit: 100,
					})
				}
				this.ancestors = ancestorObjs.map((obj) => ({
					id: obj.id,
					label: this.rowLabel(obj),
				}))
				this.children = (childObjs || [])
					.filter((c) => String(c.id) !== String(id))
					.map((obj) => ({ id: obj.id, label: this.rowLabel(obj) }))

				await this.resolveParties(store, current, register)
				if (decisionRefField && current && current[decisionRefField]) {
					this.decisionLabel = await resolveObjectLabel(
						store,
						'decision',
						'decision',
						register,
						current[decisionRefField],
						'title',
					)
				}
			} catch (e) {
				this.error =
					e?.message
					|| t('decidiq', 'Failed to load the ondermandaat chain.')
			} finally {
				this.loading = false
				this.hasLoaded = true
			}
		},

		/**
		 * Resolve the delegans / delegataris display: a referenced
		 * GovernanceBody/Person label where set, else the plain-text
		 * description/function field (bevoegdheidstoedeling's own anyOf).
		 *
		 * @spec openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
		 * @param {object} store The object store.
		 * @param {object} current The current toedeling record.
		 * @param {string} register The register slug.
		 * @return {Promise<void>}
		 */
		async resolveParties(store, current, register) {
			if (!current) {
				this.delegansLabel = ''
				this.delegatarisLabel = ''
				return
			}
			this.delegansLabel = current.delegans
				? await resolveObjectLabel(
						store,
						'governance-body',
						'governance-body',
						register,
						current.delegans,
						'name',
					)
				: current.delegansDescription || ''

			if (current.delegatarisBody) {
				this.delegatarisLabel = await resolveObjectLabel(
					store,
					'governance-body',
					'governance-body',
					register,
					current.delegatarisBody,
					'name',
				)
			} else if (current.delegateRole) {
				this.delegatarisLabel = current.delegateRole
			} else if (current.delegatePerson) {
				this.delegatarisLabel = await resolveObjectLabel(
					store,
					'person',
					'person',
					register,
					current.delegatePerson,
					'name',
				)
			} else {
				this.delegatarisLabel = ''
			}
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
		 * @param {string} id Toedeling id to navigate to.
		 * @return {void}
		 */
		openToedeling(id) {
			if (!id) return
			this.$router.push({ name: this.cfg.detailRoute, params: { id } })
		},

		/**
		 * @spec openspec/changes/register-detail-optimisation/specs/delegatie-mandaatregister/spec.md#req-dmr-008-ondermandaat-chain-widget-on-bevoegdheidstoedelingdetail
		 * @param {string} id Decision id to navigate to.
		 * @return {void}
		 */
		openDecision(id) {
			if (!id) return
			this.$router.push({ name: this.cfg.decisionRoute, params: { id } })
		},
	},
}
</script>

<style scoped>
.cn-delegation-chain__loading {
	padding: calc(2 * var(--default-grid-baseline, 4px));
	color: var(--color-text-maxcontrast);
}

.cn-delegation-chain__breadcrumb {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	padding: calc(2 * var(--default-grid-baseline, 4px))
		calc(2 * var(--default-grid-baseline, 4px)) 0;
}

.cn-delegation-chain__crumb-current {
	padding: 4px 10px;
	font-weight: bold;
}

.cn-delegation-chain__crumb-sep {
	color: var(--color-text-maxcontrast);
}

.cn-delegation-chain__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	padding: calc(2 * var(--default-grid-baseline, 4px));
}

.cn-delegation-chain__subject {
	font-weight: 600;
	flex-basis: 100%;
}

.cn-delegation-chain__party {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.cn-delegation-chain__children {
	padding: 0 calc(2 * var(--default-grid-baseline, 4px))
		calc(2 * var(--default-grid-baseline, 4px));
}

.cn-delegation-chain__children-title {
	margin: 0 0 4px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	color: var(--color-text-maxcontrast);
}

.cn-delegation-chain__children-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.cn-delegation-chain__standalone {
	padding: calc(2 * var(--default-grid-baseline, 4px));
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
