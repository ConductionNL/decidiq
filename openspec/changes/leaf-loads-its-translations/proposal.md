# The decisions leaf reads in the user's language on a host page

## Why

On a dossiq case (COMPDIFF r25e DqZaakBesluiten, 25 Oct) the decisions card read "Prepare decision", "From decision-making in decidiq, shown here", "drafted by ... on ..." and "not yet", although l10n/nl.json translates all of them. A host page loads only its own app's translations; decidiq's leaf mounts there without decidiq's.

## What changes

- The leaf mount pair loads decidiq's translations (`loadTranslations('decidiq')`) beside the component chunk and mounts once they are in. A failed load mounts the leaf in English rather than not at all.

## Impact

`src/integrations/createLazyMountPair.js`. One fetch of `l10n/<lang>.json` the first time a leaf mounts on a page; nothing on pages without a leaf.
