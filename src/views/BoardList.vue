<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Board portal — boards index (Phase 8.2 admin/secretary view).

 Lists every Board record from OpenRegister with a quick "+ New board"
 action that opens BoardCreateModal. Each row links to BoardDetail.

 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.2
-->
<template>
	<div class="board-list" data-testid="board-list">
		<header class="board-list__header">
			<h2>{{ t('decidesk', 'Boards') }}</h2>
			<NcButton
				type="primary"
				data-testid="board-list-create"
				:aria-label="t('decidesk', 'Create board')"
				@click="showCreateModal = true">
				+ {{ t('decidesk', 'New board') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="loading" :size="48" />

		<NcEmptyContent
			v-else-if="boards.length === 0"
			:name="t('decidesk', 'No boards yet')"
			:description="t('decidesk', 'Create your first board to get started.')"
			data-testid="board-list-empty" />

		<ul v-else
			class="board-list__items"
			role="list"
			data-testid="board-list-items">
			<li v-for="board in boards"
				:key="board.id"
				class="board-list__item"
				role="listitem">
				<a class="board-list__link"
					:data-testid="`board-row-${board.id}`"
					@click.prevent="openBoard(board)">
					<span class="board-list__name">{{ board.name || board.id }}</span>
					<span class="board-list__type">{{ board.type }}</span>
					<span class="board-list__governance">{{ board.governanceModel }}</span>
				</a>
			</li>
		</ul>

		<BoardCreateModal
			v-if="showCreateModal"
			@close="showCreateModal = false"
			@created="onBoardCreated" />
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import BoardCreateModal from '../modals/BoardCreateModal.vue'

export default {
	name: 'BoardList',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		BoardCreateModal,
	},

	data() {
		return {
			loading: true,
			boards: [],
			showCreateModal: false,
		}
	},

	async mounted() {
		await this.fetchBoards()
	},

	methods: {
		/**
		 * Fetch every board via the BoardController REST surface.
		 *
		 * @return {Promise<void>}
		 */
		async fetchBoards() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/decidesk/api/boards'),
					{ headers: { Accept: 'application/json', requesttoken: OC.requestToken } },
				)
				const payload = await response.json()
				this.boards = Array.isArray(payload?.boards) ? payload.boards : (payload?.results || [])
			} catch (e) {
				console.error('[decidesk] BoardList fetch failed', e)
				this.boards = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Navigate to a board's detail page.
		 *
		 * @param {object} board Board row
		 * @return {void}
		 */
		openBoard(board) {
			this.$router.push({ name: 'BoardDetail', params: { id: String(board.id) } })
		},

		/**
		 * Append the newly-created board and close the modal.
		 *
		 * @param {object} board Created board row
		 * @return {void}
		 */
		onBoardCreated(board) {
			if (board && board.id) {
				this.boards = [...this.boards, board]
			}
			this.showCreateModal = false
		},
	},
}
</script>

<style scoped>
.board-list {
	max-width: 960px;
	margin: 0 auto;
	padding: 16px;
}

.board-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.board-list__items {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.board-list__item {
	border: 1px solid var(--color-border, #d0d0d0);
	border-radius: var(--border-radius, 8px);
	padding: 12px 16px;
	background: var(--color-main-background, #fff);
}

.board-list__link {
	display: grid;
	grid-template-columns: 2fr 1fr 1fr;
	gap: 12px;
	align-items: center;
	cursor: pointer;
	color: inherit;
}

.board-list__name {
	font-weight: 600;
}

.board-list__type,
.board-list__governance {
	color: var(--color-text-maxcontrast, #595959);
	font-size: 0.95em;
}
</style>
