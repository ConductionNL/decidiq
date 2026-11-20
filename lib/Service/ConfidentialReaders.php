<?php

/**
 * Named portal readers of a confidential agenda item
 *
 * The griffie adds a person without a Nextcloud account as a reader of a
 * non-public agenda item by that person's BSN or KvK number. Portaliq
 * provisions a pending portal account for the identity and writes the claim
 * `decidiq.personId` on it; decidiq keeps only the returned portal reference
 * and never the number (portal-voting-record-and-confidential-papers, REQ-PSH-002).
 *
 * @category  Service
 * @package   OCA\Decidiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\ConfidentialReaderRefusedException;
use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adds and removes the named portal readers of a non-public agenda item.
 *
 * Only the secretariat, an administrator, or the chair or secretary of the
 * item's meeting may change the readers. Every refusal changes nothing.
 *
 * @category Service
 * @package  OCA\Decidiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- one collaborator per step of the flow.
 */
class ConfidentialReaders {

	/**
	 * The register every object lives in.
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The groups that act as the secretariat.
	 *
	 * @var list<string>
	 */
	private const SECRETARIAT_GROUPS = ['decidiq-secretariat', 'decidiq-administrators', 'decidesk-administrators'];

	/**
	 * The meeting roles that may change an item's readers.
	 *
	 * @var list<string>
	 */
	private const MEETING_ROLES = ['chair', 'secretary'];

	/**
	 * The claim portaliq writes on the reader's account, read as `decidiq.personId`.
	 */
	public const CLAIM = 'personId';

	/**
	 * The portal audience a named reader belongs to.
	 */
	private const AUDIENCE = 'citizen';

	/**
	 * The portal sign-in that reaches each kind of number.
	 *
	 * @var array<string, string>
	 */
	private const PORTAL_IDENTITY = ['bsn' => 'digid', 'kvk' => 'eherkenning'];

	/**
	 * Portaliq's provisioning event, by name so decidiq installs without portaliq.
	 */
	private const PROVISION_EVENT = '\\OCA\\Portaliq\\Event\\PortalAccountProvisionRequestedEvent';

	/**
	 * Portaliq's claim event, by name so decidiq installs without portaliq.
	 */
	private const CLAIM_EVENT = '\\OCA\\Portaliq\\Event\\PortalAccountClaimRequestedEvent';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService The item and the person.
	 * @param IUserSession                 $userSession   The caller.
	 * @param IGroupManager                $groupManager  The secretariat groups.
	 * @param ParticipantResolverInterface $participants  The meeting's chair and secretary.
	 * @param IEventDispatcher             $dispatcher    Asks portaliq for the account and the claim.
	 * @param LoggerInterface              $logger        Records a failed write, never the number.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ParticipantResolverInterface $participants,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add a person as a portal reader of a non-public item.
	 *
	 * @param string $itemId       The agenda item id, uuid or slug.
	 * @param string $personId     The person id, uuid or slug.
	 * @param string $identityType `bsn` or `kvk`.
	 * @param string $number       The BSN or KvK number; never stored.
	 * @param string $organisation The portal tenant, or ''.
	 *
	 * @return array{authorisedPersons: list<string>, person: string} The item's readers after the change.
	 *
	 * @throws ConfidentialReaderRefusedException 401, 403, 404, 409 or 422 with the reason.
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
	 */
	public function add(string $itemId, string $personId, string $identityType, string $number, string $organisation=''): array {
		$item = $this->authorisedItem(itemId: $itemId);
		if (($item['public'] ?? true) !== false) {
			throw new ConfidentialReaderRefusedException(message: 'Make the item confidential first.', status: 409);
		}

		$number = preg_replace('/\s+/', '', $number) ?? '';
		self::assertNumber(identityType: $identityType, number: $number);
		$person = $this->row(schema: 'person', id: $personId);
		if ($person === null) {
			throw new ConfidentialReaderRefusedException(message: 'This person was not found.', status: 404);
		}

		$personKey = self::idOf(row: $person);
		$subjectRef = $this->provision(identityType: $identityType, number: $number, person: $person, organisation: $organisation);
		$this->claim(subjectRef: $subjectRef, personId: $personKey);

		$readers = self::readersOf(item: $item);
		if (in_array($personKey, $readers, true) === false) {
			$readers[] = $personKey;
		}

		$person['portalAccountRef'] = $subjectRef;
		$item['authorisedPersons'] = $readers;
		$this->save(schema: 'person', row: $person);
		$this->save(schema: 'agenda-item', row: $item);

		return ['authorisedPersons' => $readers, 'person' => $personKey];
	}//end add()

	/**
	 * Remove a person from an item's portal readers.
	 *
	 * @param string $itemId   The agenda item id, uuid or slug.
	 * @param string $personId The person id.
	 *
	 * @return array{authorisedPersons: list<string>} The item's readers after the change.
	 *
	 * @throws ConfidentialReaderRefusedException 401, 403 or 404 with the reason.
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
	 */
	public function remove(string $itemId, string $personId): array {
		$item = $this->authorisedItem(itemId: $itemId);
		$before = self::readersOf(item: $item);
		$readers = array_values(array_filter($before, static fn (string $reader): bool => $reader !== $personId));
		if ($readers !== $before) {
			$item['authorisedPersons'] = $readers;
			$this->save(schema: 'agenda-item', row: $item);
		}

		return ['authorisedPersons' => $readers];
	}//end remove()

	/**
	 * Whether a BSN passes the elfproef.
	 *
	 * @param string $bsn Nine digits.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
	 */
	public static function validBsn(string $bsn): bool {
		if (preg_match('/^\d{9}$/', $bsn) !== 1 || $bsn === '000000000') {
			return false;
		}

		$sum = 0;
		for ($i = 0; $i < 8; $i++) {
			$sum += ((int)$bsn[$i] * (9 - $i));
		}

		$sum -= (int)$bsn[8];

		return $sum % 11 === 0;
	}//end validBsn()

	/**
	 * Whether a KvK number is well-formed: eight digits.
	 *
	 * @param string $kvk The number.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item
	 */
	public static function validKvk(string $kvk): bool {
		return preg_match('/^\d{8}$/', $kvk) === 1 && $kvk !== '00000000';
	}//end validKvk()

	/**
	 * The item, once the caller may change its readers.
	 *
	 * @param string $itemId The item id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ConfidentialReaderRefusedException 401, 404 or 403.
	 */
	private function authorisedItem(string $itemId): array {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			throw new ConfidentialReaderRefusedException(message: 'Log in to change the readers.', status: 401);
		}

		$item = $this->row(schema: 'agenda-item', id: $itemId);
		if ($item === null) {
			throw new ConfidentialReaderRefusedException(message: 'This agenda item was not found.', status: 404);
		}

		if ($this->mayChangeReaders(uid: $uid, item: $item) === false) {
			throw new ConfidentialReaderRefusedException(
				message: 'Only the secretariat, or the chair or secretary of the meeting, can change the readers.',
				status: 403
			);
		}

		return $item;
	}//end authorisedItem()

	/**
	 * Whether a user is in the secretariat or chairs or minutes the item's meeting.
	 *
	 * @param string               $uid  The user.
	 * @param array<string, mixed> $item The agenda item.
	 *
	 * @return bool
	 */
	private function mayChangeReaders(string $uid, array $item): bool {
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		foreach (self::SECRETARIAT_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		$meeting = (string)($item['meeting'] ?? '');

		return $meeting !== '' && $this->participants->hasRole(meetingId: $meeting, nextcloudUid: $uid, roles: self::MEETING_ROLES) === true;
	}//end mayChangeReaders()

	/**
	 * Refuse a number that is not a valid BSN or KvK number.
	 *
	 * @param string $identityType `bsn` or `kvk`.
	 * @param string $number       The number, without spaces.
	 *
	 * @return void
	 *
	 * @throws ConfidentialReaderRefusedException 422.
	 */
	private static function assertNumber(string $identityType, string $number): void {
		if ($identityType === 'bsn' && self::validBsn(bsn: $number) === false) {
			throw new ConfidentialReaderRefusedException(message: 'This is not a valid BSN.', status: 422);
		}

		if ($identityType === 'kvk' && self::validKvk(kvk: $number) === false) {
			throw new ConfidentialReaderRefusedException(message: 'This is not a valid KvK number.', status: 422);
		}

		if (in_array($identityType, ['bsn', 'kvk'], true) === false) {
			throw new ConfidentialReaderRefusedException(message: 'Give a BSN or a KvK number.', status: 422);
		}
	}//end assertNumber()

	/**
	 * Ask portaliq for a pending account for the identity; its subject reference.
	 *
	 * @param string               $identityType `bsn` or `kvk`.
	 * @param string               $number       The number.
	 * @param array<string, mixed> $person       The person.
	 * @param string               $organisation The portal tenant.
	 *
	 * @return string The portal reference.
	 *
	 * @throws ConfidentialReaderRefusedException 409 without portaliq or its answer.
	 */
	private function provision(string $identityType, string $number, array $person, string $organisation): string {
		$class = self::PROVISION_EVENT;
		if (class_exists($class) === false) {
			throw new ConfidentialReaderRefusedException(message: 'Install portaliq first: it keeps the readers\' portal accounts.', status: 409);
		}

		$event = new $class(
			appId: AppIdentity::APP_ID,
			audience: self::AUDIENCE,
			organisation: $organisation,
			identityType: self::PORTAL_IDENTITY[$identityType],
			identityRef: $number,
			displayName: (string)($person['name'] ?? '')
		);
		$this->dispatcher->dispatchTyped($event);

		$subjectRef = (string)$event->getSubjectRef();
		if ($subjectRef === '') {
			throw new ConfidentialReaderRefusedException(message: 'Portaliq did not provision a portal account for this reader.', status: 409);
		}

		return $subjectRef;
	}//end provision()

	/**
	 * Ask portaliq to write `decidiq.personId` on the account.
	 *
	 * @param string $subjectRef The account.
	 * @param string $personId   The person id.
	 *
	 * @return void
	 *
	 * @throws ConfidentialReaderRefusedException 409 when portaliq does not confirm it.
	 */
	private function claim(string $subjectRef, string $personId): void {
		$class = self::CLAIM_EVENT;
		if (class_exists($class) === false) {
			throw new ConfidentialReaderRefusedException(message: 'Install portaliq first: it keeps the readers\' portal accounts.', status: 409);
		}

		$event = new $class(appId: AppIdentity::APP_ID, subjectRef: $subjectRef, claimName: self::CLAIM, value: $personId);
		$this->dispatcher->dispatchTyped($event);
		if ($event->getResult() !== 'ok') {
			throw new ConfidentialReaderRefusedException(message: 'Portaliq did not confirm the reader\'s portal account.', status: 409);
		}
	}//end claim()

	/**
	 * The item's named readers.
	 *
	 * @param array<string, mixed> $item The agenda item.
	 *
	 * @return list<string>
	 */
	private static function readersOf(array $item): array {
		$readers = [];
		foreach ((array)($item['authorisedPersons'] ?? []) as $reader) {
			if (is_string($reader) === true && $reader !== '') {
				$readers[] = $reader;
			}
		}

		return $readers;
	}//end readersOf()

	/**
	 * One row of a schema by id, uuid or slug.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The id, uuid or slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function row(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$found = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema]],
			_rbac: false,
			_multitenancy: false
		);
		foreach (($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true && in_array($id, self::idsOf(row: $row), true) === true) {
				return $row;
			}
		}

		return null;
	}//end row()

	/**
	 * Save a row back under its own uuid.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return void
	 *
	 * @throws ConfidentialReaderRefusedException 500 when the write fails.
	 */
	private function save(string $schema, array $row): void {
		try {
			$this->objectService->saveObject(
				object: $row,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER, schema: $schema),
					uuid: self::idOf(row: $row)
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('Decidiq: a confidential reader change could not be saved', ['schema' => $schema, 'exception' => $e->getMessage()]);
			throw new ConfidentialReaderRefusedException(message: 'The readers could not be saved.', status: 500);
		}
	}//end save()

	/**
	 * Every name a row answers to: id, uuid and slug.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return list<string>
	 */
	private static function idsOf(array $row): array {
		$ids = [];
		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($row['@self']['id'] ?? null), ($row['slug'] ?? null)] as $value) {
			if (is_string($value) === true && $value !== '') {
				$ids[] = $value;
			}
		}

		return $ids;
	}//end idsOf()

	/**
	 * The row's own id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (self::idsOf(row: $row)[0] ?? '');
	}//end idOf()
}//end class
