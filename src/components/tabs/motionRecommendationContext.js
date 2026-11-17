// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What both change-recommendation widgets of the motion page read: the
 * motion itself and whether the signed-in user is the chair or secretary of
 * its meeting. The second comes from the server's voting-permissions answer
 * (the same VotingRoundGuard that refuses the accept and reject routes), so
 * the page never works out a role itself.
 *
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	meetingIdOf,
	readVotingPermissions,
	votingPermissionsPath,
} from '../../utils/votingPermissions.js'
import { ensureRelationType } from './useRelationStore.js'

export const motionRecommendationContext = {
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			motion: null,
			canDecide: false,
		}
	},

	computed: {
		/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status */
		motionId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-001-the-motion-text-can-be-read-with-stable-line-numbers */
		motionText() {
			return String(this.motion?.text || '')
		},
	},

	methods: {
		/**
		 * Read the motion and the caller's chair-or-secretary answer; on any
		 * failure the controls stay hidden.
		 *
		 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status
		 */
		async loadMotionContext() {
			this.canDecide = false
			if (!this.motionId) return
			this.motion = await ensureRelationType('motion').fetchObject(
				'motion',
				this.motionId,
			)
			try {
				const { data } = await axios.get(
					generateUrl(votingPermissionsPath(meetingIdOf(this.motion))),
				)
				this.canDecide = readVotingPermissions(data).canOpen
			} catch {
				this.canDecide = false
			}
		},
	},
}
