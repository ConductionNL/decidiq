# nextcloud-integration Specification (delta)

## ADDED Requirements

### Requirement: Meetings appear in Nextcloud's unified search

The unified search provider SHALL return meetings the searcher may read, next to decisions and minutes, each with its title and date, and SHALL open the meeting page when a result is chosen.

#### Scenario: A member finds a meeting from the search bar

- **WHEN** a member types a word from the title of the council meeting of 14 October into Nextcloud's search bar
- **THEN** the meeting is listed with its date
- **AND** choosing it opens that meeting's page in decidiq
