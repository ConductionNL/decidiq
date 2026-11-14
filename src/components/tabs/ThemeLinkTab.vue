<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Themes on an agenda item or decision page (publication-theme-pages,
 REQ-THP-001): the themes this object belongs to, and Add to theme, which
 opens AddToThemeModal and stores the object in the chosen theme's link array
 through the OpenRegister object API (ADR-022). The theme's own write rule
 (secretariat and administrators) decides whether the store is accepted.

 @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme
-->
<template>
	<div class="decidiq-theme-links" data-testid="theme-links">
		<NcLoadingIcon v-if="loading" :size="20" />
		<template v-else>
			<ul v-if="holding.length > 0" class="decidiq-theme-links__list">
				<li v-for="theme in holding" :key="idOf(theme)" data-testid="theme-links-entry">
					{{ theme.title }}
				</li>
			</ul>
			<p v-else class="decidiq-theme-links__empty" data-testid="theme-links-empty">
				{{ t('decidiq', 'Not part of a theme.') }}
			</p>
			<CnNoteCard
				v-if="error"
				type="error"
				data-testid="theme-links-error"
				:title="t('decidiq', 'Could not load the themes')">
				{{ error }}
			</CnNoteCard>
			<NcButton data-testid="theme-links-add" @click="open = true">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidiq', 'Add to theme') }}
			</NcButton>
		</template>

		<AddToThemeModal
			v-if="open"
			ref="modal"
			:themes="available"
			@close="open = false"
			@confirm="add" />
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import AddToThemeModal from '../../modals/AddToThemeModal.vue'
import { themeIdOf, themesHolding, themeUrl, withLink } from '../../utils/themes.js'

export default {
	name: 'ThemeLinkTab',
	components: { AddToThemeModal, CnNoteCard, NcButton, NcLoadingIcon, Plus },
	props: {
		objectId: { type: [String, Number], default: '' },
		/** The kind of the page's object: agendaItem or decision. */
		kind: { type: String, default: 'agendaItem' },
	},

	data() {
		return {
			loading: false,
			error: '',
			themes: [],
			open: false,
		}
	},

	computed: {
		holding() {
			return themesHolding(this.themes, this.kind, String(this.objectId))
		},

		available() {
			const holding = this.holding.map(themeIdOf)
			return this.themes.filter((theme) => !holding.includes(themeIdOf(theme)))
		},
	},

	watch: {
		objectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		idOf(theme) {
			return themeIdOf(theme)
		},

		/**
		 * Read the themes to show and to choose from.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme
		 */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const response = await fetch(themeUrl(), {
					headers: { Accept: 'application/json', requesttoken: window.OC?.requestToken },
				})
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					throw new Error(data.message || '')
				}
				this.themes = Array.isArray(data.results) ? data.results : []
			} catch (e) {
				this.themes = []
				this.error = e.message || this.t('decidiq', 'The themes could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Store the page's object in the chosen theme's link array.
		 *
		 * @param {object} theme The chosen theme.
		 * @return {Promise<void>}
		 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme
		 */
		async add(theme) {
			try {
				const response = await fetch(themeUrl(themeIdOf(theme)), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
						requesttoken: window.OC?.requestToken,
					},
					body: JSON.stringify(withLink(theme, this.kind, String(this.objectId))),
				})
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					throw new Error(data.message || this.t('decidiq', 'The theme was not changed.'))
				}
				this.open = false
				await this.load()
			} catch (e) {
				this.$refs.modal?.setError(e.message)
			}
		},
	},
}
</script>

<style scoped>
.decidiq-theme-links {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
}

.decidiq-theme-links__list {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 4);
}

.decidiq-theme-links__empty {
	color: var(--color-text-maxcontrast);
}
</style>
