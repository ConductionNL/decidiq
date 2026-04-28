// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Custom-component registry for the manifest renderer.
 *
 * Pages declared in `manifest.json` with `type: "custom"` resolve their
 * `component` field against this map. The values are full Vue component
 * definitions; each consumer (CnPageRenderer for full-page pages,
 * CnPageRenderer for slot overrides via `headerComponent` /
 * `actionsComponent`) imports this registry once and dispatches by name.
 *
 * Why type:"custom" for what looks like an index/detail page?
 * Decidesk's existing list and detail views (Decisions.vue, etc.) wire
 * up data via the `useListView` / `useDetailView` composables and
 * include slot overrides (CnSchemaFormDialog inside `#create-dialog`).
 * The renderer's built-in `type: "index"` / `type: "detail"` paths only
 * forward `page.config` as props to the corresponding Cn*Page; they do
 * not run composables. Wrapping the existing views via a custom
 * registry keeps full app-side control while still routing through the
 * manifest. A future iteration can add a manifest config for
 * "index-with-store" so CnPageRenderer wires the composable itself.
 */

import Decisions from './views/Decisions.vue'
import DecisionDetail from './views/DecisionDetail.vue'

export default {
	DecisionsView: Decisions,
	DecisionDetailView: DecisionDetail,
}
