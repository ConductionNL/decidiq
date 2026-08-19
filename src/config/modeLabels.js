// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Declarative mode-label map for the organisatie_modus mechanism.
//
// Shape: mode → canonical menu id / label → i18n key to resolve via t().
// Keys are English source strings (i18n convention: English = key, per ADR-005).
// Adding a new mode or relabeling an item is a data edit here only — no nav
// branching needed (ADR-004 Rule 1, ADR-006 §Decision mechanism 1).
//
// C7 scope: wire the Bodies item across all 5 modes; scaffold remaining items
// with their canonical English labels so a follow-up change only fills in rows.
//
// @spec openspec/specs/app-navigation/spec.md#requirement-req-nav-006-mode-aware-label-resolution-at-the-translate-chokepoint

/**
 * Default organisatie_modus when none is configured.
 *
 * @type {string}
 */
export const DEFAULT_MODE = 'gov'

/**
 * Declarative map: organisatie_modus → canonical label → i18n key.
 *
 * Each inner object maps the canonical menu label (as used in manifest.json)
 * to the translated label key that should be passed to t('decidesk', …).
 * When a canonical label is absent from a mode's map the canonical label
 * itself is used as the t() key, which resolves to the standard l10n string.
 *
 * @type {Object<string, Object<string, string>>}
 */
export const MODE_LABELS = {
	/**
	 * Government / municipal mode (default).
	 * Organisation (canonical label since ia-six-clusters; menu id GovernanceBodies) = Fracties & Organen (political fractions and organs).
	 * Decisions = Besluiten, Meetings = Vergaderingen.
	 */
	gov: {
		Organisation: 'Factions & bodies',
		// Scaffold — canonical labels fall through to standard l10n:
		// Meetings    → 'Meetings'    (resolved as 'Vergaderingen' by nl_NL)
		// Decisions   → 'Decisions'   (resolved as 'Besluiten' by nl_NL)
		// ActionItems → 'Action items'
		// Motions     → 'Motions'
	},

	/**
	 * Corporate / board mode.
	 * Bodies = Board, Decisions = Resolutions.
	 */
	corp: {
		Organisation: 'Board',
		Decisions: 'Resolutions',
		// Scaffold:
		// Meetings    → 'Meetings'
		// ActionItems → 'Action items'
		// Motions     → 'Motions'
	},

	/**
	 * Association / member-organisation mode.
	 * Bodies = Fracties & commissies (fractions and committees).
	 */
	assoc: {
		Organisation: 'Factions & committees',
		// Scaffold:
		// Meetings    → 'Meetings'
		// Decisions   → 'Decisions'
		// ActionItems → 'Action items'
		// Motions     → 'Motions'
	},

	/**
	 * Operations / internal-team mode.
	 * Bodies = Teams.
	 */
	ops: {
		Organisation: 'Teams',
		// Scaffold:
		// Meetings    → 'Meetings'
		// Decisions   → 'Decisions'
		// ActionItems → 'Action items'
		// Motions     → 'Motions'
	},

	/**
	 * Citizen portal mode.
	 * No relabeling of Organisation; canonical label used.
	 */
	citizen: {
		Organisation: 'Organisation',
		// Scaffold:
		// Meetings    → 'Meetings'
		// Decisions   → 'Decisions'
		// ActionItems → 'Action items'
		// Motions     → 'Motions'
	},
}
