<?php

/**
 * Decidiq CaseSystemClient
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
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\CaseSystemException;
use OCA\Decidiq\Kernel\Contracts\CaseSystemClientInterface;
use OCA\Decidiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Reaches the organisation's case system through integriq, and only through
 * integriq.
 *
 * The app declares the `case-system` connection (lib/Settings/connections.json);
 * an administrator links an integriq source to it. integriq speaks the ZGW APIs
 * or StUF-ZKN and maps decidiq's document kinds onto the organisation's
 * document and case types. decidiq names the intent: one POST per operation
 * under /case-system/ on the linked source, with a JSON body.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
 */
class CaseSystemClient implements CaseSystemClientInterface {
	/**
	 * The connection key decidiq declares.
	 */
	public const CONNECTION_KEY = 'case-system';

	/**
	 * Finds the linked source and reads call statuses.
	 *
	 * @var IntegriqConnection
	 */
	private readonly IntegriqConnection $connection;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service (integriq's connection rows and sources).
	 * @param ContainerInterface     $container     DI container (integriq's call service, lazily).
	 * @param SigningAnswer          $answers       Reads the body of an integriq call answer.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 */
	public function __construct(
		ObjectServiceInterface $objectService,
		private readonly ContainerInterface $container,
		private readonly SigningAnswer $answers,
	) {
		$this->connection = new IntegriqConnection(objectService: $objectService);
	}//end __construct()

	/**
	 * Whether a source is linked to the case-system connection.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 *
	 * @return bool
	 */
	public function isConnected(): bool {
		return $this->connection->isLinked(connectionKey: self::CONNECTION_KEY);
	}//end isConnected()

	/**
	 * Read one case by its number or its address.
	 *
	 * @param string $reference The case number or address.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-002-an-agenda-item-links-to-its-case
	 *
	 * @throws CaseSystemException When no case system is connected or it refuses.
	 *
	 * @return array{url:string,identification:string,title:string}|null The case, or null when it does not exist.
	 */
	public function readCase(string $reference): ?array {
		$answer = $this->call(operation: 'read-case', body: ['reference' => $reference]);
		$url    = (string)($answer['url'] ?? '');
		if ($url === '') {
			return null;
		}

		return [
			'url' => $url,
			'identification' => (string)($answer['identification'] ?? ''),
			'title' => (string)($answer['title'] ?? ''),
		];
	}//end readCase()

	/**
	 * List the documents of a case.
	 *
	 * @param string $caseUrl The case address.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @throws CaseSystemException When no case system is connected or it refuses.
	 *
	 * @return list<array{url:string,name:string}>
	 */
	public function listDocuments(string $caseUrl): array {
		$answer    = $this->call(operation: 'list-documents', body: ['case' => $caseUrl]);
		$documents = [];
		foreach ((array)($answer['documents'] ?? []) as $document) {
			if (is_array($document) === false || (string)($document['url'] ?? '') === '') {
				continue;
			}

			$documents[] = ['url' => (string)$document['url'], 'name' => (string)($document['name'] ?? $document['url'])];
		}

		return $documents;
	}//end listDocuments()

	/**
	 * Download one document from the case system.
	 *
	 * @param string $documentUrl The document's address.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @throws CaseSystemException When no case system is connected, it refuses, or the content is not readable.
	 *
	 * @return array{name:string,content:string}
	 */
	public function downloadDocument(string $documentUrl): array {
		$answer  = $this->call(operation: 'read-document', body: ['document' => $documentUrl]);
		$content = base64_decode((string)($answer['content'] ?? ''), true);
		if ($content === false || $content === '') {
			throw new CaseSystemException(message: 'The case system sent no content for ' . $documentUrl, status: 502);
		}

		return ['name' => (string)($answer['name'] ?? basename($documentUrl)), 'content' => $content];
	}//end downloadDocument()

	/**
	 * Add a document to a case.
	 *
	 * @param string              $caseUrl  The case address.
	 * @param array<string,mixed> $document name, kind, content, confidential and ground.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @throws CaseSystemException When no case system is connected or it refuses the document.
	 *
	 * @return string The document's address in the case system.
	 */
	public function addDocument(string $caseUrl, array $document): string {
		$answer = $this->call(
			operation: 'add-document',
			body: [
				'case' => $caseUrl,
				'name' => (string)($document['name'] ?? ''),
				'kind' => (string)($document['kind'] ?? ''),
				'content' => base64_encode((string)($document['content'] ?? '')),
				'confidential' => (bool)($document['confidential'] ?? false),
				'ground' => (string)($document['ground'] ?? ''),
			]
		);
		$url    = (string)($answer['url'] ?? '');
		if ($url === '') {
			$name = (string)($document['name'] ?? 'the document');
			throw new CaseSystemException(message: 'The case system did not return an address for ' . $name, status: 502);
		}

		return $url;
	}//end addDocument()

	/**
	 * Create a case for a meeting.
	 *
	 * @param string $title The meeting's title.
	 * @param string $date  The meeting's date.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
	 *
	 * @throws CaseSystemException When no case system is connected or it refuses.
	 *
	 * @return array{url:string,identification:string}
	 */
	public function createMeetingCase(string $title, string $date): array {
		$answer = $this->call(operation: 'create-case', body: ['kind' => 'meeting', 'title' => $title, 'date' => $date]);
		$url    = (string)($answer['url'] ?? '');
		if ($url === '') {
			throw new CaseSystemException(message: 'The case system did not create a case for the meeting', status: 502);
		}

		return ['url' => $url, 'identification' => (string)($answer['identification'] ?? '')];
	}//end createMeetingCase()

	/**
	 * Call one operation on the linked source.
	 *
	 * @param string              $operation The operation name.
	 * @param array<string,mixed> $body      The request body.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection
	 *
	 * @throws CaseSystemException When no case system is connected or it refuses.
	 *
	 * @return array<string,mixed> The decoded answer.
	 */
	private function call(string $operation, array $body): array {
		$source = $this->connection->source(connectionKey: self::CONNECTION_KEY);
		$caller = FleetAppId::getService($this->container, 'integriq', 'Service\CallService');
		if ($source === null || $caller === null) {
			throw new CaseSystemException(message: self::NOT_CONNECTED, status: 409);
		}

		try {
			$response = $caller->call(
				source: $source,
				endpoint: '/case-system/' . $operation,
				method: 'POST',
				config: ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json']]
			);
		} catch (Throwable $e) {
			throw new CaseSystemException(message: 'The case system could not be reached: ' . $e->getMessage(), status: 502, previous: $e);
		}

		$decoded = json_decode($this->answers->body(response: $response), true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		$status = $this->connection->statusOf(response: $response);
		if ($status >= 400) {
			throw new CaseSystemException(message: (string)($decoded['message'] ?? ('The case system answered ' . $status)), status: 502);
		}

		return $decoded;
	}//end call()
}//end class
