## ADDED Requirements

### Requirement: Municipal statutory concepts SHALL be renamed with a statute marker

Every Dutch schema name SHALL be renamed to English and SHALL carry a marker recording
its jurisdiction and legal instrument where the concept is defined by Dutch municipal
law. Being the official standardised term SHALL NOT exempt a name.

#### Scenario: A council governance concept is renamed and marked

- **WHEN** a schema models a concept defined by the Gemeentewet or the Awb
- **THEN** it SHALL be renamed to English
- **AND** it SHALL carry a marker naming the jurisdiction and the instrument

#### Scenario: A statutory citation is preserved as a value

- **WHEN** a property holds a reference to a published legal article
- **THEN** that citation SHALL be unchanged
- **AND** only the identifier around it SHALL be renamed

#### Scenario: An English schema name with Dutch properties is completed

- **WHEN** a schema already carries an English name but Dutch property names
- **THEN** the properties SHALL be renamed to match
- **AND** the partial English naming SHALL be treated as evidence of intent

### Requirement: A new English schema name SHALL be checked against the whole fleet's slugs

Schema-slug resolution is instance-global, so a proposed English schema name SHALL be
checked against every app's declared slugs before adoption, not only against decidesk's.

#### Scenario: A proposed name is already taken by another app

- **WHEN** a rename would produce a slug another app already declares
- **THEN** a distinct name SHALL be chosen
- **AND** the collision SHALL be recorded in the fleet change rather than resolved locally

#### Scenario: A collision that lands on a future merge is treated as present

- **WHEN** another app's unmerged branch will introduce the same slug
- **THEN** the collision SHALL be treated as real
- **AND** the name SHALL be avoided even though nothing fails today

#### Scenario: An existing shared slug is not deepened

- **WHEN** decidesk already shares a slug with another app
- **THEN** this change SHALL NOT extend or further entangle that schema
- **AND** the resolution SHALL be deferred to the fleet change

### Requirement: Validity dates SHALL adopt the fleet words, and event dates SHALL NOT

Properties expressing a validity boundary SHALL be renamed to the fleet's validity pair.
Properties recording an event SHALL be named for the event instead.

#### Scenario: A validity boundary adopts the fleet word

- **WHEN** a property expresses the start or end of a period of validity
- **THEN** it SHALL be renamed to the fleet's `validFrom` or `validUntil`
- **AND** decidesk SHALL NOT invent an app-specific alternative

#### Scenario: An event date keeps its own name

- **WHEN** a property records when something happened, such as joining a body, being
  sworn in, or receiving a gift
- **THEN** it SHALL be named for that event
- **AND** it SHALL NOT be flattened into the validity pair

#### Scenario: The publication pair adopts the fleet word

- **WHEN** a schema carries the publication and depublication date pair
- **THEN** both SHALL adopt the fleet names
- **AND** every schema carrying the pair SHALL be renamed in the same change

### Requirement: Non-ASCII identifiers SHALL be enumerated explicitly

Properties containing diacritics SHALL be listed by hand rather than relied upon to
appear in a scan, because ASCII-oriented matching silently omits them.

#### Scenario: A diacritic property is renamed

- **WHEN** a property name contains a non-ASCII character
- **THEN** it SHALL be enumerated explicitly in the change
- **AND** a sweep that reports itself complete SHALL NOT be trusted to have covered it

### Requirement: Fragment merging SHALL be checked before editing a required list

A fragment SHALL be checked for a second declaring fragment before its `required` list is
edited, because where two register fragments declare the same schema, list values are
concatenated on merge.

#### Scenario: A schema is declared by two fragments

- **WHEN** a schema's `required` list is edited in one fragment
- **THEN** the change SHALL first confirm whether another fragment also declares it
- **AND** a concatenated `required` demanding both vocabularies SHALL be treated as a defect

#### Scenario: An additive fragment stays additive

- **WHEN** a fragment exists to add optional properties to a schema owned elsewhere
- **THEN** it SHALL NOT introduce a competing `required` list
- **AND** its additive role SHALL be recorded in the fragment
