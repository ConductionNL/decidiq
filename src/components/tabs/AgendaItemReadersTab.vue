<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 Portal readers of a confidential agenda item (portal-voting-record-and-
 confidential-papers, REQ-PSH-002). The secretariat, or the chair or secretary
 of the meeting, adds a person without a Nextcloud account by BSN or KvK
 number (Lezers toevoegen); portaliq provisions the portal account and decidiq
 keeps only its reference. The server decides who may; this widget shows the
 refusal it answers. The rules live in src/utils/confidentialReaders.js.
 @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
-->
<template>
	<div class="confidential-readers" data-testid="confidential-readers">
		<NcLoadingIcon v-if="loading" :size="24" />
		<template v-else>
			<p
				v-if="!open"
				class="confidential-readers__hint"
				data-testid="confidential-readers-public">
				{{
					t(
						'decidiq',
						'This item is public. Make it confidential to give people without an account its papers on the portal.',
					)
				}}
			</p>
			<p
				v-if="readers.length === 0"
				class="confidential-readers__hint"
				data-testid="confidential-readers-none">
				{{ t('decidiq', 'No one reads this item on the portal.') }}
			</p>
			<ul v-else class="confidential-readers__list">
				<li
					v-for="reader in readers"
					:key="reader.id"
					class="confidential-readers__reader"
					data-testid="confidential-readers-reader">
					<span>{{ reader.name }}</span>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('decidiq', 'Remove {name}', { name: reader.name })"
						data-testid="confidential-readers-remove"
						@click="remove(reader.id)">
						{{ t('decidiq', 'Remove') }}
					</NcButton>
				</li>
			</ul>

			<form
				v-if="open"
				class="confidential-readers__form"
				data-testid="confidential-readers-form"
				@submit.prevent="add">
				<h4 class="confidential-readers__title">
					{{ t('decidiq', 'Add readers') }}
				</h4>
				<NcSelect
					v-model="person"
					:options="options"
					label="label"
					:reduce="(option) => option.id"
					:inputLabel="t('decidiq', 'Person')"
					:disabled="busy"
					data-testid="confidential-readers-person" />
				<div class="confidential-readers__types">
					<NcCheckboxRadioSwitch
						v-model="identityType"
						type="radio"
						value="bsn"
						name="decidiq-reader-identity">
						{{ t('decidiq', 'BSN') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="identityType"
						type="radio"
						value="kvk"
						name="decidiq-reader-identity">
						{{ t('decidiq', 'KvK number') }}
					</NcCheckboxRadioSwitch>
				</div>
				<NcTextField
					v-model="number"
					:label="identityType === 'kvk' ? t('decidiq', 'KvK number') : t('decidiq', 'BSN')"
					autocomplete="off"
					inputmode="numeric"
					:disabled="busy"
					data-testid="confidential-readers-number" />
				<p
					class="confidential-readers__hint">
					{{
						t(
							'decidiq',
							'The number goes to the portal to set up the account and is not kept here.',
						)
					}}
				</p>
				<NcButton
					type="submit"
					variant="primary"
					:disabled="busy || !person || number === ''"
					data-testid="confidential-readers-add">
					{{ t('decidiq', 'Add reader') }}
				</NcButton>
			</form>

			<p
				v-if="error"
				class="confidential-readers__error"
				role="alert"
				data-testid="confidential-readers-error">
				{{ error }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	addBody,
	personOptions,
	readerRows,
	readersOpen,
	readersUrl,
} from '../../utils/confidentialReaders.js'
import { objectIdLoader } from './objectIdLoader.js'

const OBJECTS = '/apps/openregister/api/objects/decidiq'

export default {
	name: 'AgendaItemReadersTab',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	mixins: [objectIdLoader],

	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			item: null,
			persons: [],
			person: null,
			identityType: 'bsn',
			number: '',
		}
	},

	computed: {
		/** @return {boolean} Whether readers can be added. */
		open() {
			return readersOpen(this.item)
		},

		/** @return {Array<{id: string, name: string}>} The readers with names. */
		readers() {
			return readerRows(this.item, this.persons)
		},

		/** @return {Array<{id: string, label: string}>} The people to pick from. */
		options() {
			return personOptions(this.item, this.persons)
		},
	},

	methods: {
		/** Read the item and the people. */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const [item, persons] = await Promise.all([
					axios.get(`${OBJECTS}/agenda-item/${encodeURIComponent(this.objectId)}`),
					axios.get(`${OBJECTS}/person`, { params: { _limit: 500 } }),
				])
				this.item = item.data || null
				this.persons = persons.data?.results || []
			} catch {
				this.error = this.t('decidiq', 'The readers could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/** Add the picked person as a reader. */
		async add() {
			await this.send(() =>
				axios.post(
					readersUrl(this.objectId),
					addBody(this.person, this.identityType, this.number),
				),
			)
			if (!this.error) {
				this.person = null
				this.number = ''
			}
		},

		/**
		 * Remove one reader.
		 *
		 * @param {string} personId The reader.
		 */
		async remove(personId) {
			await this.send(() => axios.delete(readersUrl(this.objectId, personId)))
		},

		/**
		 * Run one change and show the item as the server left it.
		 *
		 * @param {() => Promise<{data: object}>} request The request.
		 */
		async send(request) {
			this.busy = true
			this.error = ''
			try {
				const { data } = await request()
				this.item = {
					...(this.item || {}),
					authorisedPersons: data?.authorisedPersons || [],
				}
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| this.t('decidiq', 'Try again in a moment.')
			} finally {
				this.number = ''
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.confidential-readers {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.confidential-readers__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.confidential-readers__reader {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.confidential-readers__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 480px;
}

.confidential-readers__types {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 4);
}

.confidential-readers__title {
	font-weight: bold;
}

.confidential-readers__hint {
	color: var(--color-text-maxcontrast);
}

.confidential-readers__error {
	color: var(--color-error-text);
}
</style>
