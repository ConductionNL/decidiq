<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Real-time minute-taking panel for the live meeting view (minutes-ui-v1).

 Finds (or creates) the draft Minutes record linked to the meeting and lets
 the secretary record discussion notes and decisions per agenda item while
 the meeting runs. Edits autosave (debounced) to the draft Minutes object's
 additive `itemNotes` property via the shared object store — the canonical
 OR write path. The "+ Action item" shortcut creates a linked action-item
 object (action tracking), per the resolution-minutes spec.

 @spec openspec/specs/resolution-minutes/spec.md
-->
<template>
	<section
		class="minutes-panel"
		data-testid="minutes-panel"
		:aria-label="t('decidesk', 'Minute taking')">
		<div class="minutes-panel__header">
			<h3>{{ t('decidesk', 'Minutes (live)') }}</h3>
			<span
				class="minutes-panel__save-state"
				data-testid="minutes-panel-save-state"
				aria-live="polite">
				{{ saveStateLabel }}
			</span>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else-if="!minutes">
			<p>
				{{ t('decidesk', 'No draft minutes exist for this meeting yet.') }}
			</p>
			<NcButton
				variant="primary"
				data-testid="minutes-panel-start"
				:disabled="creating"
				:aria-label="t('decidesk', 'Start taking minutes')"
				@click="startMinutes">
				{{ t('decidesk', 'Start taking minutes') }}
			</NcButton>
			<p v-if="error" class="minutes-panel__error" role="alert">
				{{ error }}
			</p>
		</template>

		<template v-else>
			<p v-if="!editable" class="minutes-panel__locked">
				{{
					t(
						'decidesk',
						'The minutes are no longer in draft — editing is locked.',
					)
				}}
			</p>
			<p v-if="error" class="minutes-panel__error" role="alert">
				{{ error }}
			</p>
			<div
				v-for="item in sortedItems"
				:key="item.id"
				class="minutes-panel__item"
				:data-testid="`minutes-panel-item-${item.id}`">
				<div class="minutes-panel__item-header">
					<h4>{{ item.orderNumber }}. {{ item.title }}</h4>
					<!--
						THE ACCESSIBLE NAME MUST CONTAIN THE VISIBLE LABEL (WCAG 2.5.3,
						Label in Name). This aria-label used to read

						    'Add action item for {title}'

						while the button VISIBLY reads "+ Action item". An aria-label
						REPLACES the text content for accessible-name computation, so the
						visible words appeared nowhere in the name — which breaks 2.5.3
						outright, and breaks every voice-control user who says what they
						can see ("click add action item" matched nothing).

						It also silently broke the e2e selector: Playwright's
						`getByRole('button', { name: '+ Action item' })` matches the
						ACCESSIBLE name, so it could never resolve this button no matter
						how long it waited. That is the failure mode of
						tests/e2e/spec-coverage/resolution-minutes.spec.ts:121.

						Prefixing the visible string fixes the standard and the selector
						with one change, and keeps the per-item disambiguation that the
						aria-label was added for in the first place (several of these
						buttons render at once, one per agenda item).
					-->
					<NcButton
						v-if="editable"
						size="small"
						:aria-label="
							t('decidesk', '+ Action item for {title}', {
								title: item.title,
							})
						"
						@click="actionItemTarget = item">
						{{ t('decidesk', '+ Action item') }}
					</NcButton>
				</div>
				<!--
					@nextcloud/vue v9 renamed the NcTextArea model to
					`modelValue` / `update:modelValue`. The v8 pair
					(`:value` / `@update:value`) still RENDERS — `value`
					falls through onto the inner <textarea> and the label
					prop is unchanged — but `update:value` is never emitted,
					so every keystroke was dropped and the autosave never
					fired while the panel looked entirely healthy.
				-->
				<NcTextArea
					:model-value="noteFor(item.id).notes"
					:label="t('decidesk', 'Discussion notes')"
					:placeholder="t('decidesk', 'What was discussed…')"
					:disabled="!editable"
					resize="vertical"
					@update:model-value="onNoteInput(item.id, 'notes', $event)" />
				<NcTextArea
					:model-value="noteFor(item.id).decisions"
					:label="t('decidesk', 'Decisions')"
					:placeholder="t('decidesk', 'Decisions taken on this item…')"
					:disabled="!editable"
					resize="vertical"
					@update:model-value="
						onNoteInput(item.id, 'decisions', $event)
					" />
			</div>
		</template>

		<ActionItemCaptureModal
			v-if="actionItemTarget"
			:meeting-id="meetingId"
			:agenda-item="actionItemTarget"
			:participants="participants"
			@close="actionItemTarget = null" />
	</section>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextArea } from '@nextcloud/vue'
import ActionItemCaptureModal from '../../modals/ActionItemCaptureModal.vue'
import { ensureRelationType } from '../tabs/useRelationStore.js'
import { createAutosaver, getItemNote, mergeItemNote } from './minutesEditor.js'

export default {
	name: 'MinutesPanel',
	components: { ActionItemCaptureModal, NcButton, NcLoadingIcon, NcTextArea },
	props: {
		meetingId: { type: String, required: true },
		agendaItems: { type: Array, default: () => [] },
		participants: { type: Array, default: () => [] },
	},
	data() {
		return {
			loading: true,
			creating: false,
			error: '',
			minutes: null,
			itemNotes: [],
			saveState: 'idle',
			actionItemTarget: null,
			autosaver: null,
		}
	},
	computed: {
		/** @spec openspec/specs/resolution-minutes/spec.md */
		sortedItems() {
			return [...this.agendaItems].sort(
				(a, b) => (a.orderNumber ?? 0) - (b.orderNumber ?? 0),
			)
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		editable() {
			return (this.minutes?.lifecycle || 'draft') === 'draft'
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		saveStateLabel() {
			switch (this.saveState) {
				case 'pending':
				case 'saving':
					return this.t('decidesk', 'Saving…')
				case 'saved':
					return this.t('decidesk', 'All changes saved')
				case 'error':
					return this.t(
						'decidesk',
						'Autosave failed — retrying on next edit',
					)
				default:
					return ''
			}
		},
	},
	/** @spec exclude lifecycle wiring; builds the autosaver and triggers the initial fetch only */
	created() {
		this.autosaver = createAutosaver({
			save: (itemNotes) => this.persist(itemNotes),
			onStateChange: (state) => {
				this.saveState = state
			},
		})
		this.fetchMinutes()
	},
	/** @spec exclude lifecycle teardown; flushes the pending autosave so no live notes are lost */
	beforeUnmount() {
		this.autosaver?.flush()
	},
	methods: {
		/**
		 * Locate the draft Minutes record linked to this meeting.
		 *
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		async fetchMinutes() {
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('minutes')
				const items = await store.fetchCollection('minutes', {
					meeting: this.meetingId,
					_limit: 100,
				})
				const list = items || []
				this.minutes =
					list.find((m) => m.lifecycle === 'draft') || list[0] || null
				this.itemNotes = Array.isArray(this.minutes?.itemNotes)
					? this.minutes.itemNotes
					: []
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Failed to load minutes.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Create the draft Minutes record pre-linked to the meeting.
		 *
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		async startMinutes() {
			if (this.creating) return
			this.creating = true
			this.error = ''
			try {
				const store = ensureRelationType('minutes')
				await store.saveObject('minutes', {
					title: this.t('decidesk', 'Minutes'),
					lifecycle: 'draft',
					version: 1,
					meeting: this.meetingId,
					itemNotes: [],
				})
				await this.fetchMinutes()
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Could not create minutes.')
			} finally {
				this.creating = false
			}
		},
		/**
		 * Read the buffered note entry for an agenda item.
		 *
		 * @param {string} agendaItemId Agenda item UUID.
		 * @return {object} The { notes, decisions } entry.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		noteFor(agendaItemId) {
			return getItemNote(this.itemNotes, agendaItemId)
		},
		/**
		 * Buffer an edit and schedule the debounced autosave.
		 *
		 * @param {string} agendaItemId Agenda item UUID.
		 * @param {string} field 'notes' or 'decisions'.
		 * @param {string} value The new text.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		onNoteInput(agendaItemId, field, value) {
			if (!this.editable) return
			this.itemNotes = mergeItemNote(this.itemNotes, agendaItemId, {
				[field]: value,
			})
			this.autosaver.schedule(this.itemNotes)
		},
		/**
		 * Persist the buffered itemNotes onto the draft Minutes object.
		 *
		 * @param {Array} itemNotes The itemNotes array to save.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		async persist(itemNotes) {
			if (!this.minutes) return
			const store = ensureRelationType('minutes')
			const saved = await store.saveObject('minutes', {
				...this.minutes,
				itemNotes,
			})
			if (saved) this.minutes = saved
		},
	},
}
</script>

<style scoped>
.minutes-panel {
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	padding: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.minutes-panel__header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.minutes-panel__header h3 {
	margin: 0 0 var(--default-grid-baseline);
}

.minutes-panel__save-state {
	color: var(--color-text-maxcontrast);
	font-size: calc(var(--default-font-size) * 0.875);
}

.minutes-panel__item {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.minutes-panel__item:last-child {
	border-bottom: none;
}

.minutes-panel__item-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.minutes-panel__item-header h4 {
	margin: 0;
}

.minutes-panel__error {
	color: var(--color-error);
}

.minutes-panel__locked {
	color: var(--color-text-maxcontrast);
}
</style>
