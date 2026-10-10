# seed-profiles Delta: example-set-cards

## MODIFIED Requirements

### Requirement: Record the chosen example set

`POST /api/setup/config` SHALL accept `example_profile` and persist it, and SHALL
be admin-only.

The endpoint SHALL read exactly one named key from the request and SHALL NOT
write a caller-supplied key. The app's own settings share the appconfig
namespace, including `voter_token_secret`, the HMAC key signing every voting
token and mail-reply link.

The value MAY name several sets. A value that names neither a shipped set nor
`none` SHALL be rejected with 400 and SHALL NOT be stored, and one bad entry
SHALL reject the whole pick.

#### Scenario: A pick is persisted

- **WHEN** an administrator posts `example_profile: municipality`
- **THEN** the value is stored and echoed back

#### Scenario: An unknown set is refused

- **WHEN** an administrator posts an id no descriptor declares
- **THEN** the response is 400 and nothing is stored

### Requirement: List example sets

The system SHALL expose the example sets it ships, each carrying `id`, `label`,
`description`, `objectCount` and `icon`, ordered by the `order` its descriptor
declares.

The list SHALL be read from the descriptors on disk rather than from a list in
code, so a set that ships without being offered is impossible.

`GET /api/setup/status` SHALL include the offerable list as `profiles`, which is
`listChoices()`: the shipped sets plus `none`.

#### Scenario: Every shipped set is offered

- **WHEN** an administrator requests the setup status
- **THEN** the response lists one entry per descriptor in `lib/Settings/profiles/`
- **AND** each entry names a non-empty label and a positive object count

#### Scenario: The generated set is offered only when it ships

- **WHEN** `decidiq_mock_register.json` is absent
- **THEN** the `generated` option is not offered
- **AND** the wizard does not present an import that cannot run

### Requirement: Declining is an answer

`none` SHALL be a selectable value. Choosing it SHALL mark both the choice step
and the load step done without importing anything.

A step that can never be marked done reopens the wizard over every page, so "no
thanks" has to be expressible.

Choosing it alongside a set SHALL NOT be an error: the set wins.

#### Scenario: Choosing none closes the wizard

- **WHEN** an administrator chooses `none`
- **THEN** both setup steps report `done: true`
- **AND** no object is created
