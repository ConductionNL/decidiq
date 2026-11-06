# apphost-adoption

## ADDED Requirements

### Requirement: The deep-link listener is registered in decidiq's namespace

decidiq SHALL register the AppHost's deep-link listener, and the factory that builds it with decidiq's app id, under a service name in its own `OCA\Decidiq\` namespace, never under OpenRegister's class name.

#### Scenario: OpenRegister dispatches the deep-link registration event
- **GIVEN** decidiq and OpenRegister enabled
- **WHEN** OpenRegister dispatches `DeepLinkRegistrationEvent`
- **THEN** decidiq's listener SHALL be built by decidiq's container and register the manifest's deep links, and no "Could not resolve appId!" SHALL be logged
- @e2e exclude {bootstrap wiring; covered by DeepLinkListenerRegistrationTest}
