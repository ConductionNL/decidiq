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
		:name="t('decidiq', 'Propose agenda item')"
		data-testid="propose-item-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{
					t(
						'decidiq',
						'Fill in the agenda item details. The chair will approve or reject your proposal.',
					)
				}}
			</p>
			<NcTextField
				v-model="title"
				:label="t('decidiq', 'Title')"
				:placeholder="t('decidiq', 'Agenda item title')"
				required />
			<NcTextArea
				v-model="description"
				:label="t('decidiq', 'Description')"
				:placeholder="t('decidiq', 'Describe the agenda item')" />
		</template>
		<template #actions>
			<NcButton
				:disabled="!title"
				@click="$emit('submit', { title, description })">
				{{ t('decidiq', 'Submit proposal') }}
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
