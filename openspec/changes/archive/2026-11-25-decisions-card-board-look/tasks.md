## 1. Build

- [x] 1.1 Contract class, title line height, subtitle span (tests/vitest/decisionsCardJoinsTabStack.spec.js)
- [x] 1.2 Live check DqZaakBesluiten
  - 25 Nov, live on a sandbox: Nextcloud 34.0.6 on PostgreSQL 16, OpenRegister main, decidiq and dossiq development, Zuiddrecht example set; admin with language nl, headless browser 1440x1000, dossiq case "Verlichting fietspad Lindelaan" (2026-4871), Besluiten reached through the tab row's Acties overflow (the tab is `overflow: true` in dossiq's simple layout): the leaf's card is `cn-decisions-tab cn-case-widget-card`, first in its mount host, radius 0 12 12 12 (square top-left under the tab), padding 20px 22px; title "Besluiten" 17px on a 20.4px line (1.2); subtitle a 14px span on a 16.8px line; title top to subtitle bottom 40px. No console error from decidiq (the page logs a core dashboard `rerenderPanels` TypeError that is not decidiq's).
