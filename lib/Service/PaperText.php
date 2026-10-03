<?php

/**
 * Decidiq Paper Text
 *
 * Reads a meeting paper for an AI summary: checks the file is attached to the
 * agenda item through OpenRegister's FileService, has OpenRegister extract its
 * text, and returns that text in parts no longer than one task's input, cut on
 * OpenRegister's chunk boundaries. OpenRegister's services are resolved from
 * the container on use, as MeetingPackageService does, so decidiq loads
 * without them.
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
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\PaperSummaryRefusedException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The text of a paper attached to an agenda item.
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
 */
class PaperText {

	/**
	 * The longest part, in characters, handed to one task (about 3,000 tokens).
	 */
	public const PART_LENGTH = 12000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (OpenRegister's file, extraction and chunk services)
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The file name of a paper attached to the agenda item.
	 *
	 * @param string $agendaItemId The agenda item
	 * @param int $fileId The paper's Nextcloud file id
	 *
	 * @return string The file name.
	 *
	 * @throws PaperSummaryRefusedException 422 when the file is not attached to the item
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
	 */
	public function titleOf(string $agendaItemId, int $fileId): string {
		try {
			$nodes = (array)$this->container->get('OCA\OpenRegister\Service\FileService')->getFiles($agendaItemId);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: the papers of an agenda item could not be listed', ['agendaItem' => $agendaItemId, 'error' => $e->getMessage()]);
			$nodes = [];
		}

		foreach ($nodes as $node) {
			if (is_object($node) === true && method_exists($node, 'getId') === true && (int)$node->getId() === $fileId) {
				return (string)$node->getName();
			}
		}

		throw new PaperSummaryRefusedException(message: 'This paper is not attached to the agenda item.', status: 422);
	}//end titleOf()

	/**
	 * The paper's text in parts of at most PART_LENGTH characters.
	 *
	 * @param int $fileId The paper's Nextcloud file id
	 *
	 * @return array<int, string> The parts, in order; one part for a short paper.
	 *
	 * @throws PaperSummaryRefusedException 422 when no text could be read
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
	 */
	public function parts(int $fileId): array {
		$parts = [];
		$part = '';
		foreach ($this->chunkTexts(fileId: $fileId) as $text) {
			foreach (mb_str_split($text, self::PART_LENGTH) as $piece) {
				$joined = $piece;
				if ($part !== '') {
					$joined = $part . "\n\n" . $piece;
				}

				if (mb_strlen($joined) <= self::PART_LENGTH) {
					$part = $joined;
					continue;
				}

				$parts[] = $part;
				$part = $piece;
			}
		}

		if ($part !== '') {
			$parts[] = $part;
		}

		if ($parts === []) {
			throw new PaperSummaryRefusedException(message: 'No text could be read from this paper.', status: 422);
		}

		return $parts;
	}//end parts()

	/**
	 * The non-empty chunk texts of the paper, after OpenRegister extracted it.
	 *
	 * @param int $fileId The paper's Nextcloud file id
	 *
	 * @return array<int, string> The texts, in chunk order.
	 *
	 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
	 */
	private function chunkTexts(int $fileId): array {
		try {
			$this->container->get('OCA\OpenRegister\Service\TextExtractionService')->extractFile($fileId);
			$chunks = (array)$this->container->get('OCA\OpenRegister\Db\ChunkMapper')->findBySource('file', $fileId);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: the text of a paper could not be read', ['fileId' => $fileId, 'error' => $e->getMessage()]);
			return [];
		}

		$texts = [];
		foreach ($chunks as $chunk) {
			if (is_object($chunk) === false) {
				continue;
			}

			$text = trim((string)$chunk->getTextContent());
			if ($text !== '') {
				$texts[] = $text;
			}
		}

		return $texts;
	}//end chunkTexts()
}//end class
