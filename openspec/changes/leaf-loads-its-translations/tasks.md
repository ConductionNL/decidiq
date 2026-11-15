## 1. Build

- [x] 1.1 Load decidiq's translations before the leaf mounts (tests/vitest/lazyLeafMount.spec.js, new case red on development)
- [ ] 1.2 Live check on a dossiq case's Besluiten tab (COMPDIFF DqZaakBesluiten)
- [x] 1.3 Load the translations from the `l10n/<lang>.js` script (OC.L10N.register): the JSON bundle loadTranslations() fetches is a 404 from an app folder on :8080 (r25g still English after #1803). tests/vitest/lazyLeafMount.spec.js loadTranslationScript
