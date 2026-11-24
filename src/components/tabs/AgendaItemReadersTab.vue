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
				v-if="audience"
				class="confidential-readers__audience"
				data-testid="confidential-readers-audience">
				{{
					audience.bodyName
						? t('decidiq', 'Only for members of {body} and the people the clerk adds.', { body: audience.bodyName })
						: t('decidiq', 'Only for members of the meeting\'s body and the people the clerk adds.')
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
	closedItemAudience,
	personOptions,
	readerRows,
	readersOpen,
	readersUrl,
	relationId,
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
			body: null,
			persons: [],
			person: null,
			identityType: 'bsn',
			number: '',
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether readers can be added.
		 *
		 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
		 */
		open() {
			return readersOpen(this.item)
		},

		/**
		 * @return {Array<{id: string, name: string}>} The readers with names.
		 *
		 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
		 */
		readers() {
			return readerRows(this.item, this.persons)
		},

		/**
		 * @return {Array<{id: string, label: string}>} The people to pick from.
		 *
		 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
		 */
		options() {
			return personOptions(this.item, this.persons)
		},

		/**
		 * @return {{bodyName: string}|null} Who may read the item while it is closed.
		 *
		 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public
		 */
		audience() {
			return closedItemAudience(this.item, this.body)
		},
	},

	methods: {
		/**
		 * Read the item and the people.
		 *
		 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
		 */
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
			await this.loadBody()
		},

		/**
		 * Read the governance body of a closed item's meeting, for its name.
		 * Fail-soft: without it the line names no body.
		 *
		 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public
		 */
		async loadBody() {
			this.body = null
			const meetingId = relationId(this.item?.meeting)
			if (!readersOpen(this.item) || !meetingId) return
			try {
				const meeting = await axios.get(`${OBJECTS}/meeting/${encodeURIComponent(meetingId)}`)
				const bodyId = relationId(meeting.data?.governanceBody)
				if (!bodyId) return
				const body = await axios.get(`${OBJECTS}/governance-body/${encodeURIComponent(bodyId)}`)
				this.body = body.data || null
			} catch {
				this.body = null
			}
		},

		/**
		 * Add the picked person as a reader.
		 *
		 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
		 */
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
		 *
		 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
		 */
		async remove(personId) {
			await this.send(() => axios.delete(readersUrl(this.objectId, personId)))
		},

		/**
		 * Run one change and show the item as the server left it.
		 *
		 * @param {() => Promise<{data: object}>} request The request.
		 *
		 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
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

.confidential-readers__audience {
	font-weight: bold;
}

.confidential-readers__error {
	color: var(--color-error-text);
}
</style>
