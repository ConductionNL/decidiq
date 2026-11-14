<?php

/**
 * Decidiq Annotation Visibility Service
 *
 * Decides who sees an annotation and who may change it. An annotation is
 * private to its author unless shared with the author's faction or with the
 * body the meeting belongs to. OpenRegister's read rules cannot say this,
 * because the faction and the body depend on each object, so every annotation
 * endpoint asks this service before it answers.
 *
 * Fails closed: a faction or a body that cannot be resolved admits nobody but
 * the author.
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
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Exception\AccessDeniedException;
use OCA\Decidiq\Exception\AnnotationRefusedException;
use OCA\Decidiq\Kernel\Contracts\ParticipantResolverInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Who sees and who changes an annotation.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
 */
class AnnotationVisibilityService {
	/**
	 * The visibility values.
	 */
	public const VISIBILITIES = ['private', 'faction', 'body'];

	/**
	 * Factions already resolved in this request: user id => faction ids.
	 *
	 * @var array<string, list<string>>
	 */
	private array $factions = [];

	/**
	 * Body membership already resolved in this request: "meeting|user" => bool.
	 *
	 * @var array<string, bool>
	 */
	private array $participation = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService OpenRegister object service
	 * @param ParticipantResolverInterface $participants  Meeting participants
	 * @param ITimeFactory                 $timeFactory   Clock, for ended memberships
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ParticipantResolverInterface $participants,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Whether a user may see an annotation.
	 *
	 * The author always; a private annotation nobody else; a faction one a
	 * member of a faction the author sits in; a body one a participant of the
	 * annotation's meeting. An unknown visibility admits only the author.
	 *
	 * @param array<string, mixed> $annotation The annotation
	 * @param string               $userId     The user asking
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function canRead(array $annotation, string $userId): bool {
		$author = (string)($annotation['author'] ?? '');
		if ($userId === '' || $author === '') {
			return false;
		}

		if ($author === $userId) {
			return true;
		}

		$visibility = (string)($annotation['visibility'] ?? 'private');
		if ($visibility === 'faction') {
			return array_intersect($this->factionsOf(userId: $author), $this->factionsOf(userId: $userId)) !== [];
		}

		if ($visibility === 'body') {
			$meeting = (string)($annotation['meeting'] ?? '');
			return $meeting !== '' && $this->participates(meetingId: $meeting, userId: $userId);
		}

		return false;
	}//end canRead()

	/**
	 * Refuse anyone but the author.
	 *
	 * @param array<string, mixed> $annotation The annotation
	 * @param string               $userId     The user asking
	 *
	 * @return void
	 *
	 * @throws AccessDeniedException When the user is not the author.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function assertAuthor(array $annotation, string $userId): void {
		$author = (string)($annotation['author'] ?? '');
		if ($userId === '' || $author !== $userId) {
			throw new AccessDeniedException(message: 'Only the author can change this annotation.');
		}
	}//end assertAuthor()

	/**
	 * Refuse a visibility the author cannot use.
	 *
	 * A faction share needs a faction to share with; a body share needs the
	 * meeting whose body sees it.
	 *
	 * @param string $visibility The visibility asked for
	 * @param string $userId     The author
	 * @param string $meetingId  The annotation's meeting, empty when none
	 *
	 * @return void
	 *
	 * @throws AnnotationRefusedException When the visibility cannot be used.
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function assertMayShare(string $visibility, string $userId, string $meetingId): void {
		if (in_array(needle: $visibility, haystack: self::VISIBILITIES, strict: true) === false) {
			throw new AnnotationRefusedException(message: 'Visibility must be private, faction or body.');
		}

		if ($visibility === 'faction' && $this->hasFaction(userId: $userId) === false) {
			throw new AnnotationRefusedException(message: 'You are not a member of a faction to share with.');
		}

		if ($visibility === 'body' && $meetingId === '') {
			throw new AnnotationRefusedException(message: 'Sharing with the body needs the meeting the document belongs to.');
		}
	}//end assertMayShare()

	/**
	 * Whether a user sits in at least one faction.
	 *
	 * @param string $userId The user
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function hasFaction(string $userId): bool {
		return $this->factionsOf(userId: $userId) !== [];
	}//end hasFaction()

	/**
	 * The factions a user sits in now.
	 *
	 * User to person by the person's Nextcloud user id, person to memberships,
	 * and of those the ones naming a faction that have not ended.
	 *
	 * @param string $userId The user
	 *
	 * @return list<string> Faction (governance body) ids
	 *
	 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-002-visibility-is-private-by-default-and-enforced-server-side
	 */
	public function factionsOf(string $userId): array {
		if ($userId === '') {
			return [];
		}

		if (array_key_exists($userId, $this->factions) === true) {
			return $this->factions[$userId];
		}

		$today = $this->timeFactory->getDateTime()->format('Y-m-d');
		$factions = [];
		foreach ($this->rows(schema: 'person', filters: ['nextcloudUserId' => $userId]) as $person) {
			$personId = (string)($person['id'] ?? '');
			if ($personId === '') {
				continue;
			}

			foreach ($this->rows(schema: 'membership', filters: ['person' => $personId]) as $membership) {
				$faction = (string)($membership['faction'] ?? '');
				$end = substr((string)($membership['endDate'] ?? ''), 0, 10);
				if ($faction === '' || ($end !== '' && $end < $today)) {
					continue;
				}

				$factions[$faction] = true;
			}
		}

		$this->factions[$userId] = array_keys($factions);

		return $this->factions[$userId];
	}//end factionsOf()

	/**
	 * Whether a user is a participant of a meeting's body.
	 *
	 * @param string $meetingId The meeting
	 * @param string $userId    The user
	 *
	 * @return bool
	 */
	private function participates(string $meetingId, string $userId): bool {
		$key = $meetingId . '|' . $userId;
		if (array_key_exists($key, $this->participation) === false) {
			$this->participation[$key] = $this->participants->isParticipant(meetingId: $meetingId, nextcloudUid: $userId);
		}

		return $this->participation[$key];
	}//end participates()

	/**
	 * Read objects in system context; the caller decides what to show.
	 *
	 * @param string               $schema  The schema slug
	 * @param array<string, mixed> $filters Property filters
	 *
	 * @return list<array<string, mixed>> Objects with their id
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objectService->findAll(
			config: ['filters' => (['register' => 'decidiq', 'schema' => $schema] + $filters), 'limit' => 500],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach (($found['results'] ?? $found) as $row) {
			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$rows[] = ['id' => (string)$row->getUuid()] + (array)$row->getObject();
			}
		}

		return $rows;
	}//end rows()
}//end class
