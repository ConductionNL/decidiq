<?php

/**
 * Decidiq Member Competence Service
 *
 * Saves a body's competences and its members' competences, and confirms a
 * member competence, after CompetenceConfirmationGuard has said yes
 * (bodies-board-composition-skills-and-diversity, design D1 and D2). The
 * schemas keep their write verbs closed on the object API, so this is the
 * only way in: a payload cannot set confirmedBy, and changing the level of
 * a confirmed competence clears its confirmation.
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
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\CompetenceRefusedException;
use OCA\Decidiq\Exception\MissingObjectException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUserSession;

/**
 * Competence writes behind the guard.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 */
class MemberCompetenceService {
	/**
	 * The levels a member competence takes (the schema's enum).
	 */
	public const LEVELS = ['basic', 'experienced', 'expert'];

	/**
	 * The fields a caller writes on a body's competence.
	 */
	private const COMPETENCE_FIELDS = ['name', 'description', 'requiredHolders', 'order', 'active'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface      $objectService OpenRegister's object facade
	 * @param CompetenceConfirmationGuard $guard         The write rules
	 * @param IUserSession                $userSession   The signed-in account
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly CompetenceConfirmationGuard $guard,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Add a competence to a body, or change one. The body of an existing
	 * competence never moves.
	 *
	 * @param array<string, mixed> $data The fields: governanceBody (on create), name, description, requiredHolders, order, active
	 * @param string|null          $id   The competence, null to add one
	 *
	 * @return array<string, mixed> The stored competence, with its id
	 *
	 * @throws MissingObjectException     When the competence or the body does not exist
	 * @throws AccessDeniedException      When the caller is not a signatory of the body
	 * @throws CompetenceRefusedException When the name is empty or fewer than one holder is required
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs
	 */
	public function saveCompetence(array $data, ?string $id): array {
		$competence = [];
		if ($id !== null) {
			$competence = $this->existing(schema: 'board-competence', id: $id);
		}

		$bodyId = (string)($competence['governanceBody'] ?? ($data['governanceBody'] ?? ''));
		$this->existing(schema: 'governance-body', id: $bodyId);
		$this->guard->requireBodyManager(userId: $this->uid(), bodyId: $bodyId);

		$competence['governanceBody'] = $bodyId;
		foreach (self::COMPETENCE_FIELDS as $field) {
			if (array_key_exists($field, $data) === true) {
				$competence[$field] = $data[$field];
			}
		}

		return $this->write(schema: 'board-competence', object: $this->checkedCompetence(competence: $competence), id: $id);
	}//end saveCompetence()

	/**
	 * Record a member competence, or change one. Its membership and
	 * competence never move; changing the level clears a confirmation, and a
	 * payload never sets one.
	 *
	 * @param array<string, mixed> $data The fields: membership and competence (on create), level, note
	 * @param string|null          $id   The member competence, null to record one
	 *
	 * @return array<string, mixed> The stored member competence, with its id
	 *
	 * @throws MissingObjectException     When it, its membership or its competence does not exist
	 * @throws AccessDeniedException      When the caller is neither the member nor a signatory of the body
	 * @throws CompetenceRefusedException When the level is unknown or the competence belongs to another body
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	public function record(array $data, ?string $id): array {
		$held = [];
		if ($id !== null) {
			$held = $this->existing(schema: 'member-competence', id: $id);
		}

		$held += ['membership' => (string)($data['membership'] ?? ''), 'competence' => (string)($data['competence'] ?? '')];
		[$bodyId, $holderUser] = $this->seat(held: $held);
		$this->guard->requireRecorder(userId: $this->uid(), bodyId: $bodyId, holderUser: $holderUser);

		$level = (string)($data['level'] ?? ($held['level'] ?? ''));
		if (in_array($level, self::LEVELS, true) === false) {
			throw new CompetenceRefusedException(message: 'The level is basic, experienced or expert.');
		}

		if (($held['level'] ?? $level) !== $level) {
			unset($held['confirmedBy'], $held['confirmedAt']);
		}

		$held['level'] = $level;
		if (array_key_exists('note', $data) === true) {
			$held['note'] = (string)$data['note'];
		}

		return $this->write(schema: 'member-competence', object: $held, id: $id);
	}//end record()

	/**
	 * Confirm a member competence as the signed-in signatory.
	 *
	 * @param string $id The member competence
	 *
	 * @return array<string, mixed> The stored member competence, with its id
	 *
	 * @throws MissingObjectException When it or its membership does not exist
	 * @throws AccessDeniedException  When the caller is the holder or not a signatory of the body
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	public function confirm(string $id): array {
		$held = $this->existing(schema: 'member-competence', id: $id);
		[$bodyId, $holderUser] = $this->seat(held: $held);
		$uid = $this->uid();
		$this->guard->requireConfirmer(userId: $uid, bodyId: $bodyId, holderUser: $holderUser);

		$held['confirmedBy'] = $uid;
		$held['confirmedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		return $this->write(schema: 'member-competence', object: $held, id: $id);
	}//end confirm()

	/**
	 * The body of a member competence's seat and the Nextcloud account of
	 * its holder, after checking its competence belongs to that body.
	 *
	 * @param array<string, mixed> $held The member competence
	 *
	 * @return array{0: string, 1: string} The body and the holder's account ('' when unknown)
	 *
	 * @throws MissingObjectException     When the membership or the competence does not exist
	 * @throws CompetenceRefusedException When the competence belongs to another body
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	private function seat(array $held): array {
		$membership = $this->existing(schema: 'membership', id: (string)($held['membership'] ?? ''));
		$competence = $this->existing(schema: 'board-competence', id: (string)($held['competence'] ?? ''));
		$bodyId = (string)($membership['governanceBody'] ?? '');
		if ($bodyId === '' || (string)($competence['governanceBody'] ?? '') !== $bodyId) {
			throw new CompetenceRefusedException(message: 'The competence must be one of the same body as the membership.');
		}

		$person = $this->read(schema: 'person', id: (string)($membership['person'] ?? ''));
		return [$bodyId, (string)($person['nextcloudUserId'] ?? '')];
	}//end seat()

	/**
	 * A competence with a name and at least one required holder.
	 *
	 * @param array<string, mixed> $competence The competence to write
	 *
	 * @return array<string, mixed>
	 *
	 * @throws CompetenceRefusedException When it has no name or fewer than one required holder
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs
	 */
	private function checkedCompetence(array $competence): array {
		$competence['name'] = trim((string)($competence['name'] ?? ''));
		if ($competence['name'] === '') {
			throw new CompetenceRefusedException(message: 'A competence needs a name.');
		}

		foreach (['requiredHolders', 'order'] as $field) {
			if (isset($competence[$field]) === true && is_numeric($competence[$field]) === true) {
				$competence[$field] = (int)$competence[$field];
			}
		}

		if (isset($competence['requiredHolders']) === true && (is_int($competence['requiredHolders']) === false || $competence['requiredHolders'] < 1)) {
			throw new CompetenceRefusedException(message: 'A competence needs at least one required holder.');
		}

		if (isset($competence['active']) === true) {
			$competence['active'] = filter_var($competence['active'], FILTER_VALIDATE_BOOL);
		}

		return $competence;
	}//end checkedCompetence()

	/**
	 * The signed-in account.
	 *
	 * @return string
	 *
	 * @throws AccessDeniedException When nobody is signed in
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	private function uid(): string {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			throw new AccessDeniedException(message: 'Not signed in.');
		}

		return $uid;
	}//end uid()

	/**
	 * An object that must exist.
	 *
	 * @param string $schema The schema slug
	 * @param string $id     The uuid
	 *
	 * @return array<string, mixed>
	 *
	 * @throws MissingObjectException When it does not
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	private function existing(string $schema, string $id): array {
		$object = $this->read(schema: $schema, id: $id);
		if ($object === null) {
			throw new MissingObjectException(message: "No {$schema} {$id}.");
		}

		return $object;
	}//end existing()

	/**
	 * An object read in system context, without its metadata.
	 *
	 * @param string $schema The schema slug
	 * @param string $id     The uuid
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$entity = $this->objectService->find(id: $id, register: 'decidiq', schema: $schema, _rbac: false, _multitenancy: false);
		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();
		unset($data['@self'], $data['id']);
		return $data;
	}//end read()

	/**
	 * Write an object in system context.
	 *
	 * @param string               $schema The schema slug
	 * @param array<string, mixed> $object The complete object
	 * @param string|null          $id     The uuid when it exists
	 *
	 * @return array<string, mixed> The stored object, with its id
	 *
	 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
	 */
	private function write(string $schema, array $object, ?string $id): array {
		$saved = $this->objectService->saveObject(
			object: $object,
			register: 'decidiq',
			schema: $schema,
			uuid: $id,
			_rbac: false,
			_multitenancy: false
		);

		$stored = $saved->jsonSerialize();
		unset($stored['@self']);
		return ['id' => (string)$saved->getUuid()] + $stored;
	}//end write()
}//end class
