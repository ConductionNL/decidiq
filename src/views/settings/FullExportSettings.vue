<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Admin settings panel: export all data (platform-full-data-export). Queues a
 background job that builds one archive with every record per type, the
 files of meetings, agenda items and decisions, and a manifest; the
 administrator gets a notification with the download link.

 Rendered by the Nextcloud settings framework via AdminSettings.php, not the
 in-app router.

 @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
-->
<template>
	<div class="decidiq-full-export" data-testid="full-export-settings">
		<h3 class="decidiq-full-export__title">
			{{ t('decidiq', 'Export all data') }}
		</h3>
		<p>
			{{
				t(
					'decidiq',
					'Download every record of this app per record type, with the files of meetings, agenda items and decisions. Preparing it takes a while; you get a notification with the download link.',
				)
			}}
		</p>

		<CnNoteCard v-if="error" type="error" :title="t('decidiq', 'Export failed')">
			{{ error }}
		</CnNoteCard>
		<CnNoteCard
			v-if="queued"
			type="success"
			:title="t('decidiq', 'The export is being prepared')">
			{{ t('decidiq', 'You get a notification when it is ready.') }}
		</CnNoteCard>

		<p v-if="latestPath" data-testid="full-export-latest">
			<a :href="latestUrl">
				{{
					t('decidiq', 'Download the export of {date} ({size})', {
						date: latestDate,
						size: latestSize,
					})
				}}
			</a>
		</p>

		<div>
			<NcButton
				variant="primary"
				:disabled="starting"
				data-testid="full-export-start"
				@click="start">
				{{ t('decidiq', 'Export all data') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { exportDownloadPath, exportSize } from '../../utils/fullExport.js'

export default {
	name: 'FullExportSettings',
	components: { CnNoteCard, NcButton },
	data() {
		return {
			starting: false,
			queued: false,
			error: '',
			latest: null,
		}
	},

	computed: {
		/** @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data */
		latestPath() {
			return exportDownloadPath(this.latest?.name)
		},

		/** @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data */
		latestUrl() {
			return generateUrl(this.latestPath)
		},

		/** @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data */
		latestDate() {
			return new Date(this.latest?.createdAt).toLocaleString()
		},

		/** @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data */
		latestSize() {
			return exportSize(this.latest?.size)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/** @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data */
		async load() {
			try {
				const res = await fetch(
					generateUrl('/apps/decidiq/api/export/full'),
					{ headers: { Accept: 'application/json' } },
				)
				const body = await res.json().catch(() => ({}))
				this.latest = res.ok ? body.export : null
			} catch {
				this.latest = null
			}
		},

		/** @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data */
		async start() {
			this.starting = true
			this.queued = false
			this.error = ''
			try {
				const res = await fetch(
					generateUrl('/apps/decidiq/api/export/full'),
					{
						method: 'POST',
						headers: {
							Accept: 'application/json',
							requesttoken: window.OC?.requestToken,
						},
					},
				)
				const body = await res.json().catch(() => ({}))
				if (!res.ok) {
					throw new Error(
						body.message
							|| this.t('decidiq', 'The export could not be started.'),
					)
				}
				this.queued = true
			} catch (e) {
				this.error = e.message
			} finally {
				this.starting = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-full-export {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 700px;
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.decidiq-full-export__title {
	margin: 0;
	font-weight: bold;
}
</style>
