<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Action-items surface switch.

 Renders the Deck-board projection (real Nextcloud Deck cards via the OR
 Deck leaf) when the Deck app is installed/enabled, and falls back to the
 existing full-CRUD table tab otherwise. Both read the same read-only
 `action-item` VTODO projection and write through the same VTODO endpoints,
 so create/edit/delete keep working with or without Deck (REQ-AI-DECK-009).

 @spec openspec/changes/action-item-deck-board/specs/action-item-board-via-deck-leaf/spec.md
-->
<template>
	<div class="action-items-surface" data-testid="action-items-surface">
		<ActionItemDeckBoard v-if="deckAvailable" :objectId="objectId" />
		<DecisionActionItemsTab v-else :objectId="objectId" />
	</div>
</template>

<script>
import ActionItemDeckBoard from './ActionItemDeckBoard.vue'
import DecisionActionItemsTab from './DecisionActionItemsTab.vue'
import { isDeckAvailable } from '../../services/deckProjection.js'

export default {
	name: 'ActionItemsSurface',
	components: { DecisionActionItemsTab, ActionItemDeckBoard },
	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			// Default to the table until the capability probe resolves, so a
			// slow/failed probe degrades to the always-safe table surface.
			deckAvailable: false,
		}
	},

	async created() {
		try {
			this.deckAvailable = await isDeckAvailable()
		} catch (e) {
			this.deckAvailable = false
		}
	},
}
</script>
