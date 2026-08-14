<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Governance body — add-existing-member dialog (ADR-004 modal isolation).

 Extracted from GovernanceBodyMembersTab.vue (the picker used to be an
 inline NcDialog there — a pre-existing modal-isolation violation). Lists
 participants not yet linked to the body and links the chosen one by
 rewriting its `governanceBody` pointer via the shared OR object store.

 @spec openspec/specs/admin-settings/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Add member')"
		size="normal"
		data-testid="member-add-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{
					t(
						'decidesk',
						'Pick a participant to link to this governance body.',
					)
				}}
			</p>
			<div v-if="loading" class="member-add__loading">
				{{ t('decidesk', 'Loading participants…') }}
			</div>
			<ul v-else-if="candidates.length" class="member-add__list">
				<li v-for="cand in candidates" :key="cand.id">
					<NcButton
						:disabled="linking"
						data-testid="member-add-candidate"
						@click="link(cand)">
						{{ candidateLabel(cand) }}
					</NcButton>
				</li>
			</ul>
			<p v-else class="member-add__empty">
				{{ t('decidesk', 'No unassigned participants available.') }}
			</p>
			<p v-if="error" class="member-add__error" data-testid="member-add-error">
				{{ error }}
			</p>
		</template>
		<template #actions>
			<NcButton data-testid="member-add-cancel" @click="$emit('close')">
				{{ t('decidesk', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { ensureRelationType } from '../components/tabs/useRelationStore.js'

export default {
	name: 'MemberAddDialog',
	components: { NcButton, NcDialog },
	props: {
		/** OR object id of the governance body the member is linked to. */
		bodyId: { type: [String, Number], required: true },
	},

	emits: ['close', 'linked'],
	data() {
		return {
			loading: false,
			linking: false,
			candidates: [],
			error: '',
		}
	},

	/** @spec exclude lifecycle hook; only triggers the candidate fetch */
	created() {
		this.loadCandidates()
	},

	methods: {
		/**
		 * @param p
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		candidateLabel(p) {
			return p.displayName || p.name || p.id
		},

		/** @spec openspec/specs/admin-settings/spec.md */
		async loadCandidates() {
			this.loading = true
			try {
				const store = ensureRelationType('participant')
				// The OpenRegister API has no negation filter, so fetch a page
				// of all participants and filter client-side.
				const items = await store.fetchCollection('participant', {
					_limit: 100,
				})
				this.candidates = (items || []).filter(
					(p) => p.governanceBody !== this.bodyId,
				)
			} catch {
				this.candidates = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param participant
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async link(participant) {
			this.linking = true
			this.error = ''
			try {
				const store = ensureRelationType('participant')
				await store.saveObject('participant', {
					...participant,
					governanceBody: this.bodyId,
				})
				this.$emit('linked')
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Failed to link participant.')
			} finally {
				this.linking = false
			}
		},
	},
}
</script>

<style scoped>
.member-add__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-height: 320px;
	overflow-y: auto;
}

.member-add__empty,
.member-add__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.member-add__error {
	color: var(--color-error);
	margin: 8px 0 0;
}
</style>
