<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.2
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Besluit')"
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

			<CnDetailCard
				v-if="!object.isPublished && canPublish"
				:title="t('decidesk', 'Publiceren op ledenportaal')">
				<p>{{ t('decidesk', 'Publiceer deze beslissing op de ledenportal zodat leden deze kunnen zien.') }}</p>
				<NcButton
					type="primary"
					:disabled="publishing"
					@click="showPublishConfirm = true">
					{{ t('decidesk', 'Publiceren op ledenportaal') }}
				</NcButton>
				<p v-if="publishError" class="decidesk-publish-error">
					{{ publishError }}
				</p>
			</CnDetailCard>

			<CnDetailCard
				v-if="object.isPublished"
				:title="t('decidesk', 'Gedeeld via ledenportaal')">
				<div class="decidesk-share-link">
					<NcTextField
						:value="shareUrl"
						:placeholder="t('decidesk', 'Share URL')"
						disabled />
					<NcButton
						type="secondary"
						@click="copyShareLink">
						{{ t('decidesk', 'Kopieer') }}
					</NcButton>
					<NcButton
						type="secondary"
						:href="shareUrl"
						target="_blank">
						{{ t('decidesk', 'Bekijk publieke pagina') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<CnDetailCard :title="t('decidesk', 'Eigenschappen')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>
		</template>

		<template #relations>
			<CnDetailCard :title="t('decidesk', 'Gerelateerde motie')">
				<p v-if="!object.relations?.motion" class="decidesk-empty">
					{{ t('decidesk', 'Geen gerelateerde motie.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li>
						<router-link :to="{ name: 'MotionDetail', params: { id: object.relations.motion.id || object.relations.motion } }">
							{{ object.relations.motion.title || object.relations.motion.id || object.relations.motion }}
						</router-link>
					</li>
				</ul>
			</CnDetailCard>
			<CnDetailCard :title="t('decidesk', 'Gerelateerde actiepunten')">
				<p v-if="!object.relations?.['action-item']?.length" class="decidesk-empty">
					{{ t('decidesk', 'Geen gerelateerde actiepunten.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="ai in object.relations['action-item']" :key="ai.id || ai">
						<router-link :to="{ name: 'ActionItemDetail', params: { id: ai.id || ai } }">
							{{ ai.title || ai.id || ai }}
						</router-link>
					</li>
				</ul>
			</CnDetailCard>
		</template>

		<template #sidebar>
			<CnObjectSidebar :object="object" :loading="loading" />
		</template>

		<template #edit-dialog>
			<CnSchemaFormDialog
				v-if="editing"
				:schema="schema"
				:object="object"
				:title="t('decidesk', 'Besluit bewerken')"
				:object-store="objectStore"
				object-type="decision"
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

		<NcDialog
			v-if="showPublishConfirm"
			:name="t('decidesk', 'Bevestig publicatie')"
			:open="showPublishConfirm"
			@update:open="showPublishConfirm = false">
			<template #default>
				<p>{{ t('decidesk', 'Weet u zeker dat u deze beslissing wilt publiceren op de ledenportal?') }}</p>
			</template>
			<template #actions>
				<NcButton
					type="primary"
					:disabled="publishing"
					@click="publishToPortal">
					{{ t('decidesk', 'Publiceren') }}
				</NcButton>
				<NcButton @click="showPublishConfirm = false">
					{{ t('decidesk', 'Annuleren') }}
				</NcButton>
			</template>
		</NcDialog>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { useObjectStore } from '../store/store.js'
import { useDecisionStore } from '../store/modules/decisions.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'DecisionDetail',
	components: { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, NcButton, NcDialog, NcTextField },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const decisionStore = useDecisionStore()
		const detailView = useDetailView('decision', props.id, {
			objectStore,
			listRouteName: 'Decisions',
			detailRouteName: 'DecisionDetail',
		})
		return { ...detailView, objectStore, decisionStore }
	},
	data() {
		return {
			publishing: false,
			publishError: null,
			isSubscribed: false,
			notificationError: null,
			shareUrl: null,
			showPublishConfirm: false,
		}
	},
	mounted() {
		this.fetchSubscriptionStatus()
		if (this.object?.isPublished) {
			this.fetchShareLink()
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('decision')
		},
		canPublish() {
			return this.object?.outcome === 'adopted' && !this.object?.isPublished
		},
		propertyItems() {
			const outcomeLabel = this.object.outcome === 'adopted'
				? this.t('decidesk', 'Aangenomen')
				: this.object.outcome === 'rejected'
					? this.t('decidesk', 'Afgewezen')
					: this.object.outcome
			const publishedLabel = this.object.isPublished
				? this.t('decidesk', 'Ja') + (this.object.publishedAt ? ' (' + this.formatDate(this.object.publishedAt) + ')' : '')
				: this.t('decidesk', 'Nee')
			return [
				{ label: this.t('decidesk', 'Besluit'), value: this.object.text },
				{ label: this.t('decidesk', 'Uitkomst'), value: outcomeLabel },
				{ label: this.t('decidesk', 'Besluitdatum'), value: this.formatDate(this.object.decisionDate) },
				{ label: this.t('decidesk', 'Juridische grondslag'), value: this.object.legalBasis },
				{ label: this.t('decidesk', 'Gepubliceerd'), value: publishedLabel },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('decision', this.id)
		},
		async fetchSubscriptionStatus() {
			try {
				const url = generateUrl(`/apps/decidesk/api/notifications/decision/${this.id}/subscriptions`)
				const response = await axios.get(url)
				this.isSubscribed = response.data.subscribed || false
			} catch (error) {
				console.error('Failed to fetch subscription status:', error)
			}
		},
		async subscribe() {
			this.notificationError = null
			try {
				const url = generateUrl(`/apps/decidesk/api/notifications/decision/${this.id}/subscriptions`)
				await axios.post(url)
				this.isSubscribed = true
			} catch (error) {
				this.notificationError = error.message || this.t('decidesk', 'Abonnement mislukt.')
			}
		},
		async unsubscribe() {
			this.notificationError = null
			try {
				const url = generateUrl(`/apps/decidesk/api/notifications/decision/${this.id}/subscriptions`)
				await axios.delete(url)
				this.isSubscribed = false
			} catch (error) {
				this.notificationError = error.message || this.t('decidesk', 'Abonnement verwijderen mislukt.')
			}
		},
		async fetchShareLink() {
			try {
				const url = generateUrl(`/apps/decidesk/api/decisions/${this.id}/share-link`)
				const response = await axios.get(url)
				this.shareUrl = response.data.shareUrl || null
			} catch (error) {
				console.error('Failed to fetch share link:', error)
			}
		},
		async publishToPortal() {
			this.publishing = true
			this.publishError = null
			try {
				const url = generateUrl(`/apps/decidesk/api/decisions/${this.id}/publish`)
				const response = await axios.post(url)
				this.shareUrl = response.data.shareUrl || null
				await this.objectStore.fetchObject('decision', this.id)
				this.showPublishConfirm = false
				showSuccess(this.t('decidesk', 'Beslissing gepubliceerd.'))
			} catch (error) {
				this.publishError = error.message || this.t('decidesk', 'Publiceren mislukt.')
				showError(this.publishError)
			} finally {
				this.publishing = false
			}
		},
		async copyShareLink() {
			if (!this.shareUrl) return
			try {
				await navigator.clipboard.writeText(this.shareUrl)
				showSuccess(this.t('decidesk', 'Link gekopieerd'))
			} catch (error) {
				showError(this.t('decidesk', 'Kopiëren naar klembord mislukt.'))
			}
		},
		formatDate(dateStr) {
			if (!dateStr) return '—'
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidesk-relations {
	list-style: none;
	margin: 0;
	padding: 0;
}

.decidesk-relations li {
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-relations li:last-child {
	border-bottom: none;
}

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

.decidesk-share-link {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	margin-top: 12px;
}

.decidesk-share-link :deep(.nc-text-field) {
	flex: 1;
}

.decidesk-publish-action {
	margin-bottom: 12px;
}

.decidesk-publish-error {
	color: var(--color-error);
	margin: 4px 0 0;
	font-size: 0.875em;
}
</style>
