<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-6.1
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-6.2
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-6.3
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-6.4
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-6.5
-->
<template>
	<div class="global-search" role="search" :aria-label="t('decidesk', 'Global search')">
		<div class="global-search__input-wrap">
			<Magnify :size="18" class="global-search__icon" />
			<input
				ref="searchInput"
				v-model="query"
				type="search"
				class="global-search__input"
				:placeholder="t('decidesk', 'Search meetings, motions, decisions…')"
				:aria-label="t('decidesk', 'Search across all governance data')"
				aria-autocomplete="list"
				:aria-expanded="showDropdown ? 'true' : 'false'"
				aria-controls="global-search-results"
				role="combobox"
				@input="onInput"
				@keydown.down.prevent="onArrowDown"
				@keydown.up.prevent="onArrowUp"
				@keydown.enter.prevent="onEnter"
				@keydown.escape="onEscape"
				@focus="onFocus"
				@blur="onBlur">
		</div>

		<div
			v-if="showDropdown"
			id="global-search-results"
			class="global-search__dropdown"
			role="listbox"
			:aria-label="t('decidesk', 'Search results')">
			<template v-if="searching">
				<div class="global-search__status" role="status">
					<NcLoadingIcon :size="20" />
					<span>{{ t('decidesk', 'Searching…') }}</span>
				</div>
			</template>
			<template v-else-if="results.length === 0 && hasSearched">
				<div class="global-search__status" role="status">
					{{ t('decidesk', 'Geen resultaten gevonden') }}
				</div>
			</template>
			<template v-else>
				<button
					v-for="(result, index) in results"
					:key="result.id || index"
					tabindex="-1"
					class="global-search__result"
					role="option"
					:aria-selected="index === activeIndex"
					:class="{ 'global-search__result--active': index === activeIndex }"
					@mousedown.prevent="navigateToResult(result)"
					@mouseenter="activeIndex = index">
					<component :is="getIcon(result._type)" :size="20" class="global-search__result-icon" />
					<div class="global-search__result-content">
						<span class="global-search__result-title" :title="result.title || result.displayName || result.name">
							{{ truncateTitle(result.title || result.displayName || result.name) }}
						</span>
						<span class="global-search__result-type">{{ getTypeLabel(result._type) }}</span>
					</div>
					<span v-if="result.lifecycle || result.taskStatus || result.outcome"
						class="global-search__result-badge">
						{{ result.lifecycle || result.taskStatus || result.outcome }}
					</span>
				</button>
			</template>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../store/modules/object.js'

import Magnify from 'vue-material-design-icons/Magnify.vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'

/**
 * The schema types to search across.
 *
 * @type {string[]}
 */
const SEARCH_TYPES = ['meeting', 'motion', 'decision', 'agendaItem', 'participant']

/**
 * Global search bar with floating dropdown for governance data.
 * Searches across multiple OpenRegister object types with manual debounce.
 *
 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-6.1
 */
export default {
	name: 'GlobalSearch',
	components: {
		NcLoadingIcon,
		Magnify,
		CalendarBlank,
		FileDocumentOutline,
		GavelIcon,
		AccountGroupOutline,
		FormatListBulleted,
	},

	data() {
		return {
			query: '',
			results: [],
			searching: false,
			hasSearched: false,
			showDropdown: false,
			activeIndex: -1,
			debounceTimer: null,
		}
	},

	methods: {
		/**
		 * Input handler — delegates to each useListView's onSearchInput for debounced search.
		 *
		 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-6.1
		 */
		onInput() {
			if (this.query.length < 3) {
				this.results = []
				this.hasSearched = false
				this.showDropdown = false
				clearTimeout(this.debounceTimer)
				return
			}
			clearTimeout(this.debounceTimer)
			this.debounceTimer = setTimeout(() => this.performSearch(), 400)
		},

		/**
		 * Search OpenRegister across multiple schemas.
		 *
		 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-6.2
		 */
		async performSearch() {
			this.searching = true
			this.showDropdown = true
			this.activeIndex = -1

			const objectStore = useObjectStore()

			try {
				const fetches = SEARCH_TYPES.map(async (type) => {
					const items = await objectStore.fetchObjects(type, { _search: this.query })
					return (items || []).map((item) => ({ ...item, _type: type }))
				})
				const allResults = await Promise.all(fetches)
				this.results = allResults.flat().slice(0, 10)
			} catch (error) {
				console.error('Search failed:', error.message)
				this.results = []
			} finally {
				this.searching = false
				this.hasSearched = true
			}
		},

		onFocus() {
			if (this.query.length >= 3 && this.hasSearched) {
				this.showDropdown = true
			}
		},

		onBlur() {
			setTimeout(() => {
				this.showDropdown = false
			}, 200)
		},

		/**
		 * Arrow down in search results.
		 *
		 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-6.4
		 */
		onArrowDown() {
			if (!this.showDropdown) return
			if (this.activeIndex < this.results.length - 1) {
				this.activeIndex++
			}
		},

		onArrowUp() {
			if (!this.showDropdown) return
			if (this.activeIndex > 0) {
				this.activeIndex--
			}
		},

		onEnter() {
			if (this.activeIndex >= 0 && this.activeIndex < this.results.length) {
				this.navigateToResult(this.results[this.activeIndex])
			}
		},

		/**
		 * Escape closes dropdown and returns focus to input.
		 *
		 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-6.4
		 */
		onEscape() {
			this.showDropdown = false
			this.$refs.searchInput.focus()
		},

		/**
		 * Navigate to the detail route for a search result.
		 *
		 * @param {object} result The search result object.
		 */
		navigateToResult(result) {
			const routeMap = {
				meeting: 'MeetingDetail',
				motion: 'MotionDetail',
				decision: 'DecisionDetail',
				agendaItem: 'MeetingDetail',
				participant: 'ParticipantDetail',
			}
			const routeName = routeMap[result._type]
			if (routeName) {
				this.$router.push({ name: routeName, params: { id: result.id || result.uuid } })
			}
			this.showDropdown = false
			this.query = ''
		},

		getIcon(type) {
			const icons = {
				meeting: CalendarBlank,
				motion: FileDocumentOutline,
				decision: GavelIcon,
				participant: AccountGroupOutline,
				agendaItem: FormatListBulleted,
			}
			return icons[type] || FormatListBulleted
		},

		getTypeLabel(type) {
			const labels = {
				meeting: this.t('decidesk', 'Vergadering'),
				motion: this.t('decidesk', 'Motie'),
				decision: this.t('decidesk', 'Besluit'),
				participant: this.t('decidesk', 'Deelnemer'),
				agendaItem: this.t('decidesk', 'Agendapunt'),
			}
			return labels[type] || type
		},

		truncateTitle(title) {
			if (!title || title.length <= 60) return title || ''
			return title.substring(0, 57) + '…'
		},
	},
}
</script>

<style scoped>
.global-search {
	position: relative;
	width: 100%;
	max-width: 300px;
	margin: 4px 8px;
}

.global-search__input-wrap {
	position: relative;
	display: flex;
	align-items: center;
}

.global-search__icon {
	position: absolute;
	left: 8px;
	color: var(--color-text-maxcontrast);
	pointer-events: none;
}

.global-search__input {
	width: 100%;
	padding: 6px 8px 6px 32px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
}

.global-search__input:focus {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -1px;
	border-color: var(--color-primary-element);
}

.global-search__dropdown {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	z-index: 1000;
	margin-top: 4px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 8px var(--color-box-shadow);
	max-height: 400px;
	overflow-y: auto;
}

.global-search__status {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 16px;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.global-search__result {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 8px 12px;
	border: none;
	background: none;
	color: var(--color-main-text);
	cursor: pointer;
	text-align: left;
	font-size: 14px;
}

.global-search__result:hover,
.global-search__result--active {
	background: var(--color-background-hover);
}

.global-search__result:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.global-search__result-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.global-search__result-content {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
}

.global-search__result-title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.global-search__result-type {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.global-search__result-badge {
	flex-shrink: 0;
	padding: 2px 8px;
	font-size: 11px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
</style>
