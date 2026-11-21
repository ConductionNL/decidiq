<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 MyDeclarationsPanel — the current member's own other positions and gifts,
 shown above the My declarations index (interests-and-integrity, REQ-INT-010,
 design D3). The member declares a position, registers a gift, ends a
 position and confirms the annual review here. Every action is a plain
 OpenRegister save of the whole object; who may write what stays with the
 schema's own authorization, so a refusal is shown as the server gives it.
-->
<template>
	<section class="my-declarations" data-testid="my-declarations-panel">
		<div class="my-declarations__header">
			<h3 class="my-declarations__title">
				{{ t('decidiq', 'Your declarations') }}
			</h3>
			<div class="my-declarations__actions">
				<NcButton
					:disabled="!canDeclare"
					data-testid="my-declarations-add-position"
					@click="dialog = 'position'">
					{{ t('decidiq', 'Declare another position') }}
				</NcButton>
				<NcButton
					:disabled="!canDeclare"
					data-testid="my-declarations-add-gift"
					@click="dialog = 'gift'">
					{{ t('decidiq', 'Register a gift') }}
				</NcButton>
			</div>
		</div>
		<p v-if="error" class="my-declarations__error" role="alert">
			{{ error }}
		</p>
		<p v-if="loading" class="my-declarations__note">
			{{ t('decidiq', 'Loading your declarations…') }}
		</p>
		<p v-else-if="!canDeclare" class="my-declarations__note" data-testid="my-declarations-no-person">
			{{ t('decidiq', 'Your account is not linked to a person in this register, so there is nothing to declare yet.') }}
		</p>
		<template v-else>
			<h4 class="my-declarations__subtitle">
				{{ t('decidiq', 'Other positions') }}
			</h4>
			<p v-if="positions.length === 0" class="my-declarations__note">
				{{ t('decidiq', 'You have not declared any other position.') }}
			</p>
			<ul v-else class="my-declarations__list">
				<li
					v-for="position in positions"
					:key="position.id"
					class="my-declarations__item"
					data-testid="my-declarations-position">
					<span>
						{{ t('decidiq', '{role} at {organisation}', { role: position.role || '', organisation: position.organisation || '' }) }}
						<span v-if="reviewDue(position)" class="my-declarations__due" data-testid="my-declarations-review-due">
							{{ t('decidiq', 'Review due') }}
						</span>
					</span>
					<span class="my-declarations__item-actions">
						<NcButton
							v-if="confirmable(position)"
							variant="tertiary"
							:disabled="saving"
							data-testid="my-declarations-confirm"
							@click="confirm(position)">
							{{ t('decidiq', 'Still accurate') }}
						</NcButton>
						<NcButton
							v-if="endable(position)"
							variant="tertiary"
							:disabled="saving"
							data-testid="my-declarations-end"
							@click="startEnding(position)">
							{{ t('decidiq', 'End position') }}
						</NcButton>
					</span>
				</li>
			</ul>
			<h4 class="my-declarations__subtitle">
				{{ t('decidiq', 'Gifts') }}
			</h4>
			<p v-if="gifts.length === 0" class="my-declarations__note">
				{{ t('decidiq', 'You have not registered any gift.') }}
			</p>
			<ul v-else class="my-declarations__list">
				<li
					v-for="gift in gifts"
					:key="gift.id"
					class="my-declarations__item"
					data-testid="my-declarations-gift">
					{{ gift.description }} ({{ gift.giver }}, {{ gift.receivedOn }})
				</li>
			</ul>
		</template>

		<DeclarationDialog
			v-if="dialog"
			:kind="dialog"
			:bodies="bodyOptions"
			:position="ending"
			:saving="saving"
			:error="dialogError"
			@submit="submit"
			@close="closeDialog" />
	</section>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton } from '@nextcloud/vue'
import DeclarationDialog from '../dialogs/DeclarationDialog.vue'
import { isReviewOverdue, refId } from '../utils/integrityCompliance.js'
import {
	canConfirm,
	canEnd,
	confirmedReview,
	endedPosition,
	newGift,
	newPosition,
	ownPersonIds,
	ownRecords,
} from '../utils/ownDeclarations.js'
import { ensureRelationType } from './tabs/useRelationStore.js'

const POSITION = 'ancillary-position'
const GIFT = 'declared-gift'

/** Upper bound on one list query. */
const LIST_LIMIT = 500

export default {
	name: 'MyDeclarationsPanel',

	components: { DeclarationDialog, NcButton },

	data() {
		return {
			personIds: [],
			positions: [],
			gifts: [],
			bodies: [],
			loading: false,
			saving: false,
			dialog: null,
			ending: null,
			error: '',
			dialogError: '',
		}
	},

	computed: {
		/** @return {boolean} True when the user is a person who can declare */
		canDeclare() {
			return this.personIds.length > 0
		},

		/** @return {Array<{id: string, label: string}>} The governance bodies as options */
		bodyOptions() {
			return this.bodies.map((body) => ({ id: refId(body), label: body.name || refId(body) }))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * @param {object} position The position.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-008-annual-review-rappel-is-a-declarative-notification
		 */
		reviewDue(position) {
			return isReviewOverdue(position, Date.now())
		},

		/**
		 * @param {object} position The position.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-008-annual-review-rappel-is-a-declarative-notification
		 */
		confirmable(position) {
			return canConfirm(position)
		},

		/**
		 * @param {object} position The position.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-002-nevenfunctie-disclosure-lifecycle-is-declarative
		 */
		endable(position) {
			return canEnd(position)
		},

		/**
		 * Read who the user is and their own positions and gifts.
		 *
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const uid = getCurrentUser()?.uid || ''
				const people = await ensureRelationType('person')
					.fetchCollection('person', { nextcloudUserId: uid, _limit: LIST_LIMIT }) || []
				this.personIds = ownPersonIds(people, uid)
				if (this.personIds.length === 0) return
				const [positions, gifts, bodies] = await Promise.all([
					ensureRelationType(POSITION).fetchCollection(POSITION, { _limit: LIST_LIMIT }),
					ensureRelationType(GIFT).fetchCollection(GIFT, { _limit: LIST_LIMIT }),
					ensureRelationType('governance-body').fetchCollection('governance-body', { _limit: 200 }),
				])
				this.positions = ownRecords(positions, this.personIds, 'person')
				this.gifts = ownRecords(gifts, this.personIds, 'recipient')
				this.bodies = bodies || []
			} catch {
				this.error = this.t('decidiq', 'Your declarations could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save one whole object and answer the saved record, or null with the
		 * server's reason in `dialogError` (or `error` outside a dialog).
		 *
		 * @param {string} type The schema slug.
		 * @param {object} record The whole object.
		 * @return {Promise<object|null>} The saved record.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
		 */
		async save(type, record) {
			this.saving = true
			this.dialogError = ''
			this.error = ''
			const fallback = this.t('decidiq', 'The declaration was not saved.')
			try {
				const store = ensureRelationType(type)
				const saved = await store.saveObject(type, record)
				if (!saved) {
					this.report(store.errors?.[type]?.message || fallback)
					return null
				}
				return saved
			} catch (e) {
				this.report(e?.message || fallback)
				return null
			} finally {
				this.saving = false
			}
		},

		/**
		 * Show a save refusal where the user is looking.
		 *
		 * @param {string} message The reason.
		 */
		report(message) {
			if (this.dialog) this.dialogError = message
			else this.error = message
		},

		/**
		 * Replace a record in a list by id, or add it.
		 *
		 * @param {Array<object>} list The list.
		 * @param {object} record The saved record.
		 * @return {Array<object>} The new list.
		 */
		upsert(list, record) {
			const id = refId(record)
			const rest = list.filter((item) => refId(item) !== id)
			return [record, ...rest]
		},

		/**
		 * @param {object} form The dialog's fields.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-001-nevenfunctie-schema-on-openregister
		 */
		async declarePosition(form) {
			const saved = await this.save(POSITION, newPosition(form, this.personIds[0]))
			if (!saved) return
			this.positions = this.upsert(this.positions, saved)
			this.closeDialog()
		},

		/**
		 * @param {object} form The dialog's fields.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-005-geschenk-schema-on-openregister
		 */
		async registerGift(form) {
			const saved = await this.save(GIFT, newGift(form, this.personIds[0]))
			if (!saved) return
			this.gifts = this.upsert(this.gifts, saved)
			this.closeDialog()
		},

		/**
		 * @param {string} endDate The day it ended.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-002-nevenfunctie-disclosure-lifecycle-is-declarative
		 */
		async endPosition(endDate) {
			const saved = await this.save(POSITION, endedPosition(this.ending, endDate))
			if (!saved) return
			this.positions = this.upsert(this.positions, saved)
			this.closeDialog()
		},

		/**
		 * @param {object} position The position.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-008-annual-review-rappel-is-a-declarative-notification
		 */
		async confirm(position) {
			const saved = await this.save(POSITION, confirmedReview(position))
			if (saved) this.positions = this.upsert(this.positions, saved)
		},

		/**
		 * Open the end dialog for a position.
		 *
		 * @param {object} position The position.
		 */
		startEnding(position) {
			this.ending = position
			this.dialog = 'end'
		},

		/**
		 * Save what the open dialog asks for.
		 *
		 * @param {object} form The dialog's fields.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
		 */
		submit(form) {
			if (this.dialog === 'gift') return this.registerGift(form)
			if (this.dialog === 'end') return this.endPosition(form.endDate)
			return this.declarePosition(form)
		},

		/** Close whichever dialog is open. */
		closeDialog() {
			this.dialog = null
			this.ending = null
			this.dialogError = ''
		},
	},
}
</script>

<style scoped>
.my-declarations {
	margin: 0 0 calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.my-declarations__header {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.my-declarations__title {
	margin: 0;
}

.my-declarations__actions,
.my-declarations__item-actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.my-declarations__subtitle {
	margin: calc(var(--default-grid-baseline) * 3) 0 var(--default-grid-baseline);
}

.my-declarations__note {
	color: var(--color-text-maxcontrast);
}

.my-declarations__error {
	color: var(--color-error-text);
}

.my-declarations__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.my-declarations__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.my-declarations__due {
	margin-inline-start: var(--default-grid-baseline);
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius-pill);
	color: var(--color-warning-text);
}
</style>
