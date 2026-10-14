// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The proposal a host object's "Create proposal" form writes.
//
// The defect this pins: both decision leaves (CnDecisionsWidget, CnDecisionsTab)
// wrote `outcome: 'pending'`, a value outside the decision schema's outcome
// enum (adopted / rejected), so OpenRegister refused every proposal created
// from a host page with a 400 (seen from a pipelinq ticket's Decisions tab).
//
// @spec openspec/specs/decidesk-contract-decision-hub/spec.md

import { describe, expect, it, vi } from 'vitest'
import { hostProposalPayload } from '../../src/integrations/decisionLink.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const host = { register: 'pipelinq', schema: 'ticket', objectId: 't-1', label: 'Fietspad' }

describe('hostProposalPayload', () => {
	it('writes no outcome: a proposal has no voting result yet', () => {
		const payload = hostProposalPayload({ title: 'Motie', text: 'Toelichting' }, host, 'Proposal')
		expect(payload).not.toHaveProperty('outcome')
		expect(payload.lifecycle).toBe('proposed')
	})

	it('links the decision back to the host object', () => {
		const payload = hostProposalPayload({ title: 'Motie' }, host, 'Proposal')
		expect(payload).toMatchObject({
			sourceApp: 'ticket',
			subjectRegister: 'pipelinq',
			subjectSchema: 'ticket',
			subjectId: 't-1',
			subjectLabel: 'Fietspad',
		})
	})

	it('falls back to the given title, motion and the host id as label', () => {
		const payload = hostProposalPayload({}, { register: 'r', schema: 's', objectId: 'o-9' }, 'Proposal')
		expect(payload.title).toBe('Proposal')
		expect(payload.decisionType).toBe('motion')
		expect(payload.text).toBe('')
		expect(payload.subjectLabel).toBe('o-9')
	})
})
