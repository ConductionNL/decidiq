<?php

/**
 * Decidiq Board Book Delivery
 *
 * Puts a compiled meeting pack into each active attendee's own Files as a
 * read-only share that cannot be shared on, so the desktop and mobile clients
 * sync it for offline reading. One attendee who cannot be reached never stops
 * the others; each failure is reported by name.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\BoardBook
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-006-offline-delivery-into-attendees-own-files
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Decidiq\Service\BoardBook;

use OCP\Constants;
use OCP\Files\Node;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shares a pack file read-only with the meeting's active attendees.
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-006-offline-delivery-into-attendees-own-files
 */
class BoardBookDelivery {

	/**
	 * Constructor.
	 *
	 * @param IShareManager   $shares The share manager.
	 * @param IUserManager    $users  Whether an attendee has an account.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IShareManager $shares,
		private readonly IUserManager $users,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Share the pack with every active attendee except the one who compiled it.
	 *
	 * An attendee is active while `leftAt` is empty; their account is
	 * `nextcloudUserId`. An attendee without an account, or whose share
	 * fails, is reported and skipped.
	 *
	 * @param Node                             $file         The pack file.
	 * @param array<int, array<string, mixed>> $participants The meeting's participants.
	 * @param string                           $sharedBy     Who compiled the pack.
	 *
	 * @return array<int, string> The deliveries that failed, one line per attendee.
	 *
	 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-006-offline-delivery-into-attendees-own-files
	 */
	public function deliver(Node $file, array $participants, string $sharedBy): array {
		$failures = [];
		$done = [$sharedBy => true];
		foreach ($participants as $participant) {
			if (trim((string)($participant['leftAt'] ?? '')) !== '') {
				continue;
			}

			$uid = trim((string)($participant['nextcloudUserId'] ?? ''));
			$name = $this->nameOf(participant: $participant, uid: $uid);
			if ($uid === '' || $this->users->userExists($uid) === false) {
				$failures[] = $name . ' (no Nextcloud account)';
				continue;
			}

			if (isset($done[$uid]) === true) {
				continue;
			}

			$done[$uid] = true;
			$failure = $this->share(file: $file, uid: $uid, sharedBy: $sharedBy);
			if ($failure !== null) {
				$failures[] = $name . ' (' . $failure . ')';
			}
		}//end foreach

		return $failures;
	}//end deliver()

	/**
	 * Create one read-only user share; the reason it failed, or null.
	 *
	 * @param Node   $file     The pack file.
	 * @param string $uid      The attendee's account.
	 * @param string $sharedBy Who compiled the pack.
	 *
	 * @return string|null
	 */
	private function share(Node $file, string $uid, string $sharedBy): ?string {
		try {
			$share = $this->shares->newShare();
			$share->setNode($file);
			$share->setShareType(IShare::TYPE_USER);
			$share->setSharedWith($uid);
			$share->setSharedBy($sharedBy);
			// Read only: without the share bit it cannot be shared on.
			$share->setPermissions(Constants::PERMISSION_READ);
			$this->shares->createShare($share);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: meeting pack delivery failed', ['uid' => $uid, 'exception' => $e->getMessage()]);
			return 'share failed';
		}

		return null;
	}//end share()

	/**
	 * How an attendee is named in a failure line.
	 *
	 * @param array<string, mixed> $participant The participant.
	 * @param string               $uid         Their account, possibly empty.
	 *
	 * @return string
	 */
	private function nameOf(array $participant, string $uid): string {
		$name = trim((string)($participant['displayName'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		if ($uid !== '') {
			return $uid;
		}

		return 'attendee';
	}//end nameOf()
}//end class
