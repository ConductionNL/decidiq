<?php

/**
 * Decidiq CaseExchangeRecords
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
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\CaseSystemException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Reads and writes CaseExchangeRecord objects.
 *
 * Written in system context: the records are decidiq's account of what it
 * exchanged, the schema lets only administrators write them, and the fetch or
 * send that writes one was already authorised by the controller.
 *
 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
 */
class CaseExchangeRecords {
	/**
	 * The schema slug.
	 */
	public const SCHEMA = 'case-exchange-record';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param ITimeFactory           $timeFactory   Clock.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Write a new record, stamped with who asked and when.
	 *
	 * @param array<string,mixed> $record The record.
	 * @param string              $userId Who asked.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @return array<string,mixed> The stored record, with its id.
	 */
	public function write(array $record, string $userId): array {
		$record['requestedBy'] = $userId;
		$record['requestedAt'] = $this->timeFactory->getDateTime()->format(DATE_ATOM);
		$record = array_filter($record, static fn (mixed $value): bool => $value !== '' && $value !== null);

		return $this->save(record: $record, uuid: null);
	}//end write()

	/**
	 * Replace a record's lines and target.
	 *
	 * @param string              $recordId The record.
	 * @param array<string,mixed> $record   The record as it now is.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @return array<string,mixed> The stored record.
	 */
	public function update(string $recordId, array $record): array {
		unset($record['id'], $record['@self']);
		return $this->save(record: $record, uuid: $recordId);
	}//end update()

	/**
	 * Read one record.
	 *
	 * @param string $recordId The record.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @throws CaseSystemException When it does not exist.
	 *
	 * @return array<string,mixed>
	 */
	public function find(string $recordId): array {
		$entity = $this->objectService->find(id: $recordId, register: 'decidiq', schema: self::SCHEMA, _rbac: false, _multitenancy: false);
		if ($entity === null) {
			throw new CaseSystemException(message: 'Exchange record not found', status: 404);
		}

		return ['id' => $recordId] + (array)$entity->getObject();
	}//end find()

	/**
	 * The records of one agenda item in one direction.
	 *
	 * @param string $itemId    The agenda item.
	 * @param string $direction fetch or send.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-003-the-griffier-fetches-case-documents-onto-the-item-once-each
	 *
	 * @return list<array<string,mixed>>
	 */
	public function forItem(string $itemId, string $direction): array {
		return $this->findBy(filters: ['agendaItem' => $itemId, 'direction' => $direction]);
	}//end forItem()

	/**
	 * Read records matching the filters.
	 *
	 * @param array<string,string> $filters Property filters.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @return list<array<string,mixed>>
	 */
	private function findBy(array $filters): array {
		$rows = $this->objectService->findAll(
			config: ['filters' => (['register' => 'decidiq', 'schema' => self::SCHEMA] + $filters), 'limit' => 500],
			_rbac: false,
			_multitenancy: false
		);

		$records = [];
		foreach (($rows['results'] ?? $rows) as $row) {
			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$records[] = ['id' => (string)$row->getUuid()] + (array)$row->getObject();
			}
		}

		return $records;
	}//end findBy()

	/**
	 * Save a record in system context.
	 *
	 * @param array<string,mixed> $record The record.
	 * @param string|null         $uuid   The record's id, null for a new one.
	 *
	 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
	 *
	 * @return array<string,mixed>
	 */
	private function save(array $record, ?string $uuid): array {
		$saved = $this->objectService->saveObject(
			object: $record,
			register: 'decidiq',
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		return ['id' => (string)$saved->getUuid()] + (array)$saved->getObject();
	}//end save()
}//end class
