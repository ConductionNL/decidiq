// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// useRelationStore — small helper that wires the lib's useObjectStore
// to the decidesk register/schema map for cross-schema relation tabs.
//
// Tabs mounted inside `CnObjectSidebar` need to fetch child objects
// (e.g. participants under a meeting, agenda-items under a meeting).
// CnObjectSidebar forwards `register` (parent register) but not the
// child schema slug, so each tab registers its child object type with
// the lib's useObjectStore on first use.
//
// Schema slugs are sourced from the settings store (settings.<x>Schema
// fields, populated server-side from lib/Settings/decidesk_register.json).
// Falls back to the literal slug when the settings store hasn't loaded.

// Use the decidesk-specific store instance (id 'decidesk-objects'),
// not the lib's default 'conduction-objects' store. initializeStores()
// in src/store/store.js registers all object types on this instance,
// so the relation tabs share state with the rest of the app.
import { useObjectStore, useSettingsStore } from '../../store/store.js'

/**
 * Logical type slugs used inside the decidesk tab components.
 * Each maps to the settings key holding the OpenRegister schema slug.
 */
const TYPE_TO_SETTINGS_KEY = {
	'governance-body': 'governanceBodySchema',
	meeting: 'meetingSchema',
	participant: 'participantSchema',
	'agenda-item': 'agendaItemSchema',
	// ADR-005: motion and amendment are folded into the unified Decision
	// supertype (decisionType discriminator). The logical relation types are
	// kept so existing tab components keep working, but both now resolve to
	// the `decision` schema; callers add a `decisionType` filter to narrow.
	motion: 'decisionSchema',
	amendment: 'decisionSchema',
	'voting-round': 'votingRoundSchema',
	vote: 'voteSchema',
	decision: 'decisionSchema',
	'action-item': 'actionItemSchema',
	minutes: 'minutesSchema',
	'engagement-record': 'engagementRecordSchema',
	// C6 decision-detail-fullpicture: the route tab reads a Decision's `route`
	// relation, which resolves to DecisionStage objects (slug `decision-stage`),
	// and resolves each stage's decision-maker name from a Person or
	// GovernanceBody. These settings keys are not in SettingsService::CONFIG_KEYS,
	// so ensureRelationType falls back to the literal logical-type slug — which
	// matches the schema slug in decidesk_register.json (decision-stage / person /
	// governance-body). The mappings are kept explicit for documentation.
	'decision-stage': 'decisionStageSchema',
	person: 'personSchema',
	// model-debt-cleanup-code: GovernanceBodyMembersTab.vue + its 4 dialogs
	// read/write `membership` (Popolo Membership) instead of the deprecated
	// `participant` shim. No settings key exists, so this falls back to the
	// literal slug `membership`, matching decidesk_register.json.
	membership: 'membershipSchema',
	// publish-decisions-via-opencatalogi: the publication overview + detail
	// actions read PublicationRecord objects via the OR object API. No
	// settings key exists, so this falls back to the literal slug
	// `publication-record`, matching the schema slug in decidesk_register.json.
	'publication-record': 'publicationRecordSchema',
	// board-self-evaluation: no settings key exists for these new schemas, so
	// each falls back to its literal slug, matching decidesk_register.json.
	'board-evaluation': 'boardEvaluationSchema',
	'evaluation-template': 'evaluationTemplateSchema',
	'evaluation-response': 'evaluationResponseSchema',
}

/**
 * Ensure a logical type is registered with the shared object store.
 *
 * @param {string} type Logical type slug (key of TYPE_TO_SETTINGS_KEY).
 * @return {object} The lib's object store instance, with the type
 *                  registered (idempotent).
 */
export function ensureRelationType(type) {
	const objectStore = useObjectStore()
	const settingsStore = useSettingsStore()
	const settings = settingsStore.getSettings || {}
	const register = settings.register || 'decidesk'

	const settingsKey = TYPE_TO_SETTINGS_KEY[type]
	const schemaSlug = (settingsKey && settings[settingsKey]) || type

	if (!objectStore.objectTypeRegistry[type]) {
		objectStore.registerObjectType(type, schemaSlug, register)
	}
	return objectStore
}

// --- Membership/Person helpers (model-debt-cleanup-code) ---------------
//
// GovernanceBodyMembersTab.vue and its four dialogs (MemberAddDialog,
// MemberGroupImportDialog, MemberCsvImportDialog, MemberRoleDialog) used to
// read/write the deprecated flat `Participant` schema directly. They now
// read/write `Membership` (Popolo: relationship between a Person and a
// GovernanceBody) joined to `Person` (identity only). These helpers are
// plain functions (no Vue/DOM dependency) so they stay unit-testable —
// mirroring the PHP-side ParticipantToPersonMembershipResolver's
// email-match-else-create step (design.md Decision 1) on the client side.
// Kept in this file (not a new module) since it is already the shared home
// for the tabs' cross-schema relation plumbing.

/**
 * A Membership is active when it carries no endDate (Popolo: still
 * current). Mirrors the "Remove from body" semantics (endDate = departure
 * date), matching the existing member-onboarding offboarding pattern.
 *
 * @param {object} membership A Membership object
 * @return {boolean} True when the membership has no endDate
 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
 */
export function isActiveMembership(membership) {
	return !membership?.endDate
}

/**
 * Join one Membership to its Person into the flat display-row shape the
 * Members tab's CnDataTable and dialogs expect. Keeps the same field names
 * (`displayName`, `email`, `nextcloudUserId`) the deprecated Participant
 * shim used, so memberImport.js's duplicate-detection helpers
 * (`markGroupDuplicates`/`validateMemberRows`) keep working unchanged
 * against the new source rows.
 *
 * @param {object} membership A Membership object
 * @param {object|null} [person] The Membership's Person, or null/undefined if unresolved
 * @return {object} A denormalised display row
 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
 */
export function buildMemberRow(membership, person) {
	return {
		id: membership.id,
		person: membership.person,
		governanceBody: membership.governanceBody,
		role: membership.role || '',
		party: membership.party || '',
		votingWeight: membership.votingWeight,
		endDate: membership.endDate || null,
		displayName: person?.name || membership.person || '',
		email: person?.email || '',
		nextcloudUserId: person?.nextcloudUserId || '',
	}
}

/**
 * Filter a Membership collection to active rows and join each to its
 * Person, keyed by Person id (OpenRegister's filter API has no "is null"
 * operator, so the active/ended split happens client-side — same landmine
 * as MemberAddDialog's pre-existing negation-filter workaround).
 *
 * @param {Array<object>} memberships Membership objects
 * @param {Object<string, object>} [personsById] Person objects keyed by id
 * @return {Array<object>} Active membership rows, joined to their Person
 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
 */
export function buildMemberRows(memberships, personsById = {}) {
	return (memberships || [])
		.filter(isActiveMembership)
		.map((membership) =>
			buildMemberRow(membership, personsById[membership.person]),
		)
}

/**
 * Build a Person creation payload from raw identity fields, omitting
 * empty optional properties.
 *
 * @param {object} fields Identity fields to build the payload from
 * @param {string} fields.name Full name (Person.name, required)
 * @param {string} [fields.email] Email (Person.email)
 * @param {string} [fields.nextcloudUserId] Nextcloud UID (Person.nextcloudUserId)
 * @return {object} Person creation payload
 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
 */
export function buildPersonPayload({ name, email = '', nextcloudUserId = '' }) {
	const payload = { name: (name || '').trim() }
	if (email) {
		payload.email = email
	}
	if (nextcloudUserId) {
		payload.nextcloudUserId = nextcloudUserId
	}
	return payload
}

/**
 * Build a Membership creation/update payload, omitting empty optional
 * properties.
 *
 * @param {object} fields Membership fields to build the payload from
 * @param {string} fields.personId Person UUID (Membership.person)
 * @param {string} fields.governanceBodyId GovernanceBody UUID (Membership.governanceBody)
 * @param {string} fields.role Membership.role
 * @param {string} [fields.party] Membership.party
 * @param {number} [fields.votingWeight] Membership.votingWeight
 * @param {string} [fields.id] Existing Membership id — include to update rather than create
 * @return {object} Membership creation/update payload
 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
 */
export function buildMembershipPayload({
	personId,
	governanceBodyId,
	role,
	party = '',
	votingWeight = null,
	id = '',
}) {
	const payload = { person: personId, governanceBody: governanceBodyId, role }
	if (party) {
		payload.party = party
	}
	if (votingWeight !== null && votingWeight !== undefined) {
		payload.votingWeight = votingWeight
	}
	if (id) {
		payload.id = id
	}
	return payload
}

/**
 * Resolve an existing Person by exact email match, or create a new one.
 * Mirrors the PHP crosswalk resolver's email-match-else-create step
 * (ParticipantToPersonMembershipResolver, design.md Decision 1) for the
 * client-side import/create flows — deliberately conservative, never
 * merges on a name-similarity heuristic.
 *
 * @param {object} personStore Registered object-store instance for type 'person' (fetchCollection/saveObject)
 * @param {object} fields See buildPersonPayload
 * @return {Promise<object|null>} The matched or newly created Person, or null on failure
 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
 */
export async function resolveOrCreatePerson(personStore, fields) {
	const email = (fields.email || '').trim()
	if (email) {
		const matches = await personStore.fetchCollection('person', {
			email,
			_limit: 1,
		})
		if (matches && matches.length > 0) {
			return matches[0]
		}
	}
	return personStore.saveObject('person', buildPersonPayload(fields))
}
