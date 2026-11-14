# Proposal: public-calendar-narrows-with-filter

## Why

Seen in the Zuiddrecht go-live rehearsal R2: "Vergaderkalender" in Mijn Zuiddrecht read "Geen items" while six council meetings exist. Portaliq read every collection scoped to the resident, also an `anonymous` one; portaliq#anonymous-collection-reads-what-the-public-may now reads an anonymous collection as the public may read it.

That read honours a collection's narrowing `filter`, not its `defaultFilters`, which only preset what a list shows. `publicCalendar` and `motionsOpenForAdvice` narrowed only by `defaultFilters`, so once read they would list every published payload (decisions too) and every published decision.

## What changes

- `publicCalendar` declares `filter: {oriType: "Vergadering"}` beside its `defaultFilters`.
- `motionsOpenForAdvice` declares `filter: {citizenVotingAllowed: true}` beside its `defaultFilters`.

## Not changed

- The field whitelists, the sort, `anonymous`.
