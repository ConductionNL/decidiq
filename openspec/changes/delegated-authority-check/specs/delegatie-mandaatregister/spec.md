# delegatie-mandaatregister (delta)

## ADDED Requirements

### Requirement: REQ-DMR-008 Another app can ask whether an account holds an authority for an act

The register MUST answer, for an account, an act key, an optional amount in euro and a moment, whether an in-force `bevoegdheidstoedeling` grants that account that act. A toedeling grants an act only when its `acts` list contains the act key, it names the account in `delegateUser` or the account is a member of `delegateGroup`, its status is `effective`, the moment lies inside `validFrom` to `validTo` (both days included), the amount does not exceed `financialCeiling`, and every parent it is an ondermandaat of permits ondermandaat and is itself in force. The answer carries `authorised`, a `reason` (`authorised`, `no-actor`, `no-act`, `no-allocation`, `over-ceiling`, `register-unreadable`) and the id of the covering toedeling. The register itself refuses nothing on this answer (REQ-DMR-006 stands).

#### Scenario: A named account is authorised

- GIVEN an effective toedeling with `delegateUser` alice and `acts` [sign-fixture-letter]
- WHEN alice is checked for sign-fixture-letter
- THEN the answer is authorised and names that toedeling

#### Scenario: A group grants its members only

- GIVEN an effective toedeling with `delegateGroup` fixture-signers
- WHEN a member and a non-member are checked
- THEN the member is authorised and the non-member gets `no-allocation`

#### Scenario: An empty act list covers nothing

- GIVEN an effective toedeling naming alice with an empty `acts` list
- WHEN alice is checked for any act
- THEN the answer is `no-allocation`

#### Scenario: The ceiling holds

- GIVEN a toedeling with `financialCeiling` 25000
- WHEN the amount is 25000.01
- THEN the answer is `over-ceiling`, unless another toedeling with a higher ceiling covers the act

#### Scenario: An ondermandaat needs a permitting parent in force

- GIVEN a toedeling whose parent does not permit ondermandaat, is withdrawn, has lapsed, cannot be found, or forms a cycle
- WHEN the account it names is checked
- THEN it covers nothing

#### Scenario: An unreadable register is not an authorisation

- GIVEN OpenRegister throws on the read
- WHEN any account is checked
- THEN the answer is `register-unreadable` and not authorised
