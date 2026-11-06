<?php

/**
 * Decidiq CaseDocumentService
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
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\CaseSystemException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Links an agenda item to its case and fetches the case's documents onto the
 * item, once each.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
 */
class CaseDocumentService {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param CaseSystemClient       $client        The case system, through integriq.
	 * @param CaseExchangeRecords    $records       The exchange records.
	 * @param ContainerInterface     $container     DI container (OpenRegister FileService, lazily).
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly CaseSystemClient $client,
		private readonly CaseExchangeRecords $records,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Link an agenda item to a case, after reading the case once.
	 *
	 * @param string $itemId    The agenda item.
	 * @param string $reference The case number or address.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
	 *
	 * @throws CaseSystemException When the case does not exist or the case system refuses.
	 *
	 * @return array{url:string,identification:string,title:string} The stored case reference.
	 */
	public function link(string $itemId, string $reference): array {
		$reference = trim($reference);
		if ($reference === '') {
			throw new CaseSystemException(message: 'Enter a case number or address');
		}

		$item = $this->item(itemId: $itemId);
		$case = $this->client->readCase(reference: $reference);
		if ($case === null) {
			throw new CaseSystemException(message: 'Case ' . $reference . ' was not found in the case system');
		}

		$item['caseReference'] = $case;
		unset($item['id'], $item['@self']);
		$this->objectService->saveObject(object: $item, register: 'decidiq', schema: 'agenda-item', uuid: $itemId);

		return $case;
	}//end link()

	/**
	 * The case's documents, each marked when it was fetched before.
	 *
	 * @param string $itemId The agenda item.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @throws CaseSystemException When the item has no case or the case system refuses.
	 *
	 * @return list<array{url:string,name:string,fetched:bool}>
	 */
	public function listDocuments(string $itemId): array {
		$case    = $this->caseOf(item: $this->item(itemId: $itemId));
		$fetched = $this->fetchedUrls(itemId: $itemId);
		$result  = [];
		foreach ($this->client->listDocuments(caseUrl: $case['url']) as $document) {
			$result[] = $document + ['fetched' => isset($fetched[$document['url']])];
		}

		return $result;
	}//end listDocuments()

	/**
	 * Copy the chosen case documents into the item's files, skipping any
	 * fetched before.
	 *
	 * @param string       $itemId The agenda item.
	 * @param list<string> $urls   The documents' addresses.
	 * @param string       $userId Who fetched.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @throws CaseSystemException When the item has no case, or no document was chosen.
	 *
	 * @return array<string,mixed> The exchange record.
	 */
	public function fetch(string $itemId, array $urls, string $userId): array {
		$item    = $this->item(itemId: $itemId);
		$case    = $this->caseOf(item: $item);
		$fetched = $this->fetchedUrls(itemId: $itemId);
		$lines   = [];
		foreach (array_values(array_unique(array_map('strval', $urls))) as $url) {
			if ($url === '' || isset($fetched[$url]) === true) {
				continue;
			}

			$lines[] = $this->fetchOne(itemId: $itemId, url: $url);
		}

		if ($lines === []) {
			throw new CaseSystemException(message: 'Every chosen document is already on the agenda item');
		}

		return $this->records->write(
			record: [
				'meeting' => (string)($item['meeting'] ?? ''),
				'agendaItem' => $itemId,
				'direction' => 'fetch',
				'target' => $case['url'],
				'targetLabel' => $case['identification'],
				'lines' => $lines,
			],
			userId: $userId
		);
	}//end fetch()

	/**
	 * Fetch one document into the item's files, as one record line.
	 *
	 * @param string $itemId The agenda item.
	 * @param string $url    The document's address.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @return array<string,mixed> The line.
	 */
	private function fetchOne(string $itemId, string $url): array {
		$line = ['name' => basename($url), 'kind' => 'item-document', 'source' => $url, 'remoteUrl' => $url, 'status' => 'failed'];
		try {
			$document     = $this->client->downloadDocument(documentUrl: $url);
			$line['name'] = $document['name'];
			$file         = $this->container->get('OCA\OpenRegister\Service\FileService')->addFile($itemId, $document['name'], $document['content']);
			$line['fileId'] = (int)$file->getId();
			$line['status'] = 'sent';
		} catch (Throwable $e) {
			$line['error'] = $e->getMessage();
		}

		return $line;
	}//end fetchOne()

	/**
	 * The addresses of every document fetched onto the item before.
	 *
	 * @param string $itemId The agenda item.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @return array<string,true>
	 */
	private function fetchedUrls(string $itemId): array {
		$urls = [];
		foreach ($this->records->forItem(itemId: $itemId, direction: 'fetch') as $record) {
			foreach ((array)($record['lines'] ?? []) as $line) {
				if (($line['status'] ?? '') === 'sent' && (string)($line['remoteUrl'] ?? '') !== '') {
					$urls[(string)$line['remoteUrl']] = true;
				}
			}
		}

		return $urls;
	}//end fetchedUrls()

	/**
	 * The item's case reference.
	 *
	 * @param array<string,mixed> $item The agenda item.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
	 *
	 * @throws CaseSystemException When the item is not linked to a case.
	 *
	 * @return array{url:string,identification:string}
	 */
	private function caseOf(array $item): array {
		$case = (array)($item['caseReference'] ?? []);
		$url  = (string)($case['url'] ?? '');
		if ($url === '') {
			throw new CaseSystemException(message: 'Link the agenda item to a case first');
		}

		return ['url' => $url, 'identification' => (string)($case['identification'] ?? '')];
	}//end caseOf()

	/**
	 * Read an agenda item.
	 *
	 * @param string $itemId The agenda item.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
	 *
	 * @throws CaseSystemException When the item does not exist.
	 *
	 * @return array<string,mixed>
	 */
	private function item(string $itemId): array {
		$entity = $this->objectService->find(id: $itemId, register: 'decidiq', schema: 'agenda-item');
		if ($entity === null) {
			throw new CaseSystemException(message: 'Agenda item not found', status: 404);
		}

		return $entity->getObject();
	}//end item()
}//end class
