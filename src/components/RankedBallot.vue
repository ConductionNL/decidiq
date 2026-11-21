<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RankedBallot: a member ranks every option of a ranked preference round
 (issue #1419, REQ-PRF-002). Each row has move up and move down buttons, so
 the whole ballot works with the keyboard alone; there is no drag. The ballot
 always holds every option exactly once, which is what the cast endpoint
 requires, and emits the keys in order, first preference first.

 @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
-->
<template>
	<div class="ranked-ballot" data-testid="ranked-ballot">
		<p class="ranked-ballot__hint">
			{{
				t(
					'decidiq',
					'Put the options in your order of preference, first choice at the top.',
				)
			}}
		</p>
		<ol class="ranked-ballot__list">
			<li
				v-for="(option, index) in order"
				:key="option.key"
				class="ranked-ballot__item"
				:data-testid="`ranked-ballot-item-${index}`">
				<span class="ranked-ballot__position">{{ index + 1 }}.</span>
				<span class="ranked-ballot__label">{{ option.label }}</span>
				<NcButton
					variant="tertiary"
					:aria-label="
						t('decidiq', 'Move {option} up', { option: option.label })
					"
					:disabled="busy || index === 0"
					@click="move(index, -1)">
					<template #icon>
						<ArrowUp :size="20" />
					</template>
				</NcButton>
				<NcButton
					variant="tertiary"
					:aria-label="
						t('decidiq', 'Move {option} down', { option: option.label })
					"
					:disabled="busy || index === order.length - 1"
					@click="move(index, 1)">
					<template #icon>
						<ArrowDown :size="20" />
					</template>
				</NcButton>
			</li>
		</ol>
		<NcButton
			variant="primary"
			data-testid="ranked-ballot-submit"
			:disabled="busy || order.length < 2"
			@click="
				$emit(
					'submit',
					order.map((option) => option.key),
				)
			">
			{{ t('decidiq', 'Submit ranking') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'

export default {
	name: 'RankedBallot',
	components: { ArrowDown, ArrowUp, NcButton },
	props: {
		options: { type: Array, required: true },
		busy: { type: Boolean, default: false },
	},

	emits: ['submit'],

	data() {
		return {
			order: this.options.map((option) => ({
				key: String(option.key),
				label: String(option.label || option.key),
			})),
		}
	},

	methods: {
		/**
		 * Move one option up or down by one place.
		 *
		 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-002-members-rank-candidates-in-order-of-preference-when-voting
		 * @param {number} index The option's place
		 * @param {number} step -1 for up, 1 for down
		 */
		move(index, step) {
			const target = index + step
			if (target < 0 || target >= this.order.length) {
				return
			}
			const next = [...this.order]
			const [moved] = next.splice(index, 1)
			next.splice(target, 0, moved)
			this.order = next
		},
	},
}
</script>

<style scoped>
.ranked-ballot {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.ranked-ballot__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.ranked-ballot__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.ranked-ballot__item {
	align-items: center;
	display: flex;
	gap: var(--default-grid-baseline);
}

.ranked-ballot__label {
	flex: 1;
}
</style>
