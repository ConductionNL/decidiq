<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Board portal — board creation modal (ADR-004 modal isolation).

 Self-contained NcDialog component owned by its own .vue file under
 src/modals/ (NEVER inlined inside BoardList per the
 hydra-gate-modal-isolation rule). POSTs to BoardController::create
 and emits "created" with the new row on success.

 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.2
-->
<template>
	<NcDialog
		:name="t('decidesk', 'New board')"
		size="normal"
		data-testid="board-create-modal"
		@closing="$emit('close')">
		<template #default>
			<form class="board-create" @submit.prevent="submit">
				<div class="board-create__field">
					<label for="board-create-name">{{ t('decidesk', 'Name') }}</label>
					<input id="board-create-name"
						v-model="form.name"
						type="text"
						required
						data-testid="board-create-name"
						:aria-label="t('decidesk', 'Board name')">
				</div>
				<div class="board-create__field">
					<label for="board-create-type">{{ t('decidesk', 'Type') }}</label>
					<select id="board-create-type"
						v-model="form.type"
						data-testid="board-create-type">
						<option v-for="opt in typeOptions" :key="opt" :value="opt">
							{{ opt }}
						</option>
					</select>
				</div>
				<div class="board-create__field">
					<label for="board-create-governance">{{ t('decidesk', 'Governance model') }}</label>
					<select id="board-create-governance"
						v-model="form.governanceModel"
						data-testid="board-create-governance">
						<option v-for="opt in governanceOptions" :key="opt" :value="opt">
							{{ opt }}
						</option>
					</select>
				</div>
				<div class="board-create__field">
					<label for="board-create-language">{{ t('decidesk', 'Default language') }}</label>
					<select id="board-create-language"
						v-model="form.defaultLanguage"
						data-testid="board-create-language">
						<option v-for="opt in languageOptions" :key="opt" :value="opt">
							{{ opt }}
						</option>
					</select>
				</div>
				<p v-if="errorMessage" class="board-create__error" data-testid="board-create-error">
					{{ errorMessage }}
				</p>
			</form>
		</template>
		<template #actions>
			<NcButton type="primary"
				:loading="submitting"
				:disabled="!form.name"
				data-testid="board-create-submit"
				@click="submit">
				{{ t('decidesk', 'Create') }}
			</NcButton>
			<NcButton
				data-testid="board-create-cancel"
				@click="$emit('close')">
				{{ t('decidesk', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'

export default {
	name: 'BoardCreateModal',

	components: {
		NcButton,
		NcDialog,
	},

	emits: ['close', 'created'],

	data() {
		return {
			submitting: false,
			errorMessage: '',
			form: {
				name: '',
				type: 'raad-van-commissarissen',
				governanceModel: 'two-tier',
				defaultLanguage: 'nl',
			},
			typeOptions: [
				'raad-van-commissarissen',
				'raad-van-bestuur',
				'audit-committee',
				'remuneration-committee',
				'nomination-committee',
				'risk-committee',
				'one-tier-board',
			],
			governanceOptions: ['two-tier', 'one-tier'],
			languageOptions: ['nl', 'en'],
		}
	},

	methods: {
		/**
		 * POST the form to BoardController::create.
		 *
		 * @return {Promise<void>}
		 */
		async submit() {
			if (!this.form.name) {
				return
			}
			this.submitting = true
			this.errorMessage = ''
			try {
				const response = await fetch(
					generateUrl('/apps/decidesk/api/boards'),
					{
						method: 'POST',
						headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: OC.requestToken },
						body: JSON.stringify(this.form),
					},
				)
				const payload = await response.json()
				if (payload?.success === false) {
					this.errorMessage = String(payload?.message || 'Create failed')
					return
				}
				const board = payload?.board || payload?.result || null
				this.$emit('created', board)
			} catch (e) {
				this.errorMessage = String(e?.message || 'Create failed')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.board-create {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.board-create__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.board-create__field label {
	font-weight: 500;
}

.board-create__field input,
.board-create__field select {
	padding: 6px 12px;
	border: 1px solid var(--color-border, #d0d0d0);
	border-radius: var(--border-radius, 8px);
	background: var(--color-main-background, #fff);
}

.board-create__error {
	color: var(--color-error, #c0392b);
}
</style>
