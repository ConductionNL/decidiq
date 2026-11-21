<?php

/**
 * Approval Basis Change Listener
 *
 * Hands every object update to ApprovalStalenessService with the object as it
 * was and as it now is, so a grant whose approval basis the update touched is
 * withdrawn and its step reopened (REQ-DWP-003).
 *
 * WHY IT LISTENS TO EVERY SCHEMA. A route's subject can be any object: a
 * decision here, a document or a case in the app that hosts the approval
 * chain. The service reads the subject's stages first and stops there when
 * none declares a basis, so an update of an object without a route costs one
 * read. The route's own rows (stages, actions) are skipped before that read:
 * they are not subjects, and the service writes them.
 *
 * Fail-soft: a withdrawal that cannot be applied is logged and never breaks
 * the save that triggered it.
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
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\Decidiq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Decidiq\Service\ApprovalStalenessService;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Withdraws stale approvals when their subject is updated.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
 */
class ApprovalBasisChangeListener implements IEventListener {
	/**
	 * The route's own schemas: never a subject, and written by the service.
	 *
	 * @var array<int, string>
	 */
	public const ROUTE_SCHEMAS = ['decision-stage', 'approval-action', 'approval-route'];

	/**
	 * Constructor.
	 *
	 * @param ApprovalStalenessService        $staleness      Applies the change to the approvals.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema slug.
	 * @param LoggerInterface                 $logger         Logger.
	 */
	public function __construct(
		private readonly ApprovalStalenessService $staleness,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object update.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$this->apply(event: $event);
		} catch (Throwable $e) {
			$this->logger->warning('Decidiq: stale approvals not withdrawn', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * Read both versions of the object and hand them to the service.
	 *
	 * @param ObjectUpdatedEvent $event The update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-003)
	 */
	private function apply(ObjectUpdatedEvent $event): void {
		$entity = $event->getObject();
		$old = $event->getOldObject();
		if ($entity === null || $old === null) {
			// Without the old version there is nothing to compare against,
			// and guessing would withdraw on a save that changed nothing.
			return;
		}

		$reader = new EntityPayloadReader();
		$after = $reader->payloadOf(entity: $entity);
		if (in_array($this->schemaResolver->schemaSlug(entity: $entity, row: $after), self::ROUTE_SCHEMAS, true) === true) {
			return;
		}

		$subject = (string)($entity->getUuid() ?? ($after['id'] ?? ''));
		$this->staleness->subjectChanged(
			subject: $subject,
			before: $reader->payloadOf(entity: $old),
			after: $after,
		);
	}//end apply()
}//end class
