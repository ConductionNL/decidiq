---
status: partial
status-note: 2026-06-12 audit — 1/4 requirements built (governing-body management via governance-bodies + governance-body-crud capabilities). Missing — process-template assignment UI, organization configuration UI, member import (Groups/CSV), role assignment UI.
---

# Admin Settings Specification

## Purpose
Admin settings enable organization administrators to configure Decidesk for their specific governance context. This includes setting up governing bodies (bodies), assigning members with roles, selecting process templates, configuring voting rules, and managing the OpenRegister schema setup. The admin interface is the first thing configured after installation and determines how the entire system behaves.

**Standards**: Nextcloud Settings API (`OCP\Settings\ISettings`), Schema.org (`Organization`, `Role`)
**Feature tier**: MVP

## Requirements

---

### Requirement: Governing Body Management

The system MUST support creating and managing governing bodies (bestuursorganen). Each body MUST have a name, type (council, board, assembly, committee, team), member list with roles, default process template, and quorum rules. Bodies MUST be stored as OpenRegister objects in the `decidesk` register using the `body` schema.

**Feature tier**: MVP

#### Scenario: Create a governing body for an association board

@e2e openspec/specs/admin-settings/spec.md#create-a-governing-body-for-an-association-board

- GIVEN an administrator in the Decidesk admin settings
- WHEN they create a body with name "Bestuur", type "board", and add 5 members with roles (chair, secretary, treasurer, member, member)
- THEN the system MUST create an OpenRegister object with the `body` schema
- AND each member MUST be linked to a Nextcloud user account
- AND the default process template MUST be selectable from available templates

#### Scenario: Configure quorum rules for a body

@e2e openspec/specs/admin-settings/spec.md#configure-quorum-rules-for-a-body

- GIVEN an existing body "Algemene Ledenvergadering" with 200 members
- WHEN the administrator sets quorum to "50%+1 of members present or represented"
- THEN the quorum rule MUST be stored on the body configuration
- AND the quorum MUST be automatically calculated at each meeting

#### Scenario: Assign roles within a body

@e2e exclude Role assignment is a backend permission-semantics concern (chair/secretary/member capabilities). The members tab on a governance body renders empty in the live SPA — no role-assignment UI exists to drive from Playwright.

- GIVEN an existing body with members
- WHEN the administrator assigns the "chair" role to a member
- THEN the member MUST have chair-specific permissions (start votes, manage agenda, set speaking order)
- AND the "secretary" role MUST grant minute-taking and convocation permissions
- AND the "member" role MUST grant voting and speaking rights only

---

### Requirement: Process Template Assignment

The system MUST allow administrators to assign process templates to bodies. Each body MUST have a default template and MAY have additional templates for specific decision types (e.g., statute amendment, board election).

**Feature tier**: MVP

#### Scenario: Assign default and specialized templates to a body

@e2e exclude No template-assignment UI exists. The governance body create dialog exposes a free-text workflowTemplate field but there is no specialized-template management surface in the SPA to drive from Playwright.

- GIVEN a body "ALV" with a default template "ALV Standard Decision"
- WHEN the administrator adds a specialized template "ALV Statute Amendment" for statute changes
- THEN the body MUST have both templates available
- AND when creating a decision, the user MUST be able to choose the applicable template
- AND if no template is chosen, the default MUST apply

---

### Requirement: Organization Configuration

The system MUST support configuring organization-level settings: organization name, logo, default language (nl/en), timezone, currency for cost calculations, and archival retention period.

**Feature tier**: MVP

#### Scenario: Configure organization defaults

@e2e exclude No organization-defaults UI exists (org name/logo/timezone/currency). The decidesk admin settings panel only exposes a version-info widget and register-mapping configuration — nothing to drive from Playwright.

- GIVEN the administrator opens the organization settings
- WHEN they set organization name "Vereniging De Harmonie", language "nl", timezone "Europe/Amsterdam", and currency "EUR"
- THEN these defaults MUST apply to all meetings, decisions, and generated documents
- AND the organization name and logo MUST appear on generated resolutions and minutes

---

### Requirement: Member Import

The system MUST support importing members from Nextcloud Groups, Nextcloud Contacts, or CSV file. Imported members MUST be linked to Nextcloud user accounts where possible.

**Feature tier**: MVP

#### Scenario: Import members from a Nextcloud group

@e2e exclude No member-import UI exists. There is no Nextcloud-group import surface in the SPA to drive from Playwright.

- GIVEN a Nextcloud group "bestuur" with 5 members
- WHEN the administrator imports the group into a Decidesk body
- THEN all 5 Nextcloud users MUST be added as body members
- AND their display names and email addresses MUST be populated from Nextcloud
- AND the administrator MUST be able to assign roles after import

#### Scenario: Import members from CSV

@e2e exclude No CSV-import UI exists. There is no file-upload import surface in the SPA to drive from Playwright.

- GIVEN a CSV file with columns: name, email, role
- WHEN the administrator uploads the CSV for a body
- THEN the system MUST create member entries for each row
- AND members with matching Nextcloud accounts (by email) MUST be automatically linked
- AND unmatched members MUST be flagged for manual linking or invitation

## User Stories

1. **Board secretary managing conflict of interest register**: As a board secretary, I want to maintain a digital conflict of interest register for all board and supervisory board members, so that potential conflicts are proactively identified before meetings. (Source: intelligence DB #23)

2. **Supervisory board chair managing director appointment**: As a supervisory board chair, I want to manage the full director appointment process from vacancy to formal appointment, so that governance procedures are properly followed. (Source: intelligence DB #28)

3. **Board secretary organizing document archive**: As a board secretary, I want to maintain a structured, searchable governance document archive with access controls, so that governance documents are secure, findable, and properly retained. (Source: intelligence DB #43)

## Acceptance Criteria

- Governing bodies are stored as OpenRegister objects with member lists and roles
- Body roles (chair, secretary, treasurer, member) map to specific permissions
- Process templates are assignable to bodies with default and specialized options
- Organization-level settings (name, logo, language, timezone) are configurable
- Member import from Nextcloud Groups, Contacts, and CSV is supported
- Quorum rules are configurable per body
- Admin settings use Nextcloud Settings API (OCP\Settings\ISettings)
