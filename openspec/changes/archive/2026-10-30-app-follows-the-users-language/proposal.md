---
kind: spec
---

# Proposal: app-follows-the-users-language

## Summary

decidiq shows every page in the language the member set in Nextcloud. It ships Dutch and English complete and 24 more languages through the Nextcloud translation catalogues. No requirement said so, so the matrix row plt-04 cited `specs/app-foundation` without a requirement that names it. This change adds that requirement for behaviour that is already built.

## Evidence

`l10n/` holds 26 languages (nl.json and en.json complete, de and fr partial); `src/settings.js` calls `loadTranslations('decidiq')`; every UI string goes through `t('decidiq', ...)`; portal labels follow the reader's language (`portal-labels-in-the-readers-language`).

## Matrix rows

- plt-04: Use the app in Dutch, English and other languages.
