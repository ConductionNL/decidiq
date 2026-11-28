<?php

/**
 * Decidiq ClosedPaperReads
 *
 * Every read of a paper of a non-public agenda item through decidiq's own
 * paper view is written to the item's OpenRegister audit trail first, with
 * the reader, the paper and the time, so the griffie sees it on the item's
 * history. When the entry cannot be written, the paper is not served.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-006-every-read-of-a-closed-paper-is-logged
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\Contracts\AuditLogServiceInterface;

/**
 * Records the read of a paper of a non-public item on that item.
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-006-every-read-of-a-closed-paper-is-logged
 */
class ClosedPaperReads {

	/**
	 * The audit action of a read (shared with the other openings of confidential material).
	 *
	 * @var string
	 */
	public const ACTION = 'material-access';

	/**
	 * The kind that tells these entries apart from other material access.
	 *
	 * @var string
	 */
	public const KIND = 'closed-paper-read';

	/**
	 * Constructor.
	 *
	 * @param AuditLogServiceInterface $auditLog The OpenRegister audit trail.
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-006-every-read-of-a-closed-paper-is-logged
	 */
	public function __construct(
		private readonly AuditLogServiceInterface $auditLog,
	) {
	}//end __construct()

	/**
	 * Record one read when the paper's agenda item is not public.
	 *
	 * The entry names only the item as its object, so it lands on the item's
	 * own audit trail, which the item's History tab shows.
	 *
	 * @param string               $readerUid The reader.
	 * @param array<string, mixed> $paper     `agendaItemId` (null for a meeting's paper), `item` (its data), `fileId`, `name`.
	 *
	 * @return string|null Null when the read may go ahead (recorded, or nothing to record), else the refusal.
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#scenario-the-griffie-checks-who-read-the-paper
	 */
	public function record(string $readerUid, array $paper): ?string {
		$itemId = trim((string)($paper['agendaItemId'] ?? ''));
		if ($itemId === '' || self::isClosed(item: (array)($paper['item'] ?? [])) === false) {
			return null;
		}

		$result = $this->auditLog->append(
			actor: $readerUid,
			action: self::ACTION,
			objectUids: [$itemId],
			payload: [
				'kind'       => self::KIND,
				'channel'    => 'app',
				'agendaItem' => $itemId,
				'fileId'     => (int)($paper['fileId'] ?? 0),
				'paper'      => (string)($paper['name'] ?? ''),
			]
		);
		if (($result['success'] ?? false) !== true) {
			return 'This paper is not shown: the read of a closed paper could not be recorded. Try again in a moment.';
		}

		return null;
	}//end record()

	/**
	 * Whether an item is not public: its `public` is false.
	 *
	 * @param array<string, mixed> $item The agenda item.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-006-every-read-of-a-closed-paper-is-logged
	 */
	public static function isClosed(array $item): bool {
		return ($item['public'] ?? true) === false;
	}//end isClosed()
}//end class
