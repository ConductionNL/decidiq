## ADDED Requirements

### Requirement: decidiq contributes its part of a cross-app story example set

The app SHALL ship `lib/Settings/example-sets/<set>.json` fragments in the format of OpenRegister's example-set loader. A fragment SHALL refer to cast members only through `{{cast:key.field}}`, SHALL use relative dates only, SHALL use mail addresses on the set's `.example` domain only, and SHALL declare only keys the target schema declares. A fragment's groups SHALL be the group names the app's code checks.

#### Scenario: The Zuiddrecht fragment validates statically
@e2e exclude static data check, not a UI flow: covered by tests/example-sets/validate_example_set.py

- **GIVEN** `lib/Settings/example-sets/zuiddrecht.json` and the cast in OpenRegister's `lib/Settings/example-sets/zuiddrecht.json`
- **WHEN** `python3 -I tests/example-sets/validate_example_set.py` runs against the merged register schemas
- **THEN** it SHALL report every object clean and exit 0

#### Scenario: A council member's voting record is reachable
@e2e exclude needs the openregister#4603 loader on the instance, which the e2e stack does not install yet; checked by hand after install (tasks 2.2, 2.3)

- **GIVEN** the Zuiddrecht set is installed
- **WHEN** the voting record of a council member is read
- **THEN** it SHALL list one public vote per closed round of the Gemeenteraad Zuiddrecht, with the decision, the choice and the member's party
