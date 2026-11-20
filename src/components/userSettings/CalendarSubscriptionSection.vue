<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 User settings: the member's meetings in Outlook or on a phone
 (platform-microsoft-365-calendar-and-teams, matrix rows plt-12 and pla-04).
 Subscribing mints an OpenRegister calendar feed token over the meeting
 schema; the feed answers only the meetings the member may read and is
 generated on every refresh, so a moved or cancelled meeting follows. The
 member copies the address, sees when each subscription was made, and stops
 one, after which its address answers 404.

 @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
-->
<template>
	<div class="user-settings-section" data-testid="calendar-subscription-section">
		<p class="user-settings-section__hint">
			{{
				t(
					'decidiq',
					'Add your meetings to Outlook or the calendar on your phone. Moved and cancelled meetings follow by themselves; Outlook refreshes a subscribed calendar every few hours.',
				)
			}}
		</p>

		<NcLoadingIcon v-if="loading" :size="24" />
		<ul
			v-else-if="feeds.length"
			class="calendar-feeds"
			data-testid="calendar-subscription-list">
			<li
				v-for="feed in feeds"
				:key="feed.id"
				class="calendar-feeds__row"
				data-testid="calendar-subscription-row">
				<NcTextField
					:modelValue="feed.url"
					:label="t('decidiq', 'Calendar address')"
					readonly
					data-testid="calendar-subscription-url" />
				<p class="user-settings-section__hint">
					{{
						t('decidiq', 'In Outlook, choose Add calendar, then Subscribe from web')
					}}
				</p>
				<p class="user-settings-section__hint">
					{{
						t('decidiq', 'Made on {date}', { date: formatDate(feed.createdAt) })
					}}
				</p>
				<div class="calendar-feeds__actions">
					<NcButton
						variant="secondary"
						data-testid="calendar-subscription-copy"
						@click="copy(feed)">
						{{
							copiedId === feed.id
								? t('decidiq', 'Copied')
								: t('decidiq', 'Copy address')
						}}
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="saving"
						data-testid="calendar-subscription-stop"
						@click="stop(feed)">
						{{ t('decidiq', 'Stop this subscription') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<div class="user-settings-section__actions">
			<NcButton
				variant="primary"
				:disabled="saving || loading || !schemaId"
				data-testid="calendar-subscription-add"
				@click="subscribe">
				{{ t('decidiq', 'Subscribe in Outlook or on your phone') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { useSettingsStore } from '../../store/store.js'
import {
	feedsUrl,
	meetingFeeds,
	mintBody,
	registerUrl,
	schemaIdOf,
} from '../../utils/calendarFeeds.js'

export default {
	name: 'CalendarSubscriptionSection',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcTextField },

	data() {
		return {
			schemaId: '',
			feeds: [],
			loading: true,
			saving: false,
			copiedId: null,
			error: '',
		}
	},

	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Find the meeting schema, then list the member's subscriptions over it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const register =
					useSettingsStore().getSettings?.register || 'decidiq'
				const [registerAnswer, tokens] = await Promise.all([
					axios.get(registerUrl(register)),
					axios.get(feedsUrl()),
				])
				this.schemaId = schemaIdOf(registerAnswer.data)
				this.feeds = meetingFeeds(tokens.data?.results ?? [], this.schemaId)
			} catch {
				this.error = this.t(
					'decidiq',
					'Your calendar subscriptions could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Mint a feed token over the meeting schema and show its address.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
		 */
		async subscribe() {
			this.saving = true
			this.error = ''
			try {
				const response = await axios.post(feedsUrl(), mintBody(this.schemaId))
				this.feeds = [response.data, ...this.feeds]
			} catch {
				this.error = this.t(
					'decidiq',
					'The calendar subscription could not be made.',
				)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Revoke one subscription; its address answers 404 from then on.
		 *
		 * @param {object} feed The subscription.
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
		 */
		async stop(feed) {
			this.saving = true
			this.error = ''
			try {
				await axios.delete(feedsUrl(feed.id))
				this.feeds = this.feeds.filter((row) => row.id !== feed.id)
			} catch {
				this.error = this.t(
					'decidiq',
					'The calendar subscription could not be stopped.',
				)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Copy a subscription's address to the clipboard.
		 *
		 * @param {object} feed The subscription.
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
		 */
		async copy(feed) {
			try {
				await navigator.clipboard.writeText(feed.url)
				this.copiedId = feed.id
			} catch {
				this.error = this.t(
					'decidiq',
					'The address could not be copied. Select it and copy it yourself.',
				)
			}
		},

		/**
		 * A creation date, in the reader's locale.
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string} The date.
		 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString()
		},
	},
}
</script>

<style scoped src="./userSettingsGroups.css"></style>

<style scoped>
.calendar-feeds {
	margin: 8px 0;
	padding: 0;
	list-style: none;
}

.calendar-feeds__row {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.calendar-feeds__actions {
	display: flex;
	gap: 8px;
}
</style>
