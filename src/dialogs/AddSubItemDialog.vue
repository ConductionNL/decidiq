<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: add a nested sub-item under a parent agenda item.

 Per the agenda-management "Group agenda items with sub-items" scenario:
 each sub-item gets its own title, type, and allocated time, and nests
 under the parent (additive `parentItem` schema field). The parent
 component performs the actual object write on @submit.

 @spec openspec/specs/agenda-management/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Add sub-item')"
		data-testid="add-sub-item-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{
					t('decidesk', 'Add a sub-item under "{title}".', {
						title: parentTitle,
					})
				}}
			</p>
			<NcTextField
				v-model="title"
				:label="t('decidesk', 'Title')"
				:placeholder="t('decidesk', 'Sub-item title')"
				required />
			<NcSelect
				v-model="itemType"
				:inputLabel="t('decidesk', 'Type')"
				:options="typeOptions"
				:clearable="false" />
			<NcTextField
				v-model="estimatedDuration"
				type="number"
				:label="t('decidesk', 'Allocated time (minutes)')"
				:placeholder="t('decidesk', 'e.g. 10')" />
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="add-sub-item-confirm"
				:disabled="!title"
				@click="submit">
				{{ t('decidesk', 'Add sub-item') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'AddSubItemDialog',

	components: { NcButton, NcDialog, NcSelect, NcTextField },

	props: {
		/** Title of the parent agenda item (for the dialog copy) */
		parentTitle: { type: String, default: '' },
	},

	emits: ['submit', 'close'],

	data() {
		return {
			title: '',
			itemType: 'discussion',
			estimatedDuration: '',
		}
	},

	computed: {
		/** @spec openspec/specs/agenda-management/spec.md */
		typeOptions() {
			return ['informational', 'discussion', 'decision']
		},
	},

	methods: {
		/** @spec openspec/specs/agenda-management/spec.md */
		submit() {
			const duration = Number(this.estimatedDuration)
			this.$emit('submit', {
				title: this.title,
				itemType: this.itemType,
				estimatedDuration:
					Number.isFinite(duration) && duration > 0 ? duration : null,
			})
		},
	},
}
</script>
