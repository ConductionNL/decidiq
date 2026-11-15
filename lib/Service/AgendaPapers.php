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
use OCA\OpenRegister\Contract\ObjectServiceInterface;
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
	 * @param ObjectServiceInterface $objectService OpenRegister object service (restrictions).
	 * @param ContainerInterface $container DI container (OpenRegister FileService, lazily).
	 * @param LoggerInterface    $logger    Logger.
	 *
	 * @spec openspec/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
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
		$restricted = [];
		foreach ($this->restrictionRows() as $row) {
			$target = $this->activeRestrictionTarget(row: $row);
			if ($target !== '') {
				$restricted[$target] = true;
			}
		}

		return $restricted;
	}//end restrictedItemIds()

	/**
	 * Every item-scoped confidentiality restriction row.
	 *
	 * @return iterable<mixed>
	 *
	 * @throws ConfidentialityUnreadableException When the restrictions cannot be read.
	 */
	private function restrictionRows(): iterable {
		try {
			$rows = $this->readRestrictions();
		} catch (\Throwable $e) {
			throw new ConfidentialityUnreadableException(
				message: 'The agenda was not published: the confidential items could not be checked. Try again in a moment.',
				previous: $e
			);
		}

		return ($rows['results'] ?? $rows);
	}//end restrictionRows()

	/**
	 * Read the item-scoped restrictions, past RBAC and multitenancy.
	 *
	 * @return mixed The findAll() answer.
	 */
	private function readRestrictions(): mixed {
		return $this->objectService->findAll(
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
	}//end readRestrictions()

	/**
	 * The agenda item an active restriction row targets, or '' when it targets none.
	 *
	 * @param mixed $row A restriction row or entity.
	 *
	 * @return string
	 */
	private function activeRestrictionTarget(mixed $row): string {
		$data = $this->rowData(row: $row);
		if (is_array($data) === false || in_array(($data['lifecycle'] ?? ''), self::ACTIVE_STATES, true) === false) {
			return '';
		}

		return (string)($data['targetAgendaItem'] ?? '');
	}//end activeRestrictionTarget()

	/**
	 * A row's data: an entity's serialisation, else the row itself.
	 *
	 * @param mixed $row A restriction row or entity.
	 *
	 * @return mixed
	 */
	private function rowData(mixed $row): mixed {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return $row->jsonSerialize();
		}

		return $row;
	}//end rowData()

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
		$fileService = $this->fileService();
		if ($fileService === null || $itemId === '') {
			return ['papers' => [], 'refs' => []];
		}

		$publisher = new ItemPaperPublisher(fileService: $fileService, itemId: $itemId, logger: $this->logger);

		return $publisher->publishAll();
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
		$kept        = $this->refKeys(refs: $keep);
		$fileService = $this->fileService();
		$failed      = 0;
		foreach ($refs as $ref) {
			if (is_array($ref) === false || isset($kept[$this->refKey(ref: $ref)]) === true) {
				continue;
			}

			$failed += $this->takeOffline(fileService: $fileService, ref: $ref);
		}

		return $failed;
	}//end unpublish()

	/**
	 * The keys of the well-formed refs, as a set.
	 *
	 * @param array<int, mixed> $refs The refs.
	 *
	 * @return array<string, true>
	 */
	private function refKeys(array $refs): array {
		$keys = [];
		foreach ($refs as $ref) {
			if (is_array($ref) === true) {
				$keys[$this->refKey(ref: $ref)] = true;
			}
		}

		return $keys;
	}//end refKeys()

	/**
	 * One ref's key: its agenda item and file id.
	 *
	 * @param array<string, mixed> $ref The ref.
	 *
	 * @return string
	 */
	private function refKey(array $ref): string {
		return ($ref['agendaItem'] ?? '') . '/' . ($ref['file'] ?? '');
	}//end refKey()

	/**
	 * Take one paper offline; 1 when that failed (logged), else 0.
	 *
	 * @param object|null          $fileService OpenRegister's FileService, or null when unavailable.
	 * @param array<string, mixed> $ref         The ref.
	 *
	 * @return int
	 */
	private function takeOffline(?object $fileService, array $ref): int {
		try {
			if ($fileService === null) {
				throw new RuntimeException('OpenRegister FileService unavailable');
			}

			$fileService->unpublishFile((string)$ref['agendaItem'], (int)$ref['file']);
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq publication: a paper could not be taken offline', ['ref' => $ref, 'exception' => $e->getMessage()]);
			return 1;
		}

		return 0;
	}//end takeOffline()

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
