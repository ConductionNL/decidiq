// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Whether a paper shows "Openbaar" or "Besloten" in the Stukken list
// (agenda-item-confidential-papers, REQ-ACP-001). A paper is public unless
// its record says public: false; on a non-public item every paper is closed,
// whatever its record still says.
//
// @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public

/**
 * Whether a paper is public.
 *
 * @param {?object} record The paper's DigitalDocument record, if any.
 * @param {?object} item The agenda item the paper is on, if known.
 * @return {boolean}
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public
 */
export function isPaperPublic(record, item = null) {
	return item?.public !== false && record?.public !== false
}
