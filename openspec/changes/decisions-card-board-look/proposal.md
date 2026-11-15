# The decisions card joins the host's tab strip as a board card

## Why

COMPDIFF r25e DqZaakBesluiten (25 Oct): the decisions card on a dossiq case's Besluiten tab kept a 12px radius where the board squares the corner under the open tab (radius 0 vs 12), its title sat on a 25.5px line (board 20.4), and its subtitle read 15px (a theme's paragraph token), pushing the first row 10px down (55 vs 45).

## What changes

- The card carries the host contract class `cn-case-widget-card`, which the nextcloud-vue board look gives the board radius and padding and, as the first card under a tab strip, the square top-left corner.
- Title line height 1.2; the subtitle is a span (no theme paragraph rule), 14px.

## Impact

`src/integrations/CnDecisionsTab.vue` only.
