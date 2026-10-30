## ADDED Requirements

### Requirement: Pages follow the member's Nextcloud language

Every decidiq page SHALL show its labels, messages and dates in the language the member set in their Nextcloud personal settings. decidiq SHALL ship complete Dutch and English catalogues, SHALL take every UI string through the Nextcloud translation function, and SHALL fall back to English for a string a catalogue lacks.

#### Scenario: A member who reads English

- GIVEN member Priya set English as her Nextcloud language
- WHEN she opens the meetings page
- THEN its labels and buttons read in English

#### Scenario: A string missing from a partial catalogue

- GIVEN a member whose language is German, and a label the German catalogue lacks
- WHEN the page renders
- THEN that label reads in English and the rest in German
