<?php

/**
 * Step Threshold Change Listener
 *
 * Hands every decision-stage update to StepThresholdRecomputer with the stage
 * as it was and as it now is, so a changed threshold recomputes its step and
 * is recorded as an action (REQ-DWP-002).
 *
 * Subscribed to `decision-stage` only. The recomputer stops at once when the
 * update did not change the threshold, which is every write the engine makes
 * itself.
 *
 * Fail-soft: a recomputation that cannot be applied is logged and never
 * breaks the save that triggered it.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Service\StepThresholdRecomputer;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recomputes a step when its threshold changes.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
 */
class StepThresholdChangeListener implements IEventListener {
	/**
	 * The schema this listener reads.
	 *
	 * @var string
	 */
	public const SCHEMA = 'decision-stage';

	/**
	 * The actor named when nobody is signed in (a job, a command).
	 *
	 * @var string
	 */
	public const SYSTEM_ACTOR = 'system';

	/**
	 * Constructor.
	 *
	 * @param StepThresholdRecomputer         $recomputer     Applies the change to the step.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema slug.
	 * @param IUserSession                    $userSession    Names who changed the threshold.
	 * @param LoggerInterface                 $logger         Logger.
	 */
	public function __construct(
		private readonly StepThresholdRecomputer $recomputer,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Recompute the step of an updated stage; never break the save.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-002)
	 */
	public function handle(Event $event): void {
		$versions = $this->versionsOf(event: $event);
		if ($versions === null) {
			return;
		}

		try {
			$this->recomputer->stageChanged(before: $versions[0], after: $versions[1], actor: $this->actor());
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: threshold change not applied to its step', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * The stage as it was and as it now is, or null when the event is not a stage update.
	 *
	 * @param Event $event The event.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null The two versions.
	 */
	private function versionsOf(Event $event): ?array {
		if (($event instanceof ObjectUpdatedEvent) === false || $event->getOldObject() === null || $event->getObject() === null) {
			return null;
		}

		$current = $event->getObject();
		$reader  = new EntityPayloadReader();
		$stage   = $reader->payloadOf(entity: $current);
		if ($this->schemaResolver->schemaSlug(entity: $current, row: $stage) !== self::SCHEMA) {
			return null;
		}

		// The payload is the object's own data; its identity lives on the entity.
		$stage['id'] = (string)($stage['id'] ?? $current->getUuid());

		return [$reader->payloadOf(entity: $event->getOldObject()), $stage];
	}//end versionsOf()

	/**
	 * Who is acting.
	 *
	 * @return string The signed-in user's id, or the system actor.
	 */
	private function actor(): string {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return self::SYSTEM_ACTOR;
		}

		return $uid;
	}//end actor()
}//end class
