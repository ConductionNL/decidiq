<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
-->
<template>
	<div>
		<CnDetailPage
			:object="object"
			:loading="loading"
			:title="object.title || t('decidesk', 'Notulen')"
			:show-sidebar="true"
			@edit="editing = true"
			@delete="showDeleteDialog = true">
			<template #properties>
				<CnDetailCard :title="t('decidesk', 'Notificaties')">
					<div class="decidesk-notification-toggle">
						<p>{{ t('decidesk', isSubscribed ? 'Notificaties ingeschakeld' : 'Notificaties inschakelen') }}</p>
						<NcButton
							v-if="!isSubscribed"
							type="secondary"
							@click="subscribe">
							{{ t('decidesk', 'Abonneren') }}
						</NcButton>
						<NcButton
							v-else
							type="secondary"
							@click="unsubscribe">
							{{ t('decidesk', 'Abonnement verwijderen') }}
						</NcButton>
						<p v-if="notificationError" class="decidesk-notification-error">
							{{ notificationError }}
						</p>
					</div>
				</CnDetailCard>

				<CnDetailCard :title="t('decidesk', 'Goedkeuringen')">
					<div class="decidesk-approvals">
						<div class="decidesk-approval-row">
							<div class="decidesk-approval-info">
								<strong>{{ t('decidesk', 'Voorzitter') }}</strong>
								<span v-if="approvalStatus.chairApproved" class="decidesk-approval-status approved">
									{{ approvalStatus.chairUserId }} - {{ formatDate(approvalStatus.chairSignedAt) }}
								</span>
								<span v-else class="decidesk-approval-status">
									{{ t('decidesk', 'Wacht op goedkeuring voorzitter') }}
								</span>
							</div>
						</div>
						<div class="decidesk-approval-row">
							<div class="decidesk-approval-info">
								<strong>{{ t('decidesk', 'Secretaris') }}</strong>
								<span v-if="approvalStatus.secretaryApproved" class="decidesk-approval-status approved">
									{{ approvalStatus.secretaryUserId }} - {{ formatDate(approvalStatus.secretarySignedAt) }}
								</span>
								<span v-else class="decidesk-approval-status">
									{{ t('decidesk', 'Wacht op goedkeuring secretaris') }}
								</span>
							</div>
						</div>
					</div>
					<div v-if="canApprove" class="decidesk-approval-action">
						<NcButton
							type="primary"
							:disabled="approvingMinutes"
							@click="approveMinutes">
							{{ t('decidesk', 'Goedkeuren') }}
						</NcButton>
						<p v-if="approvalError" class="decidesk-error">
							{{ approvalError }}
						</p>
					</div>
				</CnDetailCard>

				<CnDetailCard :title="t('decidesk', 'Lifecycle')">
					<CnTimelineStages
						:stages="lifecycleStages"
						:current-stage="object.lifecycle || 'draft'" />
					<div class="decidesk-transitions">
						<NcButton
							v-if="object.lifecycle === 'draft'"
							type="primary"
							:disabled="transitioning"
							@click="transitionLifecycle('review')">
							{{ t('decidesk', 'Ter beoordeling indienen') }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'approved' && isSecretary"
							type="primary"
							:disabled="transitioning"
							@click="transitionLifecycle('signed')">
							{{ t('decidesk', 'Ondertekenen') }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'signed' && isSecretary"
							type="primary"
							:disabled="transitioning"
							@click="showPublishConfirm = true">
							{{ t('decidesk', 'Publiceren') }}
						</NcButton>
						<NcButton
							v-if="object.lifecycle === 'draft'"
							:disabled="generating"
							@click="generateDraft">
							{{ t('decidesk', 'Concept genereren') }}
						</NcButton>
						<p v-if="transitionError" class="decidesk-error">
							{{ transitionError }}
						</p>
						<p v-if="generateError" class="decidesk-error">
							{{ generateError }}
						</p>
					</div>
				</CnDetailCard>
				<CnDetailCard :title="t('decidesk', 'Eigenschappen')">
					<CnDetailGrid :items="propertyItems" />
				</CnDetailCard>

				<MinutesVersionPanel :minutes-id="id" />
			</template>

			<template #sidebar>
				<CnObjectSidebar :object="object" :loading="loading" />
			</template>

			<template #edit-dialog>
				<CnSchemaFormDialog
					v-if="editing"
					:schema="schema"
					:object="object"
					:title="t('decidesk', 'Notulen bewerken')"
					:object-store="objectStore"
					object-type="minutes"
					@close="editing = false"
					@saved="onEditSaved" />
			</template>

			<template #delete-dialog>
				<CnDeleteDialog
					v-if="showDeleteDialog"
					:object-name="object.title || ''"
					@confirm="confirmDelete"
					@close="showDeleteDialog = false" />
			</template>
		</CnDetailPage>

		<NcDialog
			v-if="showDraftModal"
			:name="t('decidesk', 'Concept gegenereerd')"
			:open="showDraftModal"
			@update:open="showDraftModal = false">
			<template #default>
				<p>{{ t('decidesk', 'Bekijk het gegenereerde concept. Klik op "Toepassen" om de inhoud te overschrijven.') }}</p>
				<pre class="decidesk-draft-preview">{{ draftPreview }}</pre>
			</template>
			<template #actions>
				<NcButton type="primary" @click="applyDraft">
					{{ t('decidesk', 'Toepassen') }}
				</NcButton>
				<NcButton @click="showDraftModal = false">
					{{ t('decidesk', 'Annuleren') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog
			v-if="showPublishConfirm"
			:name="t('decidesk', 'Bevestig publicatie')"
			:open="showPublishConfirm"
			@update:open="showPublishConfirm = false">
			<template #default>
				<p>{{ t('decidesk', 'Weet u zeker dat u deze notulen wilt publiceren?') }}</p>
			</template>
			<template #actions>
				<NcButton type="primary" :disabled="transitioning" @click="transitionLifecycle('published')">
					{{ t('decidesk', 'Publiceren') }}
				</NcButton>
				<NcButton @click="showPublishConfirm = false">
					{{ t('decidesk', 'Annuleren') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnTimelineStages, useDetailView } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { useObjectStore } from '../store/store.js'
import MinutesVersionPanel from '../components/MinutesVersionPanel.vue'

export default {
	name: 'MinutesDetail',
	components: { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnTimelineStages, NcButton, NcDialog, MinutesVersionPanel },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('minutes', props.id, {
			objectStore,
			listRouteName: 'Minutes',
			detailRouteName: 'MinutesDetail',
		})
		return { ...detailView, objectStore }
	},
	data() {
		return {
			transitioning: false,
			generating: false,
			approvingMinutes: false,
			transitionError: null,
			generateError: null,
			approvalError: null,
			showDraftModal: false,
			showPublishConfirm: false,
			draftPreview: '',
			isSubscribed: false,
			notificationError: null,
			approvalStatus: {
				chairApproved: false,
				chairUserId: null,
				chairSignedAt: null,
				secretaryApproved: false,
				secretaryUserId: null,
				secretarySignedAt: null,
				approvals: [],
			},
			lifecycleStages: [
				{ key: 'draft', label: this.t('decidesk', 'Concept') },
				{ key: 'review', label: this.t('decidesk', 'Ter beoordeling') },
				{ key: 'approved', label: this.t('decidesk', 'Goedgekeurd') },
				{ key: 'signed', label: this.t('decidesk', 'Ondertekend') },
				{ key: 'published', label: this.t('decidesk', 'Gepubliceerd') },
			],
		}
	},
	mounted() {
		this.fetchSubscriptionStatus()
		this.fetchApprovalStatus()
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('minutes')
		},
		canApprove() {
			return this.object?.lifecycle === 'review' && (this.isChair || this.isSecretary)
		},
		isChair() {
			return false // TODO: implement role check
		},
		isSecretary() {
			return false // TODO: implement role check
		},
		/**
		 * Returns the single available next transition based on the current lifecycle stage.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
		 */
		availableTransitions() {
			const map = {
				draft: { to: 'review', label: this.t('decidesk', 'Ter beoordeling indienen') },
				review: { to: 'approved', label: this.t('decidesk', 'Goedkeuren') },
				approved: { to: 'signed', label: this.t('decidesk', 'Ondertekenen') },
				signed: { to: 'published', label: this.t('decidesk', 'Publiceren') },
			}
			const current = this.object?.lifecycle || 'draft'
			return map[current] ? [map[current]] : []
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Status'), value: this.object.lifecycle },
				{ label: this.t('decidesk', 'Versie'), value: this.object.version },
				{ label: this.t('decidesk', 'Goedgekeurd op'), value: this.formatDate(this.object.approvedAt) },
				{ label: this.t('decidesk', 'Ondertekend door'), value: (this.object.signedBy || []).join(', ') },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('minutes', this.id)
		},
		async fetchSubscriptionStatus() {
			try {
				const url = generateUrl(`/apps/decidesk/api/notifications/minutes/${this.id}/subscriptions`)
				const response = await axios.get(url)
				this.isSubscribed = response.data.subscribed || false
			} catch (error) {
				console.error('Failed to fetch subscription status:', error)
			}
		},
		async subscribe() {
			this.notificationError = null
			try {
				const url = generateUrl(`/apps/decidesk/api/notifications/minutes/${this.id}/subscriptions`)
				await axios.post(url)
				this.isSubscribed = true
			} catch (error) {
				this.notificationError = error.message || this.t('decidesk', 'Abonnement mislukt.')
			}
		},
		async unsubscribe() {
			this.notificationError = null
			try {
				const url = generateUrl(`/apps/decidesk/api/notifications/minutes/${this.id}/subscriptions`)
				await axios.delete(url)
				this.isSubscribed = false
			} catch (error) {
				this.notificationError = error.message || this.t('decidesk', 'Abonnement verwijderen mislukt.')
			}
		},
		async fetchApprovalStatus() {
			try {
				const url = generateUrl(`/apps/decidesk/api/minutes/${this.id}/approval-status`)
				const response = await axios.get(url)
				this.approvalStatus = response.data || this.approvalStatus
			} catch (error) {
				console.error('Failed to fetch approval status:', error)
			}
		},
		async approveMinutes() {
			this.approvingMinutes = true
			this.approvalError = null
			const role = this.isChair ? 'chair' : 'secretary'
			try {
				const url = generateUrl(`/apps/decidesk/api/minutes/${this.id}/approve`)
				await axios.post(url, { role })
				await this.fetchApprovalStatus()
				await this.objectStore.fetchObject('minutes', this.id)
			} catch (error) {
				this.approvalError = error.message || this.t('decidesk', 'Goedkeuring mislukt.')
			} finally {
				this.approvingMinutes = false
			}
		},
		/**
		 * Calls the server-side lifecycle transition endpoint.
		 * signedBy is populated from the authenticated session server-side.
		 *
		 * @param {string} newLifecycle - The target lifecycle stage
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-5.2
		 */
		async transitionLifecycle(newLifecycle) {
			this.transitioning = true
			this.transitionError = null
			try {
				const url = generateUrl(`/apps/decidesk/api/minutes/${this.id}/transition`)
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ lifecycle: newLifecycle }),
				})
				if (response.ok) {
					await this.objectStore.fetchObject('minutes', this.id)
				} else {
					const err = await response.json().catch(() => ({}))
					this.transitionError = err.message || this.t('decidesk', 'Verzoek mislukt.')
				}
			} finally {
				this.transitioning = false
			}
		},
		async generateDraft() {
			this.generating = true
			this.generateError = null
			try {
				const url = generateUrl(`/apps/decidesk/api/minutes/${this.id}/generate-draft`)
				const response = await fetch(url, {
					method: 'POST',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.draftPreview = data.preview
					this.showDraftModal = true
				} else {
					const err = await response.json().catch(() => ({}))
					this.generateError = err.message || this.t('decidesk', 'Genereren mislukt.')
				}
			} finally {
				this.generating = false
			}
		},
		async applyDraft() {
			await this.objectStore.saveObject('minutes', {
				...this.object,
				content: this.draftPreview,
			})
			this.showDraftModal = false
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-notification-toggle {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.decidesk-notification-toggle p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.decidesk-notification-error {
	color: var(--color-error);
	margin: 0 !important;
	font-size: 0.875em;
}

.decidesk-approvals {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	margin-bottom: var(--default-grid-baseline);
}

.decidesk-approval-row {
	display: flex;
	align-items: center;
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-approval-row:last-child {
	border-bottom: none;
}

.decidesk-approval-info {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
}

.decidesk-approval-info strong {
	margin-bottom: 4px;
}

.decidesk-approval-status {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.decidesk-approval-status.approved {
	color: var(--color-success);
}

.decidesk-approval-action {
	margin-top: var(--default-grid-baseline);
	padding-top: var(--default-grid-baseline);
	border-top: 1px solid var(--color-border);
}

.decidesk-transitions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
	flex-wrap: wrap;
}

.decidesk-draft-preview {
	white-space: pre-wrap;
	word-break: break-word;
	font-family: var(--font-face);
	font-size: 14px;
	line-height: 1.6;
	max-height: 400px;
	overflow-y: auto;
}

.decidesk-error {
	color: var(--color-error);
	margin: 4px 0 0;
	font-size: 0.875em;
	width: 100%;
}
</style>
