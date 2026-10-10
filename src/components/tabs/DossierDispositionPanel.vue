<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Archival dossier widget: where the closed dossier goes (transfer or
 destruction, by its Selectielijst category), handing it to OpenRegister's
 list, reading back what OpenRegister did, and filing OpenRegister's
 destruction certificate. Without an e-depot connection it says so and points
 to OpenRegister's settings; it never builds a package itself.

 @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
-->
<template>
	<div class="decidiq-tab" data-testid="dossier-disposition">
		<p v-if="loading" class="decidiq-tab__loading">
			{{ t('decidiq', 'Reading the archive route…') }}
		</p>
		<CnNoteCard
			v-else-if="error"
			type="warning"
			:title="t('decidiq', 'Archive route')">
			{{ error }}
		</CnNoteCard>
		<template v-else>
			<p data-testid="dossier-disposition-route">
				{{ routeLabel }}
			</p>
			<CnNoteCard
				v-if="state === 'transfer-unavailable'"
				type="info"
				data-testid="dossier-transfer-unavailable"
				:title="t('decidiq', 'Automated transfer is unavailable')">
				{{
					t(
						'decidiq',
						'OpenRegister has no e-depot connection, so the dossier stays closed here. Set the connection up in the OpenRegister settings.',
					)
				}}
				<a :href="described.settingsUrl">{{
					t('decidiq', 'Open the OpenRegister settings')
				}}</a>
			</CnNoteCard>
			<p v-else-if="state === 'forming'" class="decidiq-tab__empty">
				{{
					t(
						'decidiq',
						'Close the dossier first; only a closed dossier goes to the archive or to destruction.',
					)
				}}
			</p>
			<p v-else-if="state === 'no-category'" class="decidiq-tab__empty">
				{{
					t(
						'decidiq',
						'The dossier schema names no Selectielijst category, so it cannot be routed.',
					)
				}}
			</p>
			<p v-if="notice" role="status">
				{{ notice }}
			</p>
			<div class="decidiq-tab__actions">
				<NcButton
					v-if="state === 'ready'"
					variant="primary"
					:disabled="busy"
					data-testid="dossier-propose"
					@click="act(dispositionUrl)">
					{{
						described.route === 'transfer'
							? t('decidiq', 'Send to the archive')
							: t('decidiq', 'Propose for destruction')
					}}
				</NcButton>
				<NcButton
					v-if="state === 'on-list'"
					:disabled="busy"
					data-testid="dossier-outcome"
					@click="act(outcomeUrl)">
					{{ t('decidiq', "Check OpenRegister's outcome") }}
				</NcButton>
				<NcButton
					v-if="certificateReady"
					:disabled="busy"
					data-testid="dossier-certificate"
					@click="act(certificateUrl)">
					{{ t('decidiq', 'File the destruction certificate') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import {
	canRenderCertificate,
	certificateUrl,
	dispositionUrl,
	outcomeUrl,
	panelState,
} from '../../utils/dossierDisposition.js'

export default {
	name: 'DossierDispositionPanel',

	components: { CnNoteCard, NcButton },

	props: {
		objectId: { type: [String, Number], default: '' },
		objectType: { type: String, default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			notice: '',
			described: {},
		}
	},

	computed: {
		/** @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot */
		state() {
			return panelState(this.described)
		},

		/** @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering */
		certificateReady() {
			return canRenderCertificate(this.described)
		},

		/** @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice */
		routeLabel() {
			const category = this.described.category || '?'
			if (this.described.route === 'transfer') {
				return this.t(
					'decidiq',
					'Selectielijst category {category}: kept, transferred to the archive (overbrenging).',
					{ category },
				)
			}
			if (this.described.route === 'destruction') {
				return this.t(
					'decidiq',
					'Selectielijst category {category}: destroyed when its retention period has passed (vernietiging).',
					{ category },
				)
			}
			return this.t('decidiq', 'No archive route.')
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		dispositionUrl,
		outcomeUrl,
		certificateUrl,

		/**
		 * Read where the dossier goes.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const body = await this.call(
					'GET',
					dispositionUrl(String(this.objectId)),
				)
				this.described = body.dossier || {}
			} catch (e) {
				this.error = e.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run one dossier action, then read the route again.
		 *
		 * @param {Function} urlFor Builds the action's URL from the dossier id
		 * @return {Promise<void>}
		 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
		 */
		async act(urlFor) {
			this.busy = true
			this.notice = ''
			try {
				await this.call('POST', urlFor(String(this.objectId)))
				this.notice = this.t(
					'decidiq',
					"Done. OpenRegister has the dossier's records.",
				)
				await this.refresh()
			} catch (e) {
				this.notice = e.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * One request to the dossier API; a refusal throws its message.
		 *
		 * @param {string} method The HTTP method
		 * @param {string} path   The app-relative path
		 * @return {Promise<object>}
		 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
		 */
		async call(method, path) {
			const response = await fetch(generateUrl(path), {
				method,
				headers: {
					requesttoken: window.OC?.requestToken,
					'Content-Type': 'application/json',
				},
			})
			const body = await response.json().catch(() => ({}))
			if (!response.ok) {
				throw new Error(
					body.message
						|| this.t('decidiq', 'The archive route could not be read.'),
				)
			}
			return body
		},
	},
}
</script>

<style scoped>
.decidiq-tab__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 2);
}
</style>
