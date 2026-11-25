# Tasks: council-calendar-columns-in-the-readers-language

- [x] **T1**: `PortalContributionProvider::publicCalendarCollection()` declares labels, columns and detail fields
  - PHPUnit `PortalContributionProviderTest::testTheCouncilCalendarHeadingsReadInTheReadersLanguage`, `testEveryVisibleLabelHasADutchTranslation`
- [x] **T2**: Live check on the Zuiddrecht rehearsal: the Vergaderkalender reads Datum, Vergadering, Orgaan, Vergaderingstype
  - (25 Nov, live on a sandbox rehearsal: Nextcloud 34.0.6 on PostgreSQL 16, OpenRegister main, decidiq, dossiq, portaliq and opencatalogi development, `occ portaliq:example-site:install zuiddrecht`, `occ dossiq:package:install --all`, then `occ openregister:example-set:install zuiddrecht`; read in a headless browser in Dutch, Europe/Amsterdam, signed in as the example resident Sanne de Vries at `/mijn/decidiq/publicCalendar`): the page is headed "Vergaderkalender" and its columns read Datum, Vergadering, Orgaan, Vergaderingstype; no console errors.
