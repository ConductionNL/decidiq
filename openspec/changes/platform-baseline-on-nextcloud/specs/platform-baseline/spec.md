# platform-baseline Specification

## Purpose

What decidiq inherits from the Nextcloud instance it runs on (sign-in, second factor, users and groups, hosting and data residency), and the platform rows tenders ask for on top of that: room audio and video, a public register of ancillary positions, and use on a phone.

## ADDED Requirements

### Requirement: REQ-PLB-001 Members sign in with the organisation's single sign-on through Nextcloud

decidiq SHALL keep no login and no user store of its own. Every decidiq page and API route SHALL rely on the Nextcloud session, so any single sign-on the instance is configured with (OpenID Connect through user_oidc, SAML through user_saml) signs a member in to decidiq as well. (plt-01)

#### Scenario: A member signs in through the organisation's identity provider

- GIVEN a Nextcloud instance with user_oidc linked to the organisation's identity provider
- WHEN member Fatima opens /apps/decidiq/ while signed out
- THEN Nextcloud sends her to the identity provider
- AND after signing in she lands on the decidiq page she asked for, without a second login

### Requirement: REQ-PLB-002 A second sign-in factor is enforced by Nextcloud

decidiq SHALL NOT implement a second factor of its own. When the Nextcloud administrator enforces two-factor authentication (an authenticator app through twofactor_totp, a security key through WebAuthn), a member SHALL pass that factor before any decidiq page or API route answers. (plt-28)

#### Scenario: Enforced second factor applies to decidiq

- GIVEN an instance that enforces two-factor authentication for the group of council members
- WHEN council member Pieter signs in and has not passed his authenticator code
- THEN no decidiq page or API route returns data to him
- AND after he enters the code, decidiq opens as usual

### Requirement: REQ-PLB-003 A functional administrator manages users and groups in Nextcloud

decidiq SHALL keep no user administration of its own. A functional administrator, or a Nextcloud group sub-admin, SHALL create users and groups and name the groups in Nextcloud's Users settings without the supplier. decidiq SHALL read those groups when importing body members, and SHALL create only the per-body role groups (chair, signatory) it needs, under names it states. (plt-21)

#### Scenario: A clerk adds a group and imports its members

- GIVEN functional administrator Lisa creates the group "Commissie Ruimte" in Nextcloud and adds five users
- WHEN she imports members for the body Commissie Ruimte in decidiq
- THEN decidiq offers the Nextcloud group "Commissie Ruimte" to import from
- AND no supplier action is needed at any step

### Requirement: REQ-PLB-004 decidiq runs on any self-hosted Nextcloud with OpenRegister

decidiq SHALL install as a standard Nextcloud app on any self-hosted Nextcloud within the versions `appinfo/info.xml` declares, with OpenRegister as its only required app. It SHALL need no service that the supplier runs. (plt-06)

#### Scenario: An organisation installs decidiq on its own servers

- GIVEN a Nextcloud instance on the organisation's own servers with OpenRegister installed
- WHEN the administrator enables decidiq from the app store or from a release archive
- THEN decidiq imports its register and opens, with no call to a supplier service

### Requirement: REQ-PLB-005 Data stays where the instance runs

decidiq SHALL store its records only in the instance's own OpenRegister database and its documents only in the instance's own Nextcloud Files. It SHALL make no outbound call to a host the administrator did not configure. Data residency, and the hosting certificate an organisation asks for (such as ISO 27001 inside the EU), therefore follow where the instance runs. (plt-32, pen-01)

#### Scenario: An instance hosted in an ISO 27001 certified EU data centre

- GIVEN an instance hosted in an ISO 27001 certified data centre in the Netherlands
- WHEN a clerk records a decision with its papers
- THEN the decision lives in that instance's database and the papers in its Files
- AND decidiq sends nothing to any host outside the connections the administrator set up

### Requirement: REQ-PLB-006 The room's cameras and microphones follow the speaker

When a meeting is live, decidiq SHALL send the current speaker to the room's audio and video system each time the chair gives someone the floor, through the integriq connection that links the room system. The room system then switches the microphone and the camera, so the recording follows the speaker. Without a linked room system decidiq SHALL send nothing and the live meeting SHALL work as before. (pen-02)

#### Scenario: The chair gives a member the floor

- GIVEN a live council meeting in a room whose audio and video system is linked through integriq
- WHEN the chair gives the floor to member Sem
- THEN decidiq sends the room system that Sem is speaking, with his seat
- AND the room system's camera and microphone switch to Sem

#### Scenario: No room system is linked

- GIVEN a live meeting in a room without a linked room system
- WHEN the chair gives a member the floor
- THEN decidiq makes no outbound call and the speaker list updates as usual

### Requirement: REQ-PLB-007 Residents read members' ancillary positions without an account

decidiq SHALL offer the public portal an anonymous, read-only collection of ancillary positions whose publication date has passed and whose depublication date has not, with only the fields a public register shows (member, organisation, role, paid or unpaid, start and end date). A position without a publication date SHALL never appear in it. (pen-03)

#### Scenario: A resident reads a member's outside positions

- GIVEN council member Annemarie has two published ancillary positions and one unpublished one
- WHEN a resident opens her member page on the public portal without signing in
- THEN the page lists the two published positions with organisation, role and whether they are paid
- AND the unpublished position does not appear

### Requirement: REQ-PLB-008 Every page works at phone width

Every decidiq page SHALL be usable at a viewport width of 360 pixels: no horizontal page scroll, every action reachable, and grid dashboards stacked in one column. decidiq SHALL rely on the responsive layout of Nextcloud and nextcloud-vue, and SHALL add page-specific rules only where a page breaks at that width. (plt-05)

#### Scenario: A member reads the agenda on a phone

- GIVEN member Henk opens a meeting page on a phone 360 pixels wide
- WHEN the page loads
- THEN the agenda and its papers read in one column without scrolling sideways
- AND the Join video call and document actions are reachable
