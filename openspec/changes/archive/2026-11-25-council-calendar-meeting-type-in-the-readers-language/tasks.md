# Tasks: council-calendar-meeting-type-in-the-readers-language

- [x] 1.1 `publicCalendar` declares value labels for `meetingType` and `oriType`
  - Files: lib/Portal/PortalContributionProvider.php, l10n/nl.json, l10n/en.json
  - Test: tests/Unit/Portal/PortalContributionProviderTest.php
- [x] 1.2 The label translator translates value labels and keeps their keys
  - Files: lib/Portal/PortalLabelTranslator.php
  - Test: tests/Unit/Portal/PortalContributionProviderTest.php
- [x] 1.3 Live: open the Vergaderkalender in Dutch and read "Gewone vergadering"
  - (25 Nov, live on a sandbox rehearsal: Nextcloud 34.0.6 on PostgreSQL 16, OpenRegister main, decidiq, dossiq, portaliq and opencatalogi development, `occ portaliq:example-site:install zuiddrecht`, `occ dossiq:package:install --all`, then `occ openregister:example-set:install zuiddrecht`; read in a headless browser in Dutch, Europe/Amsterdam, signed in as the example resident Sanne de Vries at `/mijn/decidiq/publicCalendar`): both rows read "Gewone vergadering" in the Vergaderingstype column (stored value `regular`).
