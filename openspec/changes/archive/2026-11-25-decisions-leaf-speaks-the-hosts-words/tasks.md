# Tasks: decisions-leaf-speaks-the-hosts-words

- [x] 1.1 The leaf takes `emptyTitle`, `emptyText` and `createLabel` from its host, with its own words as the fallback
  - Files: src/integrations/hostWording.js, src/integrations/CnDecisionsWidget.vue
  - Test: tests/vitest/decisionsLeafHostWording.spec.js
- [x] 1.2 A named empty state draws the panel with the create button and the decidiq link
  - Files: src/integrations/CnDecisionsWidget.vue
  - Test: tests/vitest/decisionsLeafHostWording.spec.js
- [x] 1.3 Live: PqTicketBesluitvorming on a ticket without decisions
  - 25 Nov, live on a sandbox (Nextcloud 34.0.6, PostgreSQL 16, OpenRegister main, decidiq and pipelinq development; admin in Dutch, headless browser 1440x1000): a new ticket "Lantaarnpaal Lindelaan defect" (melding) without decisions, its Besluiten tab: the leaf draws the empty-state panel titled "Geen besluit gekoppeld" with pipelinq's text ("Een besluit kun je direct vanuit dit ticket voorbereiden als voorstel in decidiq, ..."), the button "Voorstel maken voor dit ticket" and the link "Openen in decidiq" (to /index.php/apps/decidiq/decisions).
