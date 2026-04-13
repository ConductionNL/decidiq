<template>
	<CnDetailPage
		v-bind="detailView"
		:title="detailView.object.value?.name || t('decidesk', 'Governance Body')"
		object-type="governanceBody"
		@edit="detailView.editing.value = true"
		@delete="detailView.showDeleteDialog.value = true">
		<template #content>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<template #content>
					<dl class="decidesk-detail__properties">
						<dt>{{ t('decidesk', 'Name') }}</dt>
						<dd>{{ object.name }}</dd>
						<dt>{{ t('decidesk', 'Body Type') }}</dt>
						<dd>{{ object.bodyType }}</dd>
						<dt>{{ t('decidesk', 'Domain') }}</dt>
						<dd>{{ object.domain }}</dd>
						<dt>{{ t('decidesk', 'Voting Default') }}</dt>
						<dd>{{ object.votingDefault }}</dd>
						<dt>{{ t('decidesk', 'Quorum Rule') }}</dt>
						<dd>{{ object.quorumRule }}</dd>
						<dt>{{ t('decidesk', 'Term Start') }}</dt>
						<dd>{{ object.termStart }}</dd>
						<dt>{{ t('decidesk', 'Term End') }}</dt>
						<dd>{{ object.termEnd }}</dd>
					</dl>
				</template>
			</CnDetailCard>
		</template>

		<template #sidebar>
			<CnObjectSidebar
				v-if="object.id"
				:object-type="'governanceBody'"
				:object-id="object.id"
				:object-store="governanceBodyStore" />
		</template>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnObjectSidebar, useDetailView } from '@conduction/nextcloud-vue'
import { useGovernanceBodyStore } from '../store/modules/governanceBody.js'

export default {
	name: 'GovernanceBodyDetail',
	components: { CnDetailPage, CnDetailCard, CnObjectSidebar },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const governanceBodyStore = useGovernanceBodyStore()
		const detailView = useDetailView('governanceBody', props.id, {
			objectStore: () => governanceBodyStore,
			listRouteName: 'GovernanceBodies',
			detailRouteName: 'GovernanceBodyDetail',
		})
		return { detailView, governanceBodyStore }
	},
	computed: {
		object() {
			return this.detailView.object.value || {}
		},
	},
}
</script>

<style scoped>
.decidesk-detail__properties {
	display: grid;
	grid-template-columns: 1fr 2fr;
	gap: 8px 16px;
}

.decidesk-detail__properties dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
</style>
