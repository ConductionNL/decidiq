<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: propose an agenda item for chair review.

 Extracted from AgendaBuilder per the modal-isolation rule (ADR-004):
 the dialog owns only the form state; the parent performs the actual
 proposal write on @submit.

 @spec openspec/specs/agenda-management/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Propose agenda item')"
		data-testid="propose-item-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{
					t(
						'decidesk',
						'Fill in the agenda item details. The chair will approve or reject your proposal.',
					)
				}}
			</p>
			<NcTextField
				v-model="title"
				:label="t('decidesk', 'Title')"
				:placeholder="t('decidesk', 'Agenda item title')"
				required />
			<NcTextArea
				v-model="description"
				:label="t('decidesk', 'Description')"
				:placeholder="t('decidesk', 'Describe the agenda item')" />
		</template>
		<template #actions>
			<NcButton
				:disabled="!title"
				@click="$emit('submit', { title, description })">
				{{ t('decidesk', 'Submit proposal') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea, NcTextField } from '@nextcloud/vue'

export default {
	name: 'ProposeAgendaItemDialog',

	components: { NcButton, NcDialog, NcTextArea, NcTextField },

	emits: ['submit', 'close'],

	data() {
		return {
			title: '',
			description: '',
		}
	},
}
</script>
