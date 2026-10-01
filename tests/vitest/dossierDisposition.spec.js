// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The archival dossier panel's choices (records-management-archiving,
 * tasks 4 to 6): which state a described dossier is in, and the action URLs.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 */
import { describe, expect, it } from 'vitest'
import {
	canRenderCertificate,
	certificateUrl,
	dispositionUrl,
	outcomeUrl,
	panelState,
} from '../../src/utils/dossierDisposition.js'

const closed = { lifecycle: 'closed', route: 'transfer', category: '2.1', transferAvailable: true }

describe('the dossier disposition panel', () => {
	it('offers the hand-over only for a closed, routed dossier OpenRegister can take', () => {
		expect(panelState(closed)).toBe('ready')
		expect(panelState({ ...closed, route: 'destruction', transferAvailable: false })).toBe('ready')
		expect(panelState({ ...closed, lifecycle: 'forming' })).toBe('forming')
		expect(panelState({ ...closed, route: null })).toBe('no-category')
	})

	it('says transfer is unavailable when OpenRegister has no e-depot connection', () => {
		expect(panelState({ ...closed, transferAvailable: false })).toBe('transfer-unavailable')
	})

	it('waits on OpenRegister once the dossier is on a list, and is done when carried out', () => {
		expect(panelState({ ...closed, transferList: 't-1' })).toBe('on-list')
		expect(panelState({ ...closed, lifecycle: 'transferred', transferList: 't-1' })).toBe('done')
		expect(panelState({ ...closed, lifecycle: 'destroyed', destructionList: 'd-1' })).toBe('done')
	})

	it('asks for the certificate only for a dossier destroyed through a list', () => {
		expect(canRenderCertificate({ lifecycle: 'destroyed', destructionList: 'd-1' })).toBe(true)
		expect(canRenderCertificate({ lifecycle: 'closed', destructionList: 'd-1' })).toBe(false)
		expect(canRenderCertificate({ lifecycle: 'destroyed' })).toBe(false)
	})

	it('builds the routes the controller serves', () => {
		expect(dispositionUrl('a/b')).toBe('/apps/decidiq/api/dossiers/a%2Fb/disposition')
		expect(outcomeUrl('x')).toBe('/apps/decidiq/api/dossiers/x/outcome')
		expect(certificateUrl('x')).toBe('/apps/decidiq/api/dossiers/x/certificate')
	})
})
