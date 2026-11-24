# Tasks: meeting-requested-event

- [x] 1 `MeetingRequestedEvent` with the positional contract and result slots (REQ-MRE-001)
- [x] 2 `MeetingCommandService` resolving on the subject, patching on a repeat, refusing incomplete commands (REQ-MRE-002, REQ-MRE-003)
- [x] 3 `MeetingRequestedListener` registered in `CrossAppEventRegistrar`, bound through `ContractBindings` (REQ-MRE-001)
- [x] 4 Unit tests: `tests/Unit/Service/MeetingCommandServiceTest.php`, `tests/Unit/Listener/MeetingRequestedListenerTest.php`
