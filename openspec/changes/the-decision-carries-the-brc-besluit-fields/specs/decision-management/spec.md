# Decision management

## ADDED Requirements

### Requirement: The Decision carries the BRC Besluit fields (REQ-DM-040)

`Decision` SHALL carry `deliveryDate`, `expiryDate`, `publicationDate` and
`responsibleOrganisation`, so the VNG **Besluit** the BRC serves can be stored
here rather than in a second schema of the same name.

Two apps declared a `decision`, and a schema slug is global per organisation, so
`SchemaMapper::find()` returned whichever row it reached first. They were not two
copies of one record: this one is the governance decision (motion, voting,
adoption, amends/repeals/implements), and dossiq's was the Besluit behind its
Besluiten Registratie Component — wired into ZRC, WOO assessments and the
bezwaar lifecycle. Folding them means this schema has to hold what that API
serves.

`responsibleOrganisation` SHALL be an RSIN string and SHALL NOT be a `$ref`. The
BRC identifies the responsible party by that number, and a reference that only
resolves inside one instance would break the contract.

A `case` reference SHALL NOT be added. Cases and decisions are already linked,
and a second link would let the two disagree with nothing to say which is right.

The existing date fields SHALL survive unchanged, and SHALL NOT be collapsed
into the new ones. `effectiveDate` says when a decision takes effect and
`expiryDate` when it lapses; `publishedAt` records when this system published it
and `publicationDate` is the date the decision itself carries. Collapsing either
pair would look like tidying and would lose a distinction the BRC makes.

#### Scenario: The merged register carries all four

- **WHEN** the register JSON is read with every `register.d` fragment merged
  over it
- **THEN** `Decision` carries `deliveryDate`, `expiryDate` and `publicationDate`
  as `date`-formatted strings, and `responsibleOrganisation` as an unformatted
  string.

#### Scenario: The base register does not carry them

- **WHEN** the register JSON is read WITHOUT its fragments
- **THEN** none of the four is present, so the assertion above cannot pass if
  the fragment stops being merged.

#### Scenario: No case reference is added

- **WHEN** the merged `Decision` is inspected
- **THEN** it has no `case` property.
