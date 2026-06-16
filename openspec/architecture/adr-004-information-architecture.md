# ADR-004: Information Architecture

**Status:** accepted
**Date:** 2026-05-23

## Context

Decidesk is a universal decision-making platform: it serves municipal raden and
griffies (NOTUBIZ/iBabs-grade workflow — agenda, stukken, moties, amendementen,
stemming, livestream-transcripts), corporate boards (resolutions, minutes,
attestation), associations (ledenvergaderingen, fracties/commissies), and
operational teams (recurring meetings, action items, follow-up). The same data
model underpins all four: meetings, agenda-items, stukken, decisions, motions,
votes, actions, governance bodies, participants.

The IA needs to scale from a 6-person operational standup to a 45-member
raadsvergadering with 200 ingelogde fractievertegenwoordigers — one shell,
role-aware density. The spec count (34 specs, including tiered variants
T1/T2/T3 for meeting/decision/motion management) outweighs what any user can
hold in their head, so the navigation must compress without losing the
domain-specific surfaces that justify Decidesk against NOTUBIZ/iBabs (openness,
StUF/ZGW/Digikoppeling via OpenConnector) and against Boardable/Diligent
(government-grade compliance + Dutch standards out of the box).

This ADR captures the cross-cutting IA design rules extracted from the
fleet-wide IA proposal (`/tmp/ia-doc-dec-cat-conn.md`, decidesk section). It
exists to govern future spec placement decisions so that 34 specs today and 50
specs tomorrow do not produce 50 nav items.

## Decision

Decidesk adopts a **6-item top-level navigation** with strict placement rules
that govern how future specs slot in without expanding the nav surface.

### Top-level navigation (6, fixed)

1. **Vergaderingen** — calendar + per-meeting workspace (agenda, stukken,
   livestream, notulen, stemming, transcript)
2. **Besluiten** — decisions and resolutions register (search, status, lineage,
   publication)
3. **Acties** — action items / follow-up across meetings
4. **Moties** — moties + amendementen administration (Dutch parliamentary
   procedure)
5. **Fracties & Organen** — fracties, commissies, board, members (label adapts
   by tenant mode)
6. **Beheer** — schemas, integrations, participation portal admin, dashboard,
   MCP tools, admin-settings

"Dashboard" is the landing page of *Vergaderingen* (today's/next meeting +
open actions), not a separate top-level item.

### Design rules

#### Rule 1 — One shell, role/mode-aware labels

**Rule.** The same six top-level items serve all four target audiences
(gov / corp / assoc / ops). Only the *labels* shift via tenant mode — the
navigation structure itself never branches per persona.

**Rationale.** Decidesk's wedge is "one platform for every kind of
decision-making body". Branching the IA per persona would split the codebase,
the documentation, the muscle memory and the training material four ways.
Label-only adaptation keeps a single product while respecting the language each
audience uses.

**How to apply.**
- Tenant config exposes an `organisatie-modus` setting (`gov` / `corp` /
  `assoc` / `ops`) under *Beheer > Admin-instellingen*.
- The "Fracties & Organen" label adapts: `Fractie` (gov) → `Members` (corp) →
  `Board` (corp variant) → `Teams` (ops). Tab labels inside also adapt
  (`Voorzitter` → `Chair` etc.).
- All new specs MUST use the canonical label internally and rely on the
  translation layer + mode toggle, not a hardcoded persona branch.
- A new persona never warrants a new top-level item. If a new audience
  surfaces, extend the label-adaptation table.

#### Rule 2 — Tiered specs collapse into the same detail page

**Rule.** T1/T2/T3 (and "other" T1/T2) variants of a spec are never separate
menu entries. They are progressive-disclosure sections inside the same detail
tab, gated by feature flag or capability detection.

**Rationale.** Decidesk has six tiered spec families
(`p2-meeting-management-core-t1/t2/t3`,
`p2-meeting-management-other-t1/t2`,
`p2-minutes-and-decisions-core-t1/t2/t3`,
`p2-minutes-and-decisions-other-t1/t2`,
`p2-motion-and-voting-core-t1/t2/t3`,
`p2-motion-and-voting-other-t1/t2/t3`). If each tier earned a menu row, the
nav would carry 25+ entries from these specs alone. Progressive disclosure
inside one detail page lets simple tenants (ops, small assoc) see a focused
form while compliance-heavy tenants (gov raad) see the full ZGW/StUF/ORI
field set, on the same screen with the same routing.

**How to apply.**
- T1 fields render in the primary section of the detail tab.
- T2 fields render in a default-collapsed expandable section ("Meer velden").
- T3 fields render behind a "Geavanceerd" toggle.
- "Other" tiers (domain-specific extensions) render in a sibling section below
  core, with the same progressive-disclosure pattern.
- New spec tiers MUST inherit this placement; never add an "Advanced
  meetings" or "Extended decisions" menu item.
- Feature-flag the tier visibility per tenant so an ops team never sees
  raads-specific fields.

#### Rule 3 — Cross-cutting registers live alongside the meeting workspace

**Rule.** Decision-domain registers (Besluiten, Moties, Acties) get their own
top-level item AND surface inside *Vergaderingen* via tabs. Authoring happens
in the meeting context; browsing/searching the register lives in the dedicated
top-level. The same data, two entry points.

**Rationale.** A raadsgriffier opens a meeting and edits decisions there
("ik ben in deze vergadering aan het werk"); an archivaris opens *Besluiten*
and searches all decisions across all meetings ("ik wil weten welke besluiten
over dit dossier zijn genomen"). Forcing either user into the other's screen
breaks the mental model. The split is by *task* (authoring vs. browsing), not
by data — there is no duplication of objects, only of entry points.

**How to apply.**
- Authoring routes (write/edit motions, decisions, actions) live under
  `Vergaderingen > {meeting} > {Besluiten | Moties | Acties} tab`.
- Register routes (list, search, facets, lineage view) live at top-level
  `/besluiten`, `/moties`, `/acties`.
- Both routes open the SAME detail page for a given object; users can navigate
  meeting ↔ register from any detail view (breadcrumb + "Herkomst" tab).
- New cross-cutting decision-domain registers follow the same split. Do NOT
  create a register-only or meeting-only surface for a new object type that
  belongs to both worlds.

#### Rule 4 — Beheer is operator-only

**Rule.** Schemas, integrations (NOTUBIZ/iBabs/Digikoppeling), MCP tools,
standards-hardening toggles, NOTUBIZ/iBabs sync, dashboards and CRUD-tools —
all behind one door (*Beheer*). No admin items leak into the meeting flow.

**Rationale.** Decidesk's audience includes non-technical raadsleden and
boardmembers who never touch configuration. Mixing schema editors or
integration configuration into the meeting flow forces them to mentally filter
out admin noise on every page. The corollary: an operator (griffier, IT admin)
visits *Beheer* deliberately to configure or troubleshoot, and is never
ambushed by admin controls in their day-to-day surface.

**How to apply.**
- Anything operator-only — schema definitions, integration credentials, MCP
  tool registrations, Prometheus, admin-settings, CRUD scaffolding,
  standards-hardening toggles — MUST live under *Beheer*.
- A case-worker / meeting participant should never have a reason to open
  *Beheer*.
- A griffier / IT-admin should never have to leave *Beheer* to configure
  something tenant-wide.
- New operator-only specs default to *Beheer*; only promote to a top-level
  item with explicit IA review (and a corresponding rule update here).

### Top-level ceiling

Six items is the agreed ceiling for Decidesk. New specs MUST fit into one of
the existing top-level items (or attach as a tab/sub-page within one).
Adding a 7th top-level requires an ADR amendment that demonstrates the new
surface can't reasonably live under an existing item AND that the existing
six are still mutually exclusive.

## Consequences

- **Spec triage gets a fixed rubric.** Every new spec is placed using the
  four rules above; placement decisions stop being a per-PR debate.
- **Tiered specs stay invisible in the nav.** T1/T2/T3 expansion is a
  progressive-disclosure pattern, not an IA event.
- **Persona-specific demands are absorbed by labels, not branches.** A
  request for "board mode" or "association mode" extends the
  `organisatie-modus` translation table, not the nav.
- **Cross-cutting registers stay discoverable from both meeting and register
  contexts.** Authoring and browsing remain two doors into the same object.
- **Operator surface stays separate.** Non-technical users never see schema
  editors or integration credentials.
- **The 6-item ceiling forces discipline.** Future surfaces (e.g., new
  participation modes, new compliance regimes) MUST find a home inside the
  existing nav — or trigger an ADR amendment.
- **Cross-app consistency.** Sibling apps (docudesk, opencatalogi,
  openconnector) follow the same pattern (working-surface noun + 2–4
  specialised surfaces + one *Beheer* drawer). Users moving between Conduction
  apps recognise the structure immediately.
