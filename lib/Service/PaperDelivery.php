<?php

/**
 * Decidiq Paper Delivery
 *
 * Serves a located paper for PaperController: as it is when the meeting's
 * watermark policy does not apply, otherwise only stamped with the reader's
 * name and the date. A paper that cannot be stamped is refused with the
 * reason, and no file is sent.
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
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\PaperStampRefusedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves a paper, stamped when the meeting asks for it.
 *
 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 */
class PaperDelivery {

	/**
	 * Constructor.
	 *
	 * @param PaperLocator    $locator Finds the paper and its meeting
	 * @param PaperStamper    $stamper The watermark policy and stamp
	 * @param LoggerInterface $logger  Logger
	 * @param RestrictedPaperViews $views Records the opening of a paper under an active restriction
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 */
	public function __construct(
		private readonly PaperLocator $locator,
		private readonly PaperStamper $stamper,
		private readonly LoggerInterface $logger,
		private readonly RestrictedPaperViews $views,
	) {
	}//end __construct()

	/**
	 * The bytes to send, or the refusal to answer with.
	 *
	 * The object was already read under the caller's own rights; a file that
	 * is not one of its papers, or is gone, is refused as not found.
	 *
	 * @param array{schema: string, objectId: string, data: array<string, mixed>, fileId: int} $located The object read, and the file
	 * @param array{uid: string, displayName: string}                                        $reader  The reader
	 *
	 * @return array{content: string, name: string, mimeType: string}|array{refusal: string, status: int}
	 *
	 * @spec openspec/changes/agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-007-view-audit-trail-for-stukken-under-geheimhouding
	 */
	public function deliver(array $located, array $reader): array {
		$paper = $this->locator->locate(schema: $located['schema'], objectId: $located['objectId'], data: $located['data'], fileId: $located['fileId']);
		if ($paper === null) {
			return ['refusal' => 'Not found.', 'status' => 404];
		}

		// The opening of a paper under an active restriction is recorded
		// before a byte is read; when it cannot be, nothing is sent.
		$refused = $this->views->record(readerUid: $reader['uid'], agendaItemId: $paper['agendaItemId'], fileId: $paper['fileId']);
		if ($refused !== null) {
			return ['refusal' => $refused, 'status' => 503];
		}

		$file = $paper['file'];
		try {
			if ($this->stamper->mustStamp(meeting: $paper['meeting'], agendaItemId: $paper['agendaItemId']) === false) {
				return ['content' => $file->getContent(), 'name' => $file->getName(), 'mimeType' => $file->getMimetype()];
			}

			$stamped = $this->stamper->stamped(
				meeting: $paper['meeting'],
				paper: ['fileId' => $paper['fileId'], 'etag' => (string)$file->getEtag(), 'content' => $file->getContent()],
				reader: $reader
			);
		} catch (PaperStampRefusedException $e) {
			return ['refusal' => $e->getMessage(), 'status' => $e->getStatus()];
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: a paper could not be opened', ['fileId' => $paper['fileId'], 'exception' => $e->getMessage()]);
			return ['refusal' => 'The paper could not be opened.', 'status' => 500];
		}

		return ['content' => $stamped, 'name' => $file->getName(), 'mimeType' => 'application/pdf'];
	}//end deliver()
}//end class
