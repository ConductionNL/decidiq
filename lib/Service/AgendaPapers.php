<?php

/**
 * Decidiq Agenda Papers
 *
 * Decides which agenda items are under confidentiality and makes the papers
 * of the public ones public (and offline again on withdrawal).
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

use OCA\Decidiq\Exception\ConfidentialityUnreadableException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The papers side of publishing an agenda.
 *
 * Confidentiality lives in `confidentiality-restriction` objects (scope item,
 * targetAgendaItem, lifecycle imposed / ratified / dissolved), not on the
 * agenda item. Reading them fails closed: an agenda whose restrictions cannot
 * be read is not published, because publishing it could put a confidential
 * item and its papers in front of the public.
 *
 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
 */
class AgendaPapers {
	/**
	 * Restriction states that keep an item out of the public.
	 */
	private const ACTIVE_STATES = ['imposed', 'ratified'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy OpenRegister services).
	 * @param LoggerInterface    $logger    Logger.
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The ids of the agenda items under an imposed or ratified restriction.
	 *
	 * Read in system context: whether an item is confidential must not
	 * depend on whether the publishing clerk may read the restriction.
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 *
	 * @throws ConfidentialityUnreadableException When the restrictions cannot be read.
	 *
	 * @return array<string,true> Restricted item ids as keys.
	 */
	public function restrictedItemIds(): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => 'decidiq',
						'schema' => 'confidentiality-restriction',
						'scope' => 'item',
					],
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			throw new ConfidentialityUnreadableException('The agenda was not published: the confidential items could not be checked. Try again in a moment.', 0, $e);
		}

		$restricted = [];
		foreach (($rows['results'] ?? $rows) as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === false || in_array(($data['lifecycle'] ?? ''), self::ACTIVE_STATES, true) === false) {
				continue;
			}

			$target = (string)($data['targetAgendaItem'] ?? '');
			if ($target !== '') {
				$restricted[$target] = true;
			}
		}

		return $restricted;
	}//end restrictedItemIds()

	/**
	 * Make the papers of one public agenda item public.
	 *
	 * A paper labelled confidential is skipped. A paper that cannot be made
	 * public is left out and logged; the agenda is still published.
	 *
	 * @param string $itemId The agenda item id.
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 *
	 * @return array{papers: list<array{title:string,url:string,type:string}>, refs: list<array{agendaItem:string,file:int}>}
	 */
	public function publish(string $itemId): array {
		$result = ['papers' => [], 'refs' => []];
		$fileService = $this->fileService();
		if ($fileService === null || $itemId === '') {
			return $result;
		}

		try {
			$nodes = $fileService->getFiles($itemId);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq publication: papers of an agenda item could not be listed', ['item' => $itemId, 'exception' => $e->getMessage()]);
			return $result;
		}

		foreach ((array)$nodes as $node) {
			if (is_object($node) === false || $this->isLabelledConfidential(file: $fileService->formatFile($node)) === true) {
				continue;
			}

			try {
				$formatted = $fileService->formatFile($fileService->publishFile($itemId, $node->getId()));
			} catch (\Throwable $e) {
				$this->logger->warning('Decidiq publication: a paper could not be made public', ['item' => $itemId, 'exception' => $e->getMessage()]);
				continue;
			}

			$result['papers'][] = [
				'title' => (string)($formatted['title'] ?? ''),
				'url' => (string)($formatted['downloadUrl'] ?? ''),
				'type' => (string)($formatted['type'] ?? ''),
			];
			$result['refs'][]   = ['agendaItem' => $itemId, 'file' => (int)$node->getId()];
		}//end foreach

		return $result;
	}//end publish()

	/**
	 * Take the papers of a withdrawn publication offline again.
	 *
	 * @param array<int,mixed> $refs The record's publishedPapers.
	 * @param array<int,mixed> $keep Papers a newer version still publishes.
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 *
	 * @return int The number of papers that could not be taken offline.
	 */
	public function unpublish(array $refs, array $keep=[]): int {
		$kept = [];
		foreach ($keep as $ref) {
			if (is_array($ref) === true) {
				$kept[($ref['agendaItem'] ?? '') . '/' . ($ref['file'] ?? '')] = true;
			}
		}

		$fileService = $this->fileService();
		$failed      = 0;
		foreach ($refs as $ref) {
			if (is_array($ref) === false || isset($kept[($ref['agendaItem'] ?? '') . '/' . ($ref['file'] ?? '')]) === true) {
				continue;
			}

			try {
				if ($fileService === null) {
					throw new RuntimeException('OpenRegister FileService unavailable');
				}

				$fileService->unpublishFile((string)$ref['agendaItem'], (int)$ref['file']);
			} catch (\Throwable $e) {
				$failed++;
				$this->logger->warning('Decidiq publication: a paper could not be taken offline', ['ref' => $ref, 'exception' => $e->getMessage()]);
			}
		}

		return $failed;
	}//end unpublish()

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

	/**
	 * OpenRegister's FileService, or null when it is not there.
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 *
	 * @return object|null
	 */
	private function fileService(): ?object {
		try {
			$service = $this->container->get('OCA\OpenRegister\Service\FileService');
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq publication: FileService unavailable, agenda published without papers', ['exception' => $e->getMessage()]);
			return null;
		}

		if (is_object($service) === false || method_exists($service, 'publishFile') === false) {
			return null;
		}

		return $service;
	}//end fileService()
}//end class
