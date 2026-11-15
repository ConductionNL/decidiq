// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Global integration-leaf bootstrap for decidiq (ADR-019 / ADR-022).
//
// This is the small "leaf bundle" loaded on EVERY Nextcloud page via
// `\OCP\Util::addInitScript('decidiq', 'decidiq-integration-init')` (see
// lib/AppInfo/Application.php::boot). It registers decidiq's
// "Besluitvorming" decisions leaf on the shared OpenRegister integration
// registry so the tab + widget surface on a host object's detail page —
// e.g. a procest case — without decidiq's full app bundle being loaded.
//
// Per the registry contract a LEAF app must NOT install OR's singleton; it
// only registers its descriptor (via the load-order-safe queue stub). When
// OR's main bundle later loads on the same page it replays the queue. When
// it is already loaded the registration lands live.
//
// Kept deliberately tiny: it imports only the leaf descriptors + registration
// helper, no router / store boot / app shell. The leaf components, and Vue
// itself, load as separate chunks the first time a host mounts a leaf (see
// integrations/createLazyMountPair.js). Never import a `.vue` file or `vue`
// statically from here or from the register*Leaf modules: that pulls the
// whole component library back into a script every Nextcloud page loads.

import { registerApprovalChainLeaf } from './integrations/registerApprovalChainLeaf.js'
import { registerDecisionsLeaf } from './integrations/registerDecisionsLeaf.js'

registerDecisionsLeaf()
registerApprovalChainLeaf()
