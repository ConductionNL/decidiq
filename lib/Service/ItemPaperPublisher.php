<?php

/**
 * Decidiq Item Paper Publisher
 *
 * Makes the public papers of one agenda item public through OpenRegister's
 * FileService, skipping the papers labelled confidential. Built by
 * AgendaPapers for each item it publishes.
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
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service;

use Psr\Log\LoggerInterface;

/**
 * Publishes the public papers of one agenda item.
 *
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
 */
final class ItemPaperPublisher {

	/**
	 * Constructor.
	 *
	 * @param object          $fileService OpenRegister's FileService.
	 * @param string          $itemId      The agenda item whose papers are published.
	 * @param LoggerInterface $logger      Logger for papers that could not be published.
	 */
	public function __construct(
		private readonly object $fileService,
		private readonly string $itemId,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Publish every public paper of the item.
	 *
	 * @return array{papers: list<array{title: string, url: string, type: string}>, refs: list<array{agendaItem: string, file: int}>}
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 */
	public function publishAll(): array {
		$result = ['papers' => [], 'refs' => []];
		foreach ((array)$this->listedNodes() as $node) {
			$paper = $this->publishedPaper(node: $node);
			if ($paper === null) {
				continue;
			}

			$result['papers'][] = $paper;
			$result['refs'][]   = ['agendaItem' => $this->itemId, 'file' => (int)$node->getId()];
		}

		return $result;
	}//end publishAll()

	/**
	 * The item's file nodes, or null when they cannot be listed (logged).
	 *
	 * @return mixed
	 */
	private function listedNodes(): mixed {
		try {
			return $this->fileService->getFiles($this->itemId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq publication: papers of an agenda item could not be listed',
				['item' => $this->itemId, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end listedNodes()

	/**
	 * Publish one node; null when it is no node, is confidential or fails (logged).
	 *
	 * @param mixed $node A listed file node.
	 *
	 * @return array{title: string, url: string, type: string}|null
	 */
	private function publishedPaper(mixed $node): ?array {
		if (is_object($node) === false || $this->isLabelledConfidential(file: $this->fileService->formatFile($node)) === true) {
			return null;
		}

		try {
			$formatted = $this->fileService->formatFile($this->fileService->publishFile($this->itemId, $node->getId()));
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq publication: a paper could not be made public',
				['item' => $this->itemId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->paperOf(formatted: $formatted);
	}//end publishedPaper()

	/**
	 * The publication's paper entry for a formatted, published file.
	 *
	 * @param array<string, mixed> $formatted The formatted file.
	 *
	 * @return array{title: string, url: string, type: string}
	 */
	private function paperOf(array $formatted): array {
		return [
			'title' => (string)($formatted['title'] ?? ''),
			'url' => (string)($formatted['downloadUrl'] ?? ''),
			'type' => (string)($formatted['type'] ?? ''),
		];
	}//end paperOf()

	/**
	 * Whether a formatted file carries a confidential label.
	 *
	 * @param array<string,mixed> $file The formatted file.
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 *
	 * @return bool
	 */
	private function isLabelledConfidential(array $file): bool {
		foreach ((array)($file['labels'] ?? []) as $label) {
			if (in_array(strtolower((string)$label), ['confidential', 'vertrouwelijk', 'geheim'], true) === true) {
				return true;
			}
		}

		return false;
	}//end isLabelledConfidential()
}//end class
