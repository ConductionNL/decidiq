<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Profile widget: the person's photo (or initials) and their memberships,
 current first, each with body, role, party and portfolio. Earlier
 memberships are listed apart. Read-only: memberships are managed on the
 body's members widget.

 @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-001-every-person-has-a-profile-page
-->
<template>
	<div class="decidiq-tab decidiq-person" data-testid="person-memberships">
		<div class="decidiq-person__header">
			<img
				v-if="photo"
				class="decidiq-person__photo"
				:src="photo"
				:alt="t('decidiq', 'Photo of {name}', { name })"
				data-testid="person-photo"
				@error="photoFailed = true" />
			<span
				v-else
				class="decidiq-person__initials"
				aria-hidden="true"
				data-testid="person-initials"
				>{{ initialsText }}</span
			>
			<h3 class="decidiq-person__name">
				{{ name }}
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load memberships')">
			{{ error }}
		</CnNoteCard>

		<p v-else-if="loading" class="decidiq-tab__loading">
			{{ t('decidiq', 'Loading memberships…') }}
		</p>

		<template v-else>
			<p v-if="!sections.current.length" class="decidiq-person__empty">
				{{ t('decidiq', 'No current memberships.') }}
			</p>
			<ul
				class="decidiq-person__list"
				data-testid="person-memberships-current">
				<li
					v-for="row in sections.current"
					:key="row.id"
					class="decidiq-person__item">
					<router-link :to="{ path: `/governance-bodies/${row.bodyId}` }">
						{{ row.bodyName || t('decidiq', 'Unnamed body') }}
					</router-link>
					<span class="decidiq-person__meta">
						{{ [row.role, row.party].filter(Boolean).join(' · ') }}
					</span>
					<span
						v-if="row.portfolio"
						class="decidiq-person__portfolio"
						data-testid="person-portfolio">
						{{
							t('decidiq', 'Portfolio: {portfolio}', {
								portfolio: row.portfolio,
							})
						}}
					</span>
					<span class="decidiq-person__meta">
						{{
							t('decidiq', 'Since {date}', {
								date: dateLabel(row.startDate),
							})
						}}
					</span>
				</li>
			</ul>

			<details v-if="sections.earlier.length" class="decidiq-person__earlier">
				<summary>
					{{
						t('decidiq', 'Earlier memberships ({count})', {
							count: sections.earlier.length,
						})
					}}
				</summary>
				<ul
					class="decidiq-person__list"
					data-testid="person-memberships-earlier">
					<li
						v-for="row in sections.earlier"
						:key="row.id"
						class="decidiq-person__item">
						<router-link
							:to="{ path: `/governance-bodies/${row.bodyId}` }">
							{{ row.bodyName || t('decidiq', 'Unnamed body') }}
						</router-link>
						<span class="decidiq-person__meta">
							{{
								[row.role, row.party, row.portfolio]
									.filter(Boolean)
									.join(' · ')
							}}
						</span>
						<span class="decidiq-person__meta">
							{{
								t('decidiq', '{from} to {to}', {
									from: dateLabel(row.startDate),
									to: dateLabel(row.endDate),
								})
							}}
						</span>
					</li>
				</ul>
			</details>
		</template>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { initials, membershipSections } from '../../utils/memberProfile.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'PersonMembershipsTab',
	components: { CnNoteCard },

	props: {
		objectId: { type: [String, Number], default: '' },
		objectType: { type: String, default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			person: null,
			photoFailed: false,
			sections: { current: [], earlier: [] },
		}
	},

	computed: {
		/** @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-001-every-person-has-a-profile-page */
		name() {
			return this.person?.name || ''
		},

		/** @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-001-every-person-has-a-profile-page */
		photo() {
			return this.photoFailed ? '' : this.person?.image || ''
		},

		/** @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-001-every-person-has-a-profile-page */
		initialsText() {
			return initials(this.name)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-001-every-person-has-a-profile-page */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * Load the person, their memberships and the bodies they sit on.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-002-a-membership-carries-the-members-portfolio
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			this.photoFailed = false
			try {
				const [person, memberships] = await Promise.all([
					ensureRelationType('person').fetchObject(
						'person',
						this.objectId,
					),
					ensureRelationType('membership').fetchCollection('membership', {
						person: this.objectId,
						_limit: 100,
					}),
				])
				const bodyIds = [
					...new Set(
						(memberships || [])
							.map((m) => m.governanceBody)
							.filter(Boolean),
					),
				]
				const bodyStore = ensureRelationType('governance-body')
				const bodies = await Promise.all(
					bodyIds.map((id) =>
						bodyStore
							.fetchObject('governance-body', id)
							.catch(() => null),
					),
				)
				const bodiesById = {}
				bodyIds.forEach((id, i) => {
					bodiesById[id] = bodies[i] || {}
				})
				this.person = person
				this.sections = membershipSections(memberships || [], bodiesById)
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load memberships.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * A date as the user's short date.
		 *
		 * @param {?string} value An ISO date-time
		 * @return {string}
		 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-002-a-membership-carries-the-members-portfolio
		 */
		dateLabel(value) {
			if (!value) return ''
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.decidiq-person__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 12px;
}

.decidiq-person__photo,
.decidiq-person__initials {
	width: 64px;
	height: 64px;
	border-radius: 50%;
	flex-shrink: 0;
}

.decidiq-person__photo {
	object-fit: cover;
}

.decidiq-person__initials {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 1.4em;
	font-weight: bold;
}

.decidiq-person__name {
	margin: 0;
}

.decidiq-person__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.decidiq-person__item {
	display: flex;
	flex-direction: column;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.decidiq-person__meta,
.decidiq-person__empty {
	color: var(--color-text-maxcontrast);
}

.decidiq-person__earlier {
	margin-top: 12px;
}
</style>
