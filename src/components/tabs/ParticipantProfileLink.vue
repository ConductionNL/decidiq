<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Participant page widget: "Open profile" when the participant resolves to a
 person, on the Nextcloud user id first and then the email address. Read
 only: it never creates a person.

 @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-003-member-lists-link-to-the-profile
-->
<template>
	<div class="decidiq-tab" data-testid="participant-profile-link">
		<p v-if="loading" class="decidiq-tab__loading">
			{{ t('decidiq', 'Looking for the profile…') }}
		</p>
		<router-link
			v-else-if="personId"
			:to="{ path: profile }"
			data-testid="participant-open-profile">
			{{ t('decidiq', 'Open profile') }}
		</router-link>
		<p v-else class="decidiq-tab__empty">
			{{ t('decidiq', 'No profile is linked to this participant.') }}
		</p>
	</div>
</template>

<script>
import { participantPersonQueries, profilePath } from '../../utils/memberProfile.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'ParticipantProfileLink',

	props: {
		objectId: { type: [String, Number], default: '' },
		objectType: { type: String, default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			personId: '',
		}
	},

	computed: {
		/** @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-003-member-lists-link-to-the-profile */
		profile() {
			return profilePath(this.personId)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-003-member-lists-link-to-the-profile */
			handler() {
				this.resolve()
			},
		},
	},

	methods: {
		/**
		 * Find the participant's person, strongest match first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md#requirement-req-mpr-003-member-lists-link-to-the-profile
		 */
		async resolve() {
			if (!this.objectId) return
			this.loading = true
			this.personId = ''
			try {
				const participant = await ensureRelationType(
					'participant',
				).fetchObject('participant', this.objectId)
				const personStore = ensureRelationType('person')
				for (const query of participantPersonQueries(participant)) {
					const found = await personStore.fetchCollection('person', {
						...query,
						_limit: 1,
					})
					if (found?.length) {
						this.personId = found[0].id
						break
					}
				}
			} catch {
				this.personId = ''
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
