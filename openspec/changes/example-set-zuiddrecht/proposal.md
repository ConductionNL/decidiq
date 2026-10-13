## Why

The Zuiddrecht demo municipality needs one coherent story across apps: the residents, businesses and employees of the cast in OpenRegister, and in decidiq the council and the college that decide on what residents ask about. OpenRegister PR #4603 adds `occ openregister:example-set:install <set>`, which reads `lib/Settings/example-sets/<set>.json` from every enabled app. decidiq ships no such fragment yet, so the site's pages on the council (`/bestuur/raadsleden`, `/bestuur/hoe-stemden-de-raadsleden`, `/bestuur/vergaderingen`, `/bestuur/collegebesluiten`) have nothing behind them.

## What changes

- `lib/Settings/example-sets/zuiddrecht.json`: decidiq's fragment of the set. The Gemeenteraad Zuiddrecht (27 fictitious council members in six fictitious local parties, voting records published) and the college (a mayor and four aldermen with portfolios), the cast's griffie employee `bram` as griffier with a post, a person record and a participant. Three council meetings on Thursday evening and three college meetings on Tuesday morning, as the site says, with agendas; two are in the future. Dates are pinned with `{{datetime:<n>@thu 19:30}}` and `{{datetime:<n>@tue 10:00}}`, and every moment of a meeting (agenda published, rounds, votes, decisions) uses the meeting's own anchor so it falls on the meeting's day. Seven decisions, among them the credit for lighting the Lindelaan cycle path (the site's news item "Fietspad Lindelaan krijgt verlichting"), a rejected motion, and the college's contract award to the cast business Installatiebedrijf Van Dam. Three public, named voting rounds with a vote per council member. `bram` joins `decidiq-secretariat` and `decidiq-administrators`.
- `tests/example-sets/validate_example_set.py`: a static check of the fragment against the merged register schemas, with no instance needed.
- The existing seed profiles and `SeedProfileService` are unchanged.

## Depends on

ConductionNL/openregister#4603 (the loader and the cast). Until it merges, the fragment is inert: nothing reads the folder.

## Impact

Data only, no code. Every person except `bram` is fictitious and exists only in this fragment, without a Nextcloud account. All dates are relative to the install day.
