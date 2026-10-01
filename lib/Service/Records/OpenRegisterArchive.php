<?php

/**
 * Decidiq OpenRegister Archive
 *
 * The one place decidiq reaches OpenRegister's archival services: its e-depot
 * transport settings, transfer lists, destruction lists, certificates and the
 * register's TMLO switch. Each service is resolved by name when it is needed,
 * so an OpenRegister without the archival half answers "unavailable" instead
 * of failing the app (openregister#4228, branch feat/archival-for-apps).
 *
 * Decidiq builds no package, deletes nothing and approves nothing here: it
 * hands OpenRegister a list of records and reads back what OpenRegister did.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Records
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\Records;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OpenRegister's archival services, resolved when needed.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 */
class OpenRegisterArchive {
	private const EDEPOT = 'OCA\OpenRegister\Service\Edepot\EdepotTransferService';

	private const TRANSFER_LISTS = 'OCA\OpenRegister\Service\Edepot\TransferListService';

	private const TRANSFER_RECORDS = 'OCA\OpenRegister\Service\Edepot\TransferRecordService';

	private const DESTRUCTION_CREATOR = 'OCA\OpenRegister\Service\Archival\DestructionListCreator';

	private const DESTRUCTION_LISTS = 'OCA\OpenRegister\Service\Archival\DestructionListRepository';

	private const REGISTERS = 'OCA\OpenRegister\Db\RegisterMapper';

	/**
	 * The setting each transport cannot send without.
	 */
	private const TRANSPORT_KEYS = ['sftp' => 'host', 'openconnector' => 'sourceId'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's services
	 * @param LoggerInterface    $logger    Logger
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister has an e-depot transport it can send through. The
	 * REST transport needs an endpoint and an authentication type (an unset
	 * type means not configured, never "no authentication"); SFTP a host;
	 * OpenConnector a source.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function transferAvailable(): bool {
		$edepot = $this->service(name: self::EDEPOT, method: 'getTransportConfig');
		if ($edepot === null || $this->service(name: self::TRANSFER_LISTS, method: 'createTransferList') === null) {
			return false;
		}

		$config = (array)$edepot->getTransportConfig();
		$transport = (string)($config['transport'] ?? 'rest_api');
		if (isset(self::TRANSPORT_KEYS[$transport]) === true) {
			return (string)($config[self::TRANSPORT_KEYS[$transport]] ?? '') !== '';
		}

		return (string)($config['endpointUrl'] ?? '') !== '' && (string)($config['authenticationType'] ?? '') !== '';
	}//end transferAvailable()

	/**
	 * Create an OpenRegister transfer list over the given records. It waits
	 * in review for an archivist in OpenRegister.
	 *
	 * @param list<object> $objects The records, as OpenRegister entities
	 *
	 * @return array<string, mixed>|null The transfer list, or null when OpenRegister cannot make one
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function createTransferList(array $objects): ?array {
		$lists = $this->service(name: self::TRANSFER_LISTS, method: 'createTransferList');
		if ($lists === null || $objects === []) {
			return null;
		}

		return (array)$lists->createTransferList($objects);
	}//end createTransferList()

	/**
	 * The status of a transfer list, or null when OpenRegister does not know it.
	 *
	 * @param string $uuid The transfer list
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function transferListStatus(string $uuid): ?string {
		$records = $this->service(name: self::TRANSFER_RECORDS, method: 'loadTransferList');
		if ($records === null) {
			return null;
		}

		$list = $records->loadTransferList($uuid);
		if (is_array($list) === false) {
			return null;
		}

		return (string)($list['status'] ?? '');
	}//end transferListStatus()

	/**
	 * Ask OpenRegister for a destruction list over the given records. It
	 * judges each record by its own retention and legal holds.
	 *
	 * @param list<string> $uuids The records
	 *
	 * @return array{list: array<string, mixed>|null, refused: list<array{uuid: string, reason: string}>}|null
	 *         Null when OpenRegister has no destruction-list register or no such service
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-005-destruction-via-openregister-destruction-lists
	 */
	public function createDestructionList(array $uuids): ?array {
		$creator = $this->service(name: self::DESTRUCTION_CREATOR, method: 'createFor');
		if ($creator === null) {
			return null;
		}

		try {
			$created = (array)$creator->createFor($uuids);
		} catch (InvalidArgumentException $e) {
			$this->logger->info('Decidiq records: OpenRegister made no destruction list: ' . $e->getMessage());
			return null;
		}

		$list = ($created['list'] ?? null);
		$refused = [];
		foreach ((array)($created['refused'] ?? []) as $row) {
			$refused[] = ['uuid' => (string)($row['uuid'] ?? ''), 'reason' => (string)($row['reason'] ?? '')];
		}

		return ['list' => is_array($list) === true ? $list : null, 'refused' => $refused];
	}//end createDestructionList()

	/**
	 * A destruction list as OpenRegister keeps it (status, and after execution
	 * destroyedCount, skippedHolds, skippedErrors, skippedDecisions,
	 * certificateUuid), or null when OpenRegister does not know it.
	 *
	 * @param string $uuid The destruction list
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-005-destruction-via-openregister-destruction-lists
	 */
	public function destructionList(string $uuid): ?array {
		$lists = $this->service(name: self::DESTRUCTION_LISTS, method: 'find');
		if ($lists === null) {
			return null;
		}

		$list = $lists->find($uuid);
		if (is_object($list) === false) {
			return null;
		}

		return (array)($list->getObject() ?? []);
	}//end destructionList()

	/**
	 * OpenRegister's destruction certificates for one list.
	 *
	 * @param string $listUuid The destruction list
	 *
	 * @return array{configured: bool, results: list<array<string, mixed>>, missing: list<string>}
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
	 */
	public function certificates(string $listUuid): array {
		$lists = $this->service(name: self::DESTRUCTION_LISTS, method: 'findCertificates');
		if ($lists === null || $lists->isConfigured() !== true) {
			return ['configured' => false, 'results' => [], 'missing' => []];
		}

		$found = (array)$lists->findCertificates($listUuid);
		return [
			'configured' => true,
			'results' => array_values(array_filter((array)($found['results'] ?? []), 'is_array')),
			'missing' => array_map('strval', (array)($found['missing'] ?? [])),
		];
	}//end certificates()

	/**
	 * Whether the register keeps TMLO metadata (its configuration's
	 * tmloEnabled). OpenRegister accepts @self.tmlo on an update only then.
	 *
	 * @param string $register The register slug
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-002-dossier-level-mdto-via-openregister
	 */
	public function tmloEnabled(string $register): bool {
		$registers = $this->service(name: self::REGISTERS, method: 'find');
		if ($registers === null) {
			return false;
		}

		try {
			$entity = $registers->find($register);
		} catch (Throwable $e) {
			return false;
		}

		if (is_object($entity) === false || method_exists($entity, 'getConfiguration') === false) {
			return false;
		}

		return (($entity->getConfiguration() ?? [])['tmloEnabled'] ?? false) === true;
	}//end tmloEnabled()

	/**
	 * An OpenRegister service that has the given method, or null.
	 *
	 * @param string $name   The class name
	 * @param string $method A method the caller needs
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	private function service(string $name, string $method): ?object {
		try {
			$service = $this->container->get($name);
		} catch (Throwable $e) {
			$this->logger->info('Decidiq records: OpenRegister service ' . $name . ' unavailable: ' . $e->getMessage());
			return null;
		}

		if (is_object($service) === false || method_exists($service, $method) === false) {
			return null;
		}

		return $service;
	}//end service()
}//end class
