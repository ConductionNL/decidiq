# Proposal: decisions-leaf-drafter-name

## Why

The decisions card (decisions-leaf-board-card) named a decision's drafter by its proposer, else by the owner's account
id ("pieter"). The DqZaakBesluiten board reads "opgesteld door Pieter Jansen" and shows "PJ".

## What changes

The leaf looks up the display name of each owner it shows through Nextcloud's user directory (OCS `cloud/users/{id}`),
once per account. A proposer still wins; an account that cannot be read shows as itself.
