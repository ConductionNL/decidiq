<?php

/**
 * Decidiq PaperWatermarkPolicy
 *
 * Whether a paper opened through decidiq must carry the reader's name and the
 * date (agenda-paper-watermark). The meeting's `watermarkPapers` decides: `on`
 * always stamps, `off` never does, and `automatic` stamps a paper of a meeting
 * that is not public or a paper under an active confidentiality restriction
 * on its agenda item or its document record.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-001-a-meeting-says-whether-its-papers-are-watermarked
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a paper is stamped.
 *
 * The restrictions are read in system context, so the answer does not depend
 * on whether the reader may see the restriction itself. A read that fails
 * answers "stamp": an unstamped confidential paper cannot be taken back, a
 * stamped public one costs nothing.
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-001-a-meeting-says-whether-its-papers-are-watermarked
 */
class PaperWatermarkPolicy {

	/**
	 * Restriction states that keep a paper confidential.
	 */
	private const ACTIVE_STATES = ['imposed', 'ratified'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the confidentiality restrictions
	 * @param LoggerInterface        $logger        Logger
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-001-a-meeting-says-whether-its-papers-are-watermarked
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a paper of this meeting must be stamped.
	 *
	 * @param array<string, mixed> $meeting      The meeting
	 * @param string|null          $agendaItemId The agenda item that holds the paper, if any
	 * @param list<string>         $documentIds  The ids of the paper's document records
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-001-a-meeting-says-whether-its-papers-are-watermarked
	 */
	public function mustStamp(array $meeting, ?string $agendaItemId = null, array $documentIds = []): bool {
		$setting = ($meeting['watermarkPapers'] ?? 'automatic');
		if ($setting === 'on') {
			return true;
		}

		if ($setting === 'off') {
			return false;
		}

		// The schema's default for isPublic is false, so a meeting that does
		// not say it is public is not.
		if (($meeting['isPublic'] ?? false) !== true) {
			return true;
		}

		return $this->isRestricted(agendaItemId: trim((string)$agendaItemId), documentIds: $documentIds);
	}//end mustStamp()

	/**
	 * The stamp line: reader, moment and the meeting's own text.
	 *
	 * @param array<string, mixed> $meeting     The meeting
	 * @param string               $displayName The reader's display name
	 * @param \DateTimeInterface   $openedAt    When the paper is opened
	 *
	 * @return string
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function stampText(array $meeting, string $displayName, \DateTimeInterface $openedAt): string {
		$parts = [trim($displayName), $openedAt->format('Y-m-d H:i')];
		$extra = trim((string)($meeting['watermarkText'] ?? ''));
		if ($extra !== '') {
			$parts[] = $extra;
		}

		return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
	}//end stampText()

	/**
	 * Whether an active restriction targets the agenda item or one of the documents.
	 *
	 * @param string       $agendaItemId The agenda item, empty when none
	 * @param list<string> $documentIds  The document records
	 *
	 * @return bool
	 */
	private function isRestricted(string $agendaItemId, array $documentIds): bool {
		$targets = [];
		if ($agendaItemId !== '') {
			$targets[] = ['scope' => 'item', 'field' => 'targetAgendaItem', 'id' => $agendaItemId];
		}

		foreach ($documentIds as $documentId) {
			if (is_string($documentId) === true && trim($documentId) !== '') {
				$targets[] = ['scope' => 'document', 'field' => 'targetDocument', 'id' => trim($documentId)];
			}
		}

		foreach ($targets as $target) {
			if ($this->targets(scope: $target['scope'], field: $target['field'], id: $target['id']) === true) {
				return true;
			}
		}

		return false;
	}//end isRestricted()

	/**
	 * Whether an active restriction of this scope targets this object.
	 *
	 * @param string $scope The restriction scope
	 * @param string $field The target field
	 * @param string $id    The target id
	 *
	 * @return bool
	 */
	private function targets(string $scope, string $field, string $id): bool {
		try {
			$rows = $this->objectService->findAll(
				config: ['filters' => ['register' => 'decidiq', 'schema' => 'confidentiality-restriction', 'scope' => $scope, $field => $id]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: confidentiality could not be read for a paper; stamping it', ['target' => $id, 'exception' => $e->getMessage()]);
			return true;
		}

		// The filter narrows the read; this check decides, so a filter
		// OpenRegister ignores can only widen the read, never skip a stamp.
		foreach ((array)($rows['results'] ?? $rows) as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true
				&& ($data['scope'] ?? '') === $scope
				&& (string)($data[$field] ?? '') === $id
				&& in_array(($data['lifecycle'] ?? ''), self::ACTIVE_STATES, true) === true
			) {
				return true;
			}
		}

		return false;
	}//end targets()
}//end class
