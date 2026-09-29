<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: add agenda items from a template or from an earlier meeting
 (agenda-templates-and-copy, age-04 and pla-14).

 The secretary picks a template, or a meeting and the items to copy (none
 picked copies them all). The parent loads the templates, meetings and the
 picked meeting's items, and writes the items on @submit (modal isolation,
 ADR-004).

 @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-001-start-an-agenda-from-a-template
 @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Add agenda items')"
		data-testid="agenda-copy-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-agenda-copy">
				<NcCheckboxRadioSwitch
					v-model="source"
					value="template"
					name="agenda-copy-source"
					type="radio"
					data-testid="agenda-copy-source-template">
					{{ t('decidiq', 'From a template') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="source"
					value="meeting"
					name="agenda-copy-source"
					type="radio"
					data-testid="agenda-copy-source-meeting">
					{{ t('decidiq', 'From an earlier meeting') }}
				</NcCheckboxRadioSwitch>

				<template v-if="source === 'template'">
					<NcSelect
						v-model="template"
						:options="templateOptions"
						:inputLabel="t('decidiq', 'Template')"
						:clearable="false"
						data-testid="agenda-copy-template" />
					<p
						v-if="templateOptions.length === 0"
						class="decidiq-agenda-copy__hint">
						{{
							t(
								'decidiq',
								'There are no agenda templates yet. Save an agenda as a template, or add one under Agenda templates in the settings.',
							)
						}}
					</p>
					<ol v-else-if="template" class="decidiq-agenda-copy__preview">
						<li v-for="(item, idx) in template.items" :key="idx">
							{{ item.title }}
						</li>
					</ol>
				</template>

				<template v-else>
					<NcSelect
						v-model="meeting"
						:options="meetingOptions"
						:inputLabel="t('decidiq', 'Meeting')"
						:clearable="false"
						data-testid="agenda-copy-meeting"
						@update:modelValue="
							$emit('pickMeeting', $event && $event.id)
						" />
					<p
						v-if="meeting && meetingItems.length === 0"
						class="decidiq-agenda-copy__hint">
						{{ t('decidiq', 'That meeting has no agenda items.') }}
					</p>
					<div v-else-if="meeting" class="decidiq-agenda-copy__items">
						<p class="decidiq-agenda-copy__hint">
							{{
								t(
									'decidiq',
									'Pick the items to copy. With none picked, the whole agenda is copied.',
								)
							}}
						</p>
						<NcCheckboxRadioSwitch
							v-for="item in meetingItems"
							:key="item.id"
							v-model="picked"
							:value="item.id"
							name="agenda-copy-items"
							:data-testid="`agenda-copy-item-${item.id}`">
							{{ item.orderNumber }}. {{ item.title }}
						</NcCheckboxRadioSwitch>
					</div>
				</template>

				<p v-if="error" class="decidiq-error" role="alert">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="agenda-copy-submit"
				:disabled="busy || !canSubmit"
				@click="submit">
				{{ t('decidiq', 'Add to the agenda') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcSelect } from '@nextcloud/vue'

export default {
	name: 'AgendaCopyDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
	},

	props: {
		/** The agenda templates */
		templates: { type: Array, default: () => [] },
		/** The template the meeting's type names, preselected */
		defaultTemplateId: { type: String, default: '' },
		/** Earlier meetings to copy from */
		meetings: { type: Array, default: () => [] },
		/** The picked meeting's agenda items */
		meetingItems: { type: Array, default: () => [] },
		/** Whether the parent is writing the items */
		busy: { type: Boolean, default: false },
		/** A failure to show */
		error: { type: String, default: '' },
	},

	emits: ['close', 'pickMeeting', 'submit'],

	data() {
		return {
			source: 'template',
			template: null,
			meeting: null,
			picked: [],
		}
	},

	computed: {
		/** @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-001-start-an-agenda-from-a-template */
		templateOptions() {
			return this.templates.map((tpl) => ({ ...tpl, label: tpl.name }))
		},

		/** @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting */
		meetingOptions() {
			return this.meetings.map((m) => ({ ...m, label: m.title || m.id }))
		},

		/** @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting */
		canSubmit() {
			if (this.source === 'template') return !!this.template
			return !!this.meeting && this.meetingItems.length > 0
		},
	},

	watch: {
		templateOptions: {
			immediate: true,
			/**
			 * @param {Array<object>} options The template options
			 * @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-001-start-an-agenda-from-a-template
			 */
			handler(options) {
				if (this.template || options.length === 0) return
				this.template =
					options.find((o) => o.id === this.defaultTemplateId) || null
			},
		},
	},

	methods: {
		/** @spec openspec/specs/agenda-builder/spec.md#requirement-req-atc-002-copy-items-or-a-whole-agenda-from-an-earlier-meeting */
		submit() {
			if (this.source === 'template') {
				this.$emit('submit', { source: 'template', template: this.template })
				return
			}
			this.$emit('submit', {
				source: 'meeting',
				meetingId: this.meeting.id,
				itemIds: [...this.picked],
			})
		},
	},
}
</script>

<style scoped>
.decidiq-agenda-copy {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.decidiq-agenda-copy__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.decidiq-agenda-copy__preview {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}
</style>
