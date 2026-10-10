# Tasks

- [x] 1.1 `GovernanceBodyStateRequestedEvent` (lib/Event/GovernanceBodyStateRequestedEvent.php)
- [x] 1.2 `GovernanceBodyQueryService::lookup()` reads the body and roster for the asking app only (lib/Service/GovernanceBodyQueryService.php; GovernanceBodyQueryServiceTest, against rows written by the real GovernanceBodyCommandService)
- [x] 1.3 `GovernanceBodyStateRequestedListener` answers found / not found / unhandled on failure (GovernanceBodyStateRequestedListenerTest)
- [x] 1.4 Registered in `CrossAppEventRegistrar` (GovernanceBodyStateRequestedListenerTest::testTheRegistrarWiresTheListener)
