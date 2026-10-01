<?php

/**
 * Decidiq Archival Dossier Service
 *
 * Forms the archival dossier of a meeting, gathers its records again while it
 * is forming, and closes it. Closing checks completeness first and freezes
 * the member list: a closed dossier is never gathered again.
 *
 * This is the one thing OpenRegister lacks, the aggregate. Everything after a
 * dossier is closed, retention, transfer, destruction and the certificate, is
 * OpenRegister's (design.md "What OpenRegister already provides").
 *
 * Dossier writes are service-owned: the schema grants signed-in members read
 * only, and this service checks authority per meeting (its chair or secretary,
 * or an administrator) before it writes in system context. So the object API
 * cannot reopen or edit a closed dossier around the checks here.
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
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\DossierRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\Decidiq\Service\Records\OpenRegisterArchive;
use OCA\Decidiq\Service\Records\SecurityClassification;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Form, gather and close a meeting's archival dossier.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
 */
class ArchivalDossierService {
	/**
	 * The dossier schema.
	 */
	private const SCHEMA = 'archival-dossier';

	/**
	 * The only stage in which a dossier's records may change.
	 */
	private const FORMING = 'forming';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object facade
	 * @param DossierMemberCollector $members       Gathers the meeting's records
	 * @param ParticipantResolver    $participants  The meeting's roles
	 * @param IUserSession           $userSession   The signed-in account
	 * @param IGroupManager          $groupManager  Administrator check
	 * @param IL10N                  $l10n          Translations
	 * @param OpenRegisterArchive    $archive       The register's TMLO switch
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly DossierMemberCollector $members,
		private readonly ParticipantResolver $participants,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l10n,
		private readonly OpenRegisterArchive $archive,
	) {
	}//end __construct()

	/**
	 * Form the dossier of a meeting, or answer the one it already has.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return array<string, mixed> The dossier, with its id
	 *
	 * @throws MissingObjectException When the meeting does not exist
	 * @throws AccessDeniedException  When the caller is not the meeting's chair, secretary or an administrator
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function formForMeeting(string $meetingId): array {
		$meeting = $this->read(schema: 'meeting', id: $meetingId);
		if ($meeting === null) {
			throw new MissingObjectException(message: 'Meeting not found.');
		}

		$this->requireAuthority(meetingId: $meetingId);

		$existing = $this->objectService->findAll(
			config: ['filters' => ['register' => 'decidiq', 'schema' => self::SCHEMA, 'meeting' => $meetingId], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		if ($existing !== []) {
			return ['id' => (string)$existing[0]->getUuid()] + $existing[0]->jsonSerialize();
		}

		$dossier = [
			'title' => (string)($meeting['title'] ?? $meetingId),
			'meeting' => $meetingId,
			'lifecycle' => self::FORMING,
			'securityClassification' => (string)($meeting['securityClassification'] ?? 'openbaar'),
		];
		$body = ($meeting['governanceBody'] ?? null);
		if (is_string($body) === true && $body !== '') {
			$dossier['governanceBody'] = $body;
		}

		return $this->write(dossier: $this->gathered(dossier: $dossier, meeting: $meeting), uuid: null);
	}//end formForMeeting()

	/**
	 * Gather a forming dossier's records again.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array<string, mixed> The dossier, with its id
	 *
	 * @throws MissingObjectException  When the dossier or its meeting does not exist
	 * @throws AccessDeniedException   When the caller lacks authority on the meeting
	 * @throws DossierRefusedException When the dossier is no longer forming
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function assemble(string $dossierId): array {
		[$dossier, $meeting] = $this->formingDossier(dossierId: $dossierId);

		return $this->write(dossier: $this->gathered(dossier: $dossier, meeting: $meeting), uuid: $dossierId);
	}//end assemble()

	/**
	 * Close a forming dossier: gather once more, refuse when gaps remain and
	 * no reason is given, then freeze it.
	 *
	 * @param string      $dossierId      The dossier
	 * @param string|null $overrideReason Why it closes despite its gaps
	 *
	 * @return array<string, mixed> The closed dossier, with its id
	 *
	 * @throws MissingObjectException  When the dossier or its meeting does not exist
	 * @throws AccessDeniedException   When the caller lacks authority on the meeting
	 * @throws DossierRefusedException When it is no longer forming, or has gaps and no reason
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	public function close(string $dossierId, ?string $overrideReason): array {
		[$dossier, $meeting] = $this->formingDossier(dossierId: $dossierId);
		$dossier = $this->gathered(dossier: $dossier, meeting: $meeting);

		$reason = trim((string)$overrideReason);
		if ($dossier['gaps'] !== [] && $reason === '') {
			throw new DossierRefusedException(
				message: $this->l10n->t('The dossier cannot be closed yet: %1$s. Give a reason to close it anyway.', [$this->gapList(gaps: $dossier['gaps'])]),
				reason: DossierRefusedException::GAPS,
				gaps: $dossier['gaps']
			);
		}

		$dossier['lifecycle'] = 'closed';
		$dossier['closedAt'] = (new DateTimeImmutable())->format(DATE_ATOM);
		$dossier['closedBy'] = (string)$this->userSession->getUser()?->getUID();
		unset($dossier['closeOverrideReason']);
		if ($dossier['gaps'] !== []) {
			$dossier['closeOverrideReason'] = $reason;
		}

		// A closed dossier is semi-static in TMLO terms. OpenRegister takes
		// @self.tmlo on an update only, and keeps it only in a register with
		// tmloEnabled; its MdtoXmlGenerator derives the rest from the object.
		if ($this->archive->tmloEnabled(register: 'decidiq') === true) {
			$dossier['@self'] = ['tmlo' => ['archiefstatus' => 'semi_statisch']];
		}

		return $this->write(dossier: $dossier, uuid: $dossierId);
	}//end close()

	/**
	 * A dossier the caller may change, with its meeting, while it is forming.
	 *
	 * @param string $dossierId The dossier
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 *
	 * @throws MissingObjectException  When the dossier or its meeting does not exist
	 * @throws AccessDeniedException   When the caller lacks authority on the meeting
	 * @throws DossierRefusedException When the dossier is no longer forming
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	private function formingDossier(string $dossierId): array {
		$dossier = $this->read(schema: self::SCHEMA, id: $dossierId);
		if ($dossier === null) {
			throw new MissingObjectException(message: 'Dossier not found.');
		}

		$meetingId = (string)($dossier['meeting'] ?? '');
		$this->requireAuthority(meetingId: $meetingId);

		$state = (string)($dossier['lifecycle'] ?? self::FORMING);
		if ($state !== self::FORMING) {
			throw new DossierRefusedException(
				message: $this->l10n->t('The dossier is %1$s: its records are frozen.', [$state]),
				reason: DossierRefusedException::FROZEN
			);
		}

		$meeting = $this->read(schema: 'meeting', id: $meetingId);
		if ($meeting === null) {
			throw new MissingObjectException(message: 'Meeting not found.');
		}

		unset($dossier['id'], $dossier['@self']);
		return [$dossier, $meeting];
	}//end formingDossier()

	/**
	 * The dossier with its records gathered now.
	 *
	 * @param array<string, mixed> $dossier The dossier
	 * @param array<string, mixed> $meeting Its meeting
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	private function gathered(array $dossier, array $meeting): array {
		$gathered = $this->members->collect(meetingId: (string)$dossier['meeting'], meeting: $meeting);
		$gathered['assembledAt'] = (new DateTimeImmutable())->format(DATE_ATOM);

		// The dossier must be at least as restrictive as its most restrictive
		// record; when it is not, the warning names that record (REQ-RMA-008).
		$restrictive = $gathered['restrictiveMember'];
		unset($gathered['restrictiveMember'], $dossier['classificationWarning']);
		if ($restrictive !== null
			&& SecurityClassification::isMoreRestrictive(label: $restrictive['level'], than: (string)($dossier['securityClassification'] ?? 'openbaar')) === true
		) {
			$gathered['classificationWarning'] = $restrictive;
		}

		return array_merge($dossier, $gathered);
	}//end gathered()

	/**
	 * The gaps as a reader says them.
	 *
	 * @param list<string> $gaps The gap codes
	 *
	 * @return string
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	private function gapList(array $gaps): string {
		$labels = array_map(
			fn (string $gap): string => match ($gap) {
				DossierMemberCollector::GAP_MINUTES_MISSING => $this->l10n->t('the meeting has no minutes'),
				DossierMemberCollector::GAP_MINUTES_NOT_APPROVED => $this->l10n->t('the minutes are not approved'),
				DossierMemberCollector::GAP_MEETING_NOT_CLOSED => $this->l10n->t('the meeting is not closed'),
				default => $gap,
			},
			$gaps
		);

		return implode('; ', $labels);
	}//end gapList()

	/**
	 * Refuse a caller who is neither an administrator nor the meeting's chair
	 * or secretary. Fails closed on a meeting that cannot be resolved.
	 *
	 * @param string $meetingId The meeting
	 *
	 * @return void
	 *
	 * @throws AccessDeniedException When the caller lacks that authority
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	private function requireAuthority(string $meetingId): void {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			throw new AccessDeniedException(message: 'Not signed in.');
		}

		if ($this->groupManager->isAdmin($uid) === true) {
			return;
		}

		if ($meetingId === '' || $this->participants->hasRole(meetingId: $meetingId, nextcloudUid: $uid, roles: ['chair', 'secretary']) === false) {
			throw new AccessDeniedException(message: 'Only the chair or secretary of the meeting keeps its archival dossier.');
		}
	}//end requireAuthority()

	/**
	 * Read one object in system context.
	 *
	 * @param string $schema The schema slug
	 * @param string $id     The uuid
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
		if ($entity === null) {
			return null;
		}

		return $entity->jsonSerialize();
	}//end read()

	/**
	 * Write the dossier in system context.
	 *
	 * @param array<string, mixed> $dossier The complete dossier
	 * @param string|null          $uuid    The uuid when it exists
	 *
	 * @return array<string, mixed> The stored dossier, with its id
	 *
	 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-001-archival-dossier-assembly
	 */
	private function write(array $dossier, ?string $uuid): array {
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
