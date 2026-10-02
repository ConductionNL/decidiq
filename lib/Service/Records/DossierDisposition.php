<?php

/**
 * Decidiq Dossier Disposition
 *
 * Routes a closed archival dossier to OpenRegister by its Selectielijst
 * category: a category that keeps (bewaren) becomes an OpenRegister transfer
 * list over the dossier's records, a category that destroys (vernietigen) an
 * OpenRegister destruction list. The category is read from the register
 * (SelectionCategoryReader), never written here. Approval, packaging,
 * delivery, legal holds and deletion are OpenRegister's; the dossier only
 * reflects the outcome once OpenRegister has carried it out.
 *
 * Only an archivist (OpenRegister's `archivaris` group) or an administrator
 * routes a dossier, the same people OpenRegister lets approve the list.
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

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Hand a closed dossier to OpenRegister's transfer or destruction list.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 */
class DossierDisposition {
	/**
	 * The dossier schema's key in the register, where its category lives.
	 */
	private const SCHEMA_KEY = 'ArchivalDossier';

	private const SCHEMA = 'archival-dossier';

	private const TRANSFER = 'transfer';

	private const DESTRUCTION = 'destruction';

	/**
	 * The dossier's record kinds and the schema each lives in.
	 */
	private const MEMBER_SCHEMAS = [
		'minutes' => 'minutes',
		'decisions' => 'decision',
		'votingRounds' => 'voting-round',
		'documents' => 'digital-document',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface  $objectService OpenRegister's object facade
	 * @param SelectionCategoryReader $categories    The dossier's Selectielijst category
	 * @param OpenRegisterArchive     $archive       OpenRegister's archival services
	 * @param ArchivistGuard          $guard         Archivist or administrator
	 * @param IURLGenerator           $urlGenerator  OpenRegister's settings link
	 * @param IL10N                   $l10n          Translations
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SelectionCategoryReader $categories,
		private readonly OpenRegisterArchive $archive,
		private readonly ArchivistGuard $guard,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Where the dossier goes and whether OpenRegister can take it there now.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array<string, mixed> The id, lifecycle, route, category, action, overridden, transferAvailable, settingsUrl, transferList and destructionList
	 *
	 * @throws MissingObjectException When the dossier does not exist
	 * @throws AccessDeniedException  When the caller is not an archivist or administrator
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function describe(string $dossierId): array {
		$this->guard->requireArchivist();
		$dossier = $this->dossier(dossierId: $dossierId);
		$category = $this->categories->forObject(schemaKey: self::SCHEMA_KEY, object: $dossier);

		return [
			'id' => $dossierId,
			'lifecycle' => (string)($dossier['lifecycle'] ?? ''),
			'route' => $this->route(category: $category),
			'category' => $category['category'] ?? null,
			'action' => $category['action'] ?? null,
			'overridden' => $category['overridden'] ?? false,
			'transferAvailable' => $this->archive->transferAvailable(),
			'settingsUrl' => $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'openregister']),
			'transferList' => $dossier['transferList'] ?? null,
			'destructionList' => $dossier['destructionList'] ?? null,
		];
	}//end describe()

	/**
	 * Hand a closed dossier to OpenRegister: a transfer list for a category
	 * that keeps, a destruction list for one that destroys.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array<string, mixed> The dossier, with its id
	 *
	 * @throws MissingObjectException  When the dossier does not exist
	 * @throws AccessDeniedException   When the caller is not an archivist or administrator
	 * @throws DossierRefusedException When it is not closed, already on a list, has no category,
	 *                                 or OpenRegister cannot take it
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	public function propose(string $dossierId): array {
		$this->guard->requireArchivist();
		$dossier = $this->dossier(dossierId: $dossierId);
		if (($dossier['lifecycle'] ?? '') !== 'closed') {
			throw new DossierRefusedException(
				message: $this->l10n->t('Only a closed dossier goes to the archive or to destruction.'),
				reason: DossierRefusedException::NOT_CLOSED
			);
		}

		if (isset($dossier['transferList']) === true || isset($dossier['destructionList']) === true) {
			throw new DossierRefusedException(
				message: $this->l10n->t('The dossier is already on a list in OpenRegister.'),
				reason: DossierRefusedException::ALREADY_PROPOSED
			);
		}

		$route = $this->route(category: $this->categories->forObject(schemaKey: self::SCHEMA_KEY, object: $dossier));
		if ($route === null) {
			throw new DossierRefusedException(
				message: $this->l10n->t('The dossier schema names no Selectielijst category, so it cannot be routed.'),
				reason: DossierRefusedException::NO_CATEGORY
			);
		}

		if ($route === self::TRANSFER) {
			return $this->write(dossier: $this->proposeTransfer(dossier: $dossier), uuid: $dossierId);
		}

		return $this->write(dossier: $this->proposeDestruction(dossier: $dossier), uuid: $dossierId);
	}//end propose()

	/**
	 * Read what OpenRegister did with the dossier's list: a completed transfer
	 * makes it transferred, an executed destruction list destroyed. Anything
	 * else leaves it as it is.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array<string, mixed> The id, lifecycle and listStatus
	 *
	 * @throws MissingObjectException When the dossier does not exist
	 * @throws AccessDeniedException  When the caller is not an archivist or administrator
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-005-destruction-via-openregister-destruction-lists
	 */
	public function reflectOutcome(string $dossierId): array {
		$this->guard->requireArchivist();
		$dossier = $this->dossier(dossierId: $dossierId);
		$lifecycle = (string)($dossier['lifecycle'] ?? '');

		[$status, $outcome] = $this->listOutcome(dossier: $dossier);
		if ($outcome !== null && $lifecycle === 'closed') {
			$dossier['lifecycle'] = $outcome;
			$lifecycle = (string)$this->write(dossier: $dossier, uuid: $dossierId)['lifecycle'];
		}

		return ['id' => $dossierId, 'lifecycle' => $lifecycle, 'listStatus' => $status];
	}//end reflectOutcome()

	/**
	 * The status of the dossier's list in OpenRegister and the stage it means.
	 *
	 * @param array<string, mixed> $dossier The dossier
	 *
	 * @return array{0: string|null, 1: string|null} [status, transferred|destroyed|null]
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-005-destruction-via-openregister-destruction-lists
	 */
	private function listOutcome(array $dossier): array {
		if (is_string($dossier['transferList'] ?? null) === true) {
			$status = $this->archive->transferListStatus(uuid: $dossier['transferList']);
			return [$status, $this->stageFor(status: $status, done: 'completed', stage: 'transferred')];
		}

		if (is_string($dossier['destructionList'] ?? null) === true) {
			$list = $this->archive->destructionList(uuid: $dossier['destructionList']);
			$status = null;
			if ($list !== null) {
				$status = (string)($list['status'] ?? '');
			}

			return [$status, $this->stageFor(status: $status, done: 'executed', stage: 'destroyed')];
		}

		return [null, null];
	}//end listOutcome()

	/**
	 * The stage a list status means: the given stage once OpenRegister is done.
	 *
	 * @param string|null $status The list's status in OpenRegister
	 * @param string      $done   The status that means carried out
	 * @param string      $stage  The dossier stage it leads to
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-005-destruction-via-openregister-destruction-lists
	 */
	private function stageFor(?string $status, string $done, string $stage): ?string {
		if ($status === $done) {
			return $stage;
		}

		return null;
	}//end stageFor()

	/**
	 * The dossier with an OpenRegister transfer list over its records.
	 *
	 * @param array<string, mixed> $dossier The dossier
	 *
	 * @return array<string, mixed>
	 *
	 * @throws DossierRefusedException When OpenRegister has no transport or no records were found
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	private function proposeTransfer(array $dossier): array {
		$list = null;
		if ($this->archive->transferAvailable() === true) {
			$list = $this->archive->createTransferList(objects: $this->memberEntities(dossier: $dossier));
		}

		if ($list === null || is_string($list['uuid'] ?? null) === false) {
			throw new DossierRefusedException(
				message: $this->l10n->t('Automated transfer is unavailable: OpenRegister has no e-depot connection. Set one up in the OpenRegister settings.'),
				reason: DossierRefusedException::TRANSFER_UNAVAILABLE
			);
		}

		$dossier['disposition'] = self::TRANSFER;
		$dossier['transferList'] = $list['uuid'];
		return $dossier;
	}//end proposeTransfer()

	/**
	 * The dossier with an OpenRegister destruction list over its records.
	 *
	 * @param array<string, mixed> $dossier The dossier
	 *
	 * @return array<string, mixed>
	 *
	 * @throws DossierRefusedException When OpenRegister has no destruction register or found nothing eligible
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-005-destruction-via-openregister-destruction-lists
	 */
	private function proposeDestruction(array $dossier): array {
		$created = $this->archive->createDestructionList(uuids: $this->memberUuids(dossier: $dossier));
		if ($created === null) {
			throw new DossierRefusedException(
				message: $this->l10n->t('OpenRegister has no register for destruction lists. Set one up in the OpenRegister settings.'),
				reason: DossierRefusedException::DESTRUCTION_UNAVAILABLE
			);
		}

		$refused = $created['refused'];
		if ($created['list'] === null || is_string($created['list']['uuid'] ?? null) === false) {
			throw new DossierRefusedException(
				message: $this->l10n->t('None of the dossier\'s records may be destroyed now.'),
				reason: DossierRefusedException::NOTHING_ELIGIBLE,
				gaps: array_map(static fn (array $row): string => $row['uuid'] . ': ' . $row['reason'], $refused)
			);
		}

		$dossier['disposition'] = self::DESTRUCTION;
		$dossier['destructionList'] = $created['list']['uuid'];
		$dossier['dispositionRefused'] = $refused;
		return $dossier;
	}//end proposeDestruction()

	/**
	 * The route a category means, or null when there is none.
	 *
	 * @param array{action: string, category: string, description: string, retentionYears: int|null}|null $category The category
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-003-retention-via-openregister-selectielijst-and-retentionservice
	 */
	private function route(?array $category): ?string {
		return match ($category['action'] ?? null) {
			SelectionCategoryReader::KEEP => self::TRANSFER,
			SelectionCategoryReader::DESTROY => self::DESTRUCTION,
			default => null,
		};
	}//end route()

	/**
	 * The dossier's record uuids, in kind order.
	 *
	 * @param array<string, mixed> $dossier The dossier
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	private function memberUuids(array $dossier): array {
		$uuids = [];
		foreach (array_keys(self::MEMBER_SCHEMAS) as $kind) {
			foreach ((array)($dossier[$kind] ?? []) as $uuid) {
				$uuids[] = (string)$uuid;
			}
		}

		return $uuids;
	}//end memberUuids()

	/**
	 * The dossier's records as OpenRegister entities, the shape its transfer
	 * list takes. A record that no longer exists is left out.
	 *
	 * @param array<string, mixed> $dossier The dossier
	 *
	 * @return list<object>
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	private function memberEntities(array $dossier): array {
		$entities = [];
		foreach (self::MEMBER_SCHEMAS as $kind => $schema) {
			foreach ((array)($dossier[$kind] ?? []) as $uuid) {
				$entity = $this->objectService->find(id: (string)$uuid, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
				if ($entity !== null) {
					$entities[] = $entity;
				}
			}
		}

		return $entities;
	}//end memberEntities()

	/**
	 * The dossier, read in system context.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array<string, mixed>
	 *
	 * @throws MissingObjectException When it does not exist
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	private function dossier(string $dossierId): array {
		$entity = null;
		if ($dossierId !== '') {
			$entity = $this->objectService->find(id: $dossierId, register: 'decidiq', schema: self::SCHEMA, _rbac: false, _multitenancy: false);
		}

		if ($entity === null) {
			throw new MissingObjectException(message: 'Dossier not found.');
		}

		$dossier = $entity->jsonSerialize();
		unset($dossier['id'], $dossier['@self']);
		return $dossier;
	}//end dossier()

	/**
	 * Write the dossier in system context.
	 *
	 * @param array<string, mixed> $dossier The complete dossier
	 * @param string               $uuid    Its uuid
	 *
	 * @return array<string, mixed> The stored dossier, with its id
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
	 */
	private function write(array $dossier, string $uuid): array {
		$saved = $this->objectService->saveObject(
			object: $dossier,
			register: 'decidiq',
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		$stored = $saved->jsonSerialize();
		unset($stored['@self']);
		return ['id' => (string)$saved->getUuid()] + $stored;
	}//end write()
}//end class
