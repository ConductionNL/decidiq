# Proposal: decisions-leaf-speaks-the-hosts-words

## Why

The `decidesk-decisions` leaf speaks of its host as "this object": "Create proposal for this object", "No decision-making linked to this object yet." The page that mounts it knows the object. The board PqTicketBesluitvorming (pipelinq) draws an empty state titled "Geen besluit gekoppeld", the host's explanation under it, a primary "Voorstel maken voor dit ticket" and an "Openen in decidiq" link, and no collapse button. The leaf cannot translate the host's words, so the host must hand them over (nextcloud-vue `labels`, change leaf-labels-in-the-host-language).

## What changes

`CnDecisionsWidget` takes three optional props, already in the reader's language: `emptyTitle`, `emptyText` and `createLabel`.

- `createLabel` replaces the create-proposal button's words and the dialog title.
- When the host names the empty state (`emptyTitle`), an empty list draws a panel: the gavel icon, the title, the host's text, then the create button and "Open in decidiq" as a link. Without `emptyTitle` the empty line stays as it is.
- `collapsible: false`, which the leaf already takes, drops the collapse button.

## Not changed

- Hosts that pass none of the props (dossiq and others) render as before.
- The list, the create dialog and the dashboard surfaces.
