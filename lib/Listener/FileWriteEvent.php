<?php

/**
 * What a Nextcloud before-write file event touches, and how to stop it.
 *
 * @category Listener
 * @package  OCA\Decidiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCP\EventDispatcher\Event;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\HintException;

/**
 * The nodes a before-write file event changes, and the exception that stops it.
 *
 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
 */
final class FileWriteEvent {

	/**
	 * The before-write events, each a change to the files of a folder.
	 *
	 * @var list<class-string<Event>>
	 */
	public const EVENTS = [
		BeforeNodeCreatedEvent::class,
		BeforeNodeWrittenEvent::class,
		BeforeNodeRenamedEvent::class,
		BeforeNodeDeletedEvent::class,
	];

	/**
	 * The HTTP status a refused write carries: the owner's state conflicts with the write.
	 *
	 * @var int
	 */
	public const HTTP_STATUS = 409;

	/**
	 * The nodes the event changes: both ends of a rename, the one node of a write, create or removal.
	 *
	 * A new folder holds no file yet, so creating one changes nothing: OpenRegister
	 * makes an object's folder when it first needs it, also for a sealed record.
	 *
	 * @param Event $event The file event.
	 *
	 * @return list<Node>
	 *
	 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#requirement-req-gdr-003-version-immutability-once-effective
	 */
	public function nodesOf(Event $event): array {
		if ($event instanceof BeforeNodeRenamedEvent) {
			return [$event->getSource(), $event->getTarget()];
		}

		if ($event instanceof BeforeNodeCreatedEvent && $event->getNode() instanceof Folder) {
			return [];
		}

		if ($event instanceof BeforeNodeWrittenEvent || $event instanceof BeforeNodeCreatedEvent || $event instanceof BeforeNodeDeletedEvent) {
			return [$event->getNode()];
		}

		return [];
	}//end nodesOf()

	/**
	 * The exception that stops this event with the refusal.
	 *
	 * Nextcloud honours an AbortedEventException on a rename or removal; a
	 * create or overwrite is stopped by the exception reaching the caller.
	 *
	 * @param Event  $event   The file event.
	 * @param string $refusal Why it is refused.
	 *
	 * @return AbortedEventException|HintException
	 *
	 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md#scenario-editing-a-sealed-version-refused
	 */
	public function refusal(Event $event, string $refusal): AbortedEventException|HintException {
		if ($event instanceof BeforeNodeRenamedEvent || $event instanceof BeforeNodeDeletedEvent) {
			return new AbortedEventException($refusal);
		}

		return new HintException(message: $refusal, hint: $refusal, code: self::HTTP_STATUS);
	}//end refusal()
}//end class
