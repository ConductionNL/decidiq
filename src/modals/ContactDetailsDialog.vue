<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Contact details of a member (a Person) or of a governance body: email,
 phone, address and the like, stored as ContactDetail objects
 (bodies-membership-terms-contacts-and-factions, ADR-004 modal isolation).

 @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-002-contact-details-for-members-and-bodies
-->
<template>
	<NcDialog
		:name="
			name
				? t('decidiq', 'Contact details of {name}', { name })
				: t('decidiq', 'Contact details of this body')
		"
		size="normal"
		data-testid="contact-details-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="contact-details__form">
				<ul v-if="details.length" class="contact-details__list">
					<li
						v-for="detail in details"
						:key="detail.id"
						class="contact-details__item"
						data-testid="contact-details-item">
						<span class="contact-details__type">{{
							typeLabel(detail.type)
						}}</span>
						<span class="contact-details__value">{{
							detail.value
						}}</span>
						<NcButton
							variant="tertiary"
							:aria-label="
								t('decidiq', 'Remove {value}', {
									value: detail.value,
								})
							"
							@click="remove(detail)">
							{{ t('decidiq', 'Remove') }}
						</NcButton>
					</li>
				</ul>
				<p v-else-if="!loading" class="contact-details__empty">
					{{ t('decidiq', 'No contact details yet.') }}
				</p>
				<NcSelect
					v-model="selectedType"
					:inputLabel="t('decidiq', 'Kind')"
					:options="typeOptions"
					label="label"
					:clearable="false"
					data-testid="contact-details-type" />
				<NcTextField
					v-model="value"
					data-testid="contact-details-value"
					:label="t('decidiq', 'Value')" />
				<p v-if="error" class="contact-details__error" role="alert">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton data-testid="contact-details-close" @click="$emit('close')">
				{{ t('decidiq', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving || !value.trim()"
				data-testid="contact-details-add"
				@click="add">
				{{ t('decidiq', 'Add contact detail') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { ensureRelationType } from '../components/tabs/useRelationStore.js'
import { contactDetailPayload } from '../utils/bodyMembership.js'

export default {
	name: 'ContactDetailsDialog',

	components: { NcButton, NcDialog, NcSelect, NcTextField },

	props: {
		/** The person whose details these are (a member). */
		personId: { type: String, default: '' },
		/** The body whose details these are, when not a person's. */
		bodyId: { type: String, default: '' },
		/** Whose details, for the title. */
		name: { type: String, default: '' },
	},

	emits: ['close', 'saved'],

	data() {
		const typeOptions = [
			{ id: 'email', label: this.t('decidiq', 'Email') },
			{ id: 'phone', label: this.t('decidiq', 'Phone') },
			{ id: 'cell', label: this.t('decidiq', 'Mobile phone') },
			{ id: 'address', label: this.t('decidiq', 'Address') },
			{ id: 'url', label: this.t('decidiq', 'Website') },
		]
		return {
			typeOptions,
			selectedType: typeOptions[1],
			value: '',
			details: [],
			loading: false,
			saving: false,
			error: '',
		}
	},

	/** @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-002-contact-details-for-members-and-bodies */
	created() {
		this.load()
	},

	methods: {
		/**
		 * The label of a kind of contact detail.
		 *
		 * @param {string} type The ContactDetail type.
		 *
		 * @return {string} The label.
		 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-002-contact-details-for-members-and-bodies
		 */
		typeLabel(type) {
			return this.typeOptions.find((o) => o.id === type)?.label || type
		},

		/**
		 * Read the person's or the body's contact details.
		 *
		 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-002-contact-details-for-members-and-bodies
		 */
		async load() {
			this.loading = true
			try {
				const filter = this.personId
					? { person: this.personId }
					: { governanceBody: this.bodyId }
				this.details =
					(await ensureRelationType('contact-detail').fetchCollection(
						'contact-detail',
						{ ...filter, _limit: 50 },
					)) || []
			} catch {
				this.details = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save a new contact detail.
		 *
		 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-002-contact-details-for-members-and-bodies
		 */
		async add() {
			this.saving = true
			this.error = ''
			try {
				await ensureRelationType('contact-detail').saveObject(
					'contact-detail',
					contactDetailPayload({
						type: this.selectedType?.id || 'phone',
						value: this.value,
						personId: this.personId,
						bodyId: this.personId ? '' : this.bodyId,
					}),
				)
				this.value = ''
				await this.load()
				this.$emit('saved')
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'The contact detail could not be saved.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Remove a contact detail.
		 *
		 * @param {object} detail The contact detail.
		 * @spec openspec/specs/governance-bodies/spec.md#requirement-req-bmt-002-contact-details-for-members-and-bodies
		 */
		async remove(detail) {
			this.error = ''
			try {
				await ensureRelationType('contact-detail').deleteObject(
					'contact-detail',
					detail.id,
				)
				await this.load()
				this.$emit('saved')
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'The contact detail could not be removed.')
			}
		},
	},
}
</script>

<style scoped>
.contact-details__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.contact-details__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.contact-details__item {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.contact-details__type {
	min-width: 8em;
	color: var(--color-text-maxcontrast);
}

.contact-details__value {
	flex: 1;
}

.contact-details__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.contact-details__error {
	color: var(--color-error-text);
	margin: 0;
}
</style>
