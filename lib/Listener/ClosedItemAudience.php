<?php

/**
 * What a paper of an agenda item must carry so its read rule matches the item's.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Service\ReferenceId;

/**
 * The fields a paper changes to follow its item: closed while the item is not public,
 * and the item's governance body and authorised readers, which its read rule matches on.
 */
final class ClosedItemAudience {

	/**
	 * Public false when the paper is still public.
	 *
	 * @param array<string,mixed> $paper The paper.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public
	 */
	public function closing(array $paper): array {
		if (($paper['public'] ?? true) === false) {
			return [];
		}

		return ['public' => false];
	}//end closing()

	/**
	 * The item's governance body and authorised readers where the paper differs, so its read rule matches the item's.
	 *
	 * @param array<string,mixed> $row  The paper.
	 * @param array<string,mixed> $item The paper's agenda item.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/agenda-item-confidential-papers/tasks.md#task-1-fields-and-read-rules
	 */
	public function audience(array $row, array $item): array {
		$changes = [];
		$body = self::referenceId(value: ($item['governanceBody'] ?? null));
		if ($body !== null && self::referenceId(value: ($row['governanceBody'] ?? null)) !== $body) {
			$changes['governanceBody'] = $body;
		}

		$readers = array_values(array_filter((array)($item['authorisedReaders'] ?? []), 'is_string'));
		if ($readers !== array_values((array)($row['authorisedReaders'] ?? []))) {
			$changes['authorisedReaders'] = $readers;
		}

		return $changes;
	}//end audience()

	/**
	 * The id a reference points at, or null when it names none.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string|null
	 */
	private static function referenceId(mixed $value): ?string {
		$id = (new ReferenceId())->referencedId(ref: $value);
		if ($id === '') {
			return null;
		}

		return $id;
	}//end referenceId()
}//end class
