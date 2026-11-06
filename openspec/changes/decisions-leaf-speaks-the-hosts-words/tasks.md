# Tasks: decisions-leaf-speaks-the-hosts-words

- [x] 1.1 The leaf takes `emptyTitle`, `emptyText` and `createLabel` from its host, with its own words as the fallback
  - Files: src/integrations/hostWording.js, src/integrations/CnDecisionsWidget.vue
  - Test: tests/vitest/decisionsLeafHostWording.spec.js
- [x] 1.2 A named empty state draws the panel with the create button and the decidiq link
  - Files: src/integrations/CnDecisionsWidget.vue
  - Test: tests/vitest/decisionsLeafHostWording.spec.js
- [ ] 1.3 Live: PqTicketBesluitvorming on a ticket without decisions
