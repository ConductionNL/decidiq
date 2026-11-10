/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the decisions tab and the decisions widget on a host object share: the
 * host object's identity, the decisions linked to it and how they load, the
 * decision-type vocabulary for the create form, and how one decision row is
 * named, labelled and linked.
 *
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md
 */
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	isProposal,
	listDecisionTypes,
	listHostDecisions,
	objId,
	proposalFormSchema,
} from './decisionLink.js'

/** Badge variant per lifecycle; any other lifecycle is 'default'. */
const LIFECYCLE_VARIANTS = {
	decided: 'success',
	enacted: 'success',
	withdrawn: 'error',
}

/**
 * A decision's lifecycle as stored, on the object or under its data.
 *
 * @param {object} decision The decision.
 * @param {string} fallback The lifecycle when none is stored.
 * @return {string} The lifecycle.
 */
function lifecycleOf(decision, fallback) {
	return String(decision?.lifecycle ?? decision?.data?.lifecycle ?? fallback)
}

/**
 * Whether a decision is still a draft.
 *
 * @param {object} decision The decision.
 * @return {boolean} True for a draft.
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-003 outcome/lifecycle label.
 */
export function isDraft(decision) {
	return lifecycleOf(decision, '') === 'draft'
}

/**
 * The lifecycle label of a decision: Proposal, or its decided lifecycle in words.
 *
 * @param {object} decision The decision.
 * @return {string} The label; an unknown lifecycle as stored.
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-003 outcome/lifecycle label.
 */
export function lifecycleLabelOf(decision) {
	if (isProposal(decision)) {
		return t('decidiq', 'Proposal')
	}
	const lifecycle = lifecycleOf(decision, 'decided')
	const labels = {
		decided: t('decidiq', 'Decided'),
		enacted: t('decidiq', 'Enacted'),
		archived: t('decidiq', 'Archived'),
		withdrawn: t('decidiq', 'Withdrawn'),
	}
	return Object.hasOwn(labels, lifecycle) ? labels[lifecycle] : lifecycle
}

/**
 * The badge variant of a decision: info for a proposal, else by lifecycle.
 *
 * @param {object} decision The decision.
 * @return {string} The variant.
 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-003 outcome/lifecycle badge variant.
 */
export function lifecycleVariantOf(decision) {
	if (isProposal(decision)) {
		return 'info'
	}
	const lifecycle = lifecycleOf(decision, '')
	return Object.hasOwn(LIFECYCLE_VARIANTS, lifecycle) ? LIFECYCLE_VARIANTS[lifecycle] : 'default'
}

export const hostDecisionsMixin = {
	props: {
		/** Stable integration id (forwarded from the registry — always `'decidesk-decisions'`). */
		integrationId: { type: String, default: 'decidesk-decisions' },
		/** OpenRegister register id of the HOST object (slug or uuid). */
		register: { type: String, default: '' },
		/** OpenRegister schema id of the HOST object (slug or uuid). */
		schema: { type: String, default: '' },
		/** UUID of the HOST object the decisions are linked to. */
		objectId: { type: [String, Number], default: '' },
		/** Whole integration context — used as a fallback when discrete props are absent. */
		integrationContext: { type: Object, default: () => ({}) },
		/** Human label for the host object (stored as subjectLabel on created decisions). */
		objectLabel: { type: String, default: '' },
	},

	data() {
		return {
			decisions: [],
			decisionTypes: null,
			loading: false,
			creating: false,
			error: '',
			createOpen: false,
		}
	},

	computed: {
		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 host subject reference. */
		hostObjectId() {
			return String(this.objectId || this.integrationContext.objectId || '')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 host subject reference. */
		hostRegister() {
			return this.register || this.integrationContext.register || ''
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 host subject reference. */
		hostSchema() {
			return this.schema || this.integrationContext.schema || ''
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 link-out to decidiq. */
		appUrl() {
			return generateUrl('/apps/decidiq/decisions')
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 link-out to decidiq. */
		openInDecidiqLabel() {
			return t('decidiq', 'Open in decidiq')
		},

		/**
		 * The picker offers the REGISTRY's vocabulary, not a hardcoded list:
		 * a type an administrator adds appears here without a release.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 create-proposal form schema.
		 */
		createSchema() {
			return proposalFormSchema(this.decisionTypes)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler() {
				this.refresh()
			},
		},

		integrationContext: {
			handler() {
				this.refresh()
			},
		},
	},

	created() {
		this.loadDecisionTypes()
	},

	methods: {
		/**
		 * @param {object} decision The decision.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — stable list-row key.
		 */
		rowKey(decision) {
			return objId(decision)
		},

		/**
		 * @param {object} decision The decision.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — list-row title fallback.
		 */
		rowTitle(decision) {
			return (
				String(decision?.title ?? decision?.data?.title ?? '').trim()
				|| t('decidiq', 'Untitled decision')
			)
		},

		/**
		 * @param {object} decision The decision.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-003 outcome/lifecycle label.
		 */
		lifecycleLabel(decision) {
			return lifecycleLabelOf(decision)
		},

		/**
		 * @param {object} decision The decision.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-003 outcome/lifecycle badge variant.
		 */
		lifecycleVariant(decision) {
			return lifecycleVariantOf(decision)
		},

		/**
		 * @param {object} decision The decision.
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-007 deep-link to a decision.
		 */
		decisionUrl(decision) {
			const id = objId(decision)
			return id
				? generateUrl(`/apps/decidiq/decisions/${encodeURIComponent(id)}`)
				: this.appUrl
		},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 open create-proposal dialog. */
		openCreate() {
			if (this.hostObjectId) {
				this.createOpen = true
			}
		},

		/**
		 * Load the decisionType vocabulary from the registry endpoint.
		 *
		 * Fire-and-forget from created(): until it answers, the picker offers
		 * the shipped seed, and the schema is reactive so the configured
		 * vocabulary replaces it the moment it arrives.
		 *
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-002 create-proposal form schema.
		 */
		async loadDecisionTypes() {
			this.decisionTypes = await listDecisionTypes()
		},

		/**
		 * Read what a component shows beside each decision, once the list is in.
		 * The widget shows nothing more; the tab overrides this.
		 *
		 * @return {Promise<void>|void}
		 * @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 load decisions for the host object.
		 */
		loadDecisionDetails() {},

		/** @spec openspec/specs/decidesk-contract-decision-hub/spec.md — REQ-DCDH-001 load decisions for the host object. */
		async refresh() {
			if (!this.hostObjectId) {
				this.decisions = []
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.decisions = await listHostDecisions(this.hostObjectId, 100)
				await this.loadDecisionDetails()
			} catch (e) {
				this.error =
					e?.message || t('decidiq', 'Could not load decision-making.')
			} finally {
				this.loading = false
			}
		},
	},
}
