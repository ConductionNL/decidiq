<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: chair-controlled amendment voting order for a Motion
 (motion-amendment spec). Lists the motion's amendments in their current
 voting order, lets the chair move them up/down, offers a scope-based
 suggestion (most far-reaching first, via textDiff.changeMagnitude), and
 saves through the chair-only POST /api/motions/{id}/amendment-order
 endpoint (server enforces the chair role, fail closed — a 403 here is
 surfaced as-is).

 Decided amendments (adopted/rejected) are shown locked at their position;
 only undecided amendments can be reordered, since the server enforces the
 order on undecided amendments only.

 @spec openspec/specs/motion-amendment/spec.md
-->
<template>
	<div
		class="decidesk-tab decidesk-tab--amendment-order"
		data-testid="motion-amendment-order-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Voting order') }}
				<span v-if="!loading" class="decidesk-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
		</div>

		<p class="decidesk-tab__hint">
			{{
				t(
					'decidesk',
					'Amendments are voted before the main motion, most far-reaching first. Only the chair can save the order.',
				)
			}}
		</p>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load amendments')">
			{{ error }}
		</CnNoteCard>

		<p v-else-if="loading" class="decidesk-tab__loading">
			{{ t('decidesk', 'Loading amendments…') }}
		</p>

		<CnNoteCard
			v-else-if="!rows.length"
			type="info"
			:title="t('decidesk', 'No amendments')">
			{{ t('decidesk', 'This motion has no amendments to order.') }}
		</CnNoteCard>

		<template v-else>
			<ol class="amendment-order__list" data-testid="amendment-order-list">
				<li
					v-for="(row, index) in rows"
					:key="row.id"
					class="amendment-order__item"
					:data-testid="`amendment-order-item-${index}`">
					<span class="amendment-order__position">{{ index + 1 }}.</span>
					<span class="amendment-order__label">
						{{ row.title || row.id }}
						<CnStatusBadge
							v-if="row.lifecycle"
							:label="row.lifecycle"
							:color-map="lifecycleColors" />
					</span>
					<span class="amendment-order__actions">
						<NcButton
							variant="tertiary"
							:aria-label="t('decidesk', 'Move amendment up')"
							:disabled="busy || index === 0 || isDecided(row)"
							:data-testid="`amendment-order-up-${index}`"
							@click="move(index, -1)">
							<template #icon>
								<ArrowUp :size="20" />
							</template>
						</NcButton>
						<NcButton
							variant="tertiary"
							:aria-label="t('decidesk', 'Move amendment down')"
							:disabled="
								busy || index === rows.length - 1 || isDecided(row)
							"
							:data-testid="`amendment-order-down-${index}`"
							@click="move(index, 1)">
							<template #icon>
								<ArrowDown :size="20" />
							</template>
						</NcButton>
					</span>
				</li>
			</ol>

			<div class="amendment-order__footer">
				<NcButton
					variant="secondary"
					data-testid="amendment-order-suggest"
					:disabled="busy"
					:aria-label="
						t('decidesk', 'Suggest order, most far-reaching first')
					"
					@click="suggest">
					{{ t('decidesk', 'Suggest order') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="amendment-order-save"
					:disabled="busy || !dirty"
					:aria-label="t('decidesk', 'Save voting order')"
					@click="save">
					{{ t('decidesk', 'Save order') }}
				</NcButton>
			</div>

			<CnNoteCard
				v-if="saveError"
				type="error"
				:title="t('decidesk', 'Saving the order failed')">
				{{ saveError }}
			</CnNoteCard>
			<CnNoteCard
				v-else-if="saved"
				type="success"
				:title="t('decidesk', 'Order saved')">
				{{ t('decidesk', 'The amendment voting order has been saved.') }}
			</CnNoteCard>
		</template>
	</div>
</template>

<script>
import { CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import { suggestVotingOrder } from '../../utils/textDiff.js'
import { ensureRelationType } from './useRelationStore.js'
import { DECISION_LIFECYCLE_COLORS } from '../../constants/decisionLifecycle.js'

export default {
	name: 'MotionAmendmentOrderTab',
	components: { ArrowDown, ArrowUp, CnNoteCard, CnStatusBadge, NcButton },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			saveError: '',
			saved: false,
			dirty: false,
			rows: [],
			motionText: '',
		}
	},
	computed: {
		/** @spec openspec/specs/motion-amendment/spec.md */
		lifecycleColors() {
			return DECISION_LIFECYCLE_COLORS
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/motion-amendment/spec.md */
			handler() {
				this.refresh()
			},
		},
	},
	methods: {
		/** @spec openspec/specs/motion-amendment/spec.md */
		isDecided(row) {
			return ['adopted', 'rejected'].includes(row?.lifecycle)
		},
		/**
		 * Sort amendments by the server's deterministic comparison:
		 * votingOrder ascending (unordered last), submittedAt, id.
		 *
		 * @param {Array<object>} amendments Amendment objects.
		 * @return {Array<object>} Sorted copy.
		 * @spec openspec/specs/motion-amendment/spec.md
		 */
		sortByVotingOrder(amendments) {
			return [...amendments].sort((a, b) => {
				const rankA =
					Number.isFinite(Number(a?.votingOrder))
					&& a?.votingOrder !== null
					&& a?.votingOrder !== undefined
					&& a?.votingOrder !== ''
						? Number(a.votingOrder)
						: Number.MAX_SAFE_INTEGER
				const rankB =
					Number.isFinite(Number(b?.votingOrder))
					&& b?.votingOrder !== null
					&& b?.votingOrder !== undefined
					&& b?.votingOrder !== ''
						? Number(b.votingOrder)
						: Number.MAX_SAFE_INTEGER
				if (rankA !== rankB) return rankA - rankB
				const subA = String(a?.submittedAt || '')
				const subB = String(b?.submittedAt || '')
				if (subA !== subB) return subA < subB ? -1 : 1
				return String(a?.id || '') < String(b?.id || '') ? -1 : 1
			})
		},
		/** @spec openspec/specs/motion-amendment/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			this.saved = false
			this.dirty = false
			try {
				const motionStore = ensureRelationType('motion')
				const motion = await motionStore.fetchObject('motion', this.objectId)
				this.motionText = motion?.text || ''

				const amendmentStore = ensureRelationType('amendment')
				const items = await amendmentStore.fetchCollection('amendment', {
					decisionType: 'amendment',
					amends: this.objectId,
					_limit: 100,
				})
				this.rows = this.sortByVotingOrder(items || [])
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Failed to load amendments.')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/specs/motion-amendment/spec.md */
		move(index, delta) {
			const target = index + delta
			if (target < 0 || target >= this.rows.length) return
			if (
				this.isDecided(this.rows[index])
				|| this.isDecided(this.rows[target])
			)
				return
			const next = [...this.rows]
			const [row] = next.splice(index, 1)
			next.splice(target, 0, row)
			this.rows = next
			this.dirty = true
			this.saved = false
		},
		/** @spec openspec/specs/motion-amendment/spec.md */
		suggest() {
			// Decided amendments keep their position; only the undecided tail is
			// re-sorted most-far-reaching-first.
			const decided = this.rows.filter((row) => this.isDecided(row))
			const open = this.rows.filter((row) => !this.isDecided(row))
			this.rows = [...decided, ...suggestVotingOrder(open, this.motionText)]
			this.dirty = true
			this.saved = false
		},
		/** @spec openspec/specs/motion-amendment/spec.md */
		async save() {
			this.busy = true
			this.saveError = ''
			this.saved = false
			try {
				const res = await fetch(
					generateUrl(
						`/apps/decidesk/api/motions/${this.objectId}/amendment-order`,
					),
					{
						method: 'POST',
						headers: {
							Accept: 'application/json',
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({
							orderedAmendmentIds: this.rows.map((row) =>
								String(row.id),
							),
						}),
					},
				)
				const body = await res.json()
				if (!res.ok) {
					this.saveError =
						body?.message
						|| this.t('decidesk', 'Saving the order failed.')
					return
				}
				this.saved = true
				this.dirty = false
				await this.refresh()
				this.saved = true
			} catch (e) {
				this.saveError =
					e?.message || this.t('decidesk', 'Saving the order failed.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.decidesk-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidesk-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidesk-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}

.decidesk-tab__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.decidesk-tab__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.amendment-order__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.amendment-order__item {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
}

.amendment-order__position {
	font-weight: bold;
	min-width: 1.5rem;
}

.amendment-order__label {
	flex: 1;
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	min-width: 0;
}

.amendment-order__actions {
	display: flex;
	gap: 2px;
}

.amendment-order__footer {
	display: flex;
	gap: var(--default-grid-baseline);
	justify-content: flex-end;
}
</style>
