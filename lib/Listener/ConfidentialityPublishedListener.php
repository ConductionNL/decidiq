<?php

/**
 * Records that a lifted restriction's target has been published.
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
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Decidiq\Service\SystemObjectRows;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * When an agenda item or decision becomes public, every lifted restriction on
 * it that was waiting for that stops waiting: `awaitingPublication` goes to
 * false and `targetPublishedAt` records the moment. The Dashboard's "Lifted,
 * awaiting publication" tile counts the restrictions still waiting.
 *
 * A restriction that cannot be read or saved is left as it is and logged: the
 * flag only feeds a counter, it guards nothing.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
 */
class ConfidentialityPublishedListener implements IEventListener {

	/**
	 * The schema slug of an agenda item.
	 *
	 * @var string
	 */
	public const SCHEMA_AGENDA_ITEM = 'agenda-item';

	/**
	 * The schema slug of a decision.
	 *
	 * @var string
	 */
	public const SCHEMA_DECISION = 'decision';

	/**
	 * The schema slug of a restriction.
	 *
	 * @var string
	 */
	private const SCHEMA_RESTRICTION = 'confidentiality-restriction';

	/**
	 * Per target kind: the field that says it is public, and the restriction field naming it.
	 *
	 * An agenda item carries `confidentiality`, a decision `isPublished`; both
	 * read `public` once published.
	 *
	 * @var array<string, array{classifier: string, target: string}>
	 */
	private const KINDS = [
		'item'     => ['classifier' => 'confidentiality', 'target' => 'targetAgendaItem'],
		'decision' => ['classifier' => 'isPublished', 'target' => 'targetDecision'],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 * @param LoggerInterface        $logger        The logger.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stop the waiting of the lifted restrictions on a target that just became public.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false || $event->getNewObject() === null) {
			return;
		}

		$new  = (array)$event->getNewObject()->getObject();
		$old  = (array)($event->getOldObject()?->getObject() ?? []);
		$kind = $this->justPublished(new: $new, old: $old);
		if ($kind === null) {
			return;
		}

		$targetId = (string)($new['id'] ?? ($new['@self']['id'] ?? ''));
		if ($targetId === '' && method_exists($event->getNewObject(), 'getUuid') === true) {
			$targetId = (string)$event->getNewObject()->getUuid();
		}

		if ($targetId === '') {
			return;
		}

		$this->publishedOn(kind: $kind, targetId: $targetId, now: new DateTimeImmutable());
	}//end handle()

	/**
	 * Which kind of target just became public, or null.
	 *
	 * The two classifiers never sit on one object, so the first that flipped
	 * to `public` names the kind.
	 *
	 * @param array<string, mixed> $new The object after the save.
	 * @param array<string, mixed> $old The object before it.
	 *
	 * @return string|null `item`, `decision` or null.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
	 */
	public function justPublished(array $new, array $old): ?string {
		foreach (self::KINDS as $kind => $fields) {
			$field = $fields['classifier'];
			if (($new[$field] ?? null) === 'public' && ($old[$field] ?? null) !== 'public') {
				return $kind;
			}
		}

		return null;
	}//end justPublished()

	/**
	 * Mark every lifted restriction on the target that still waits as published.
	 *
	 * @param string            $kind     `item` or `decision`.
	 * @param string            $targetId The agenda item or decision.
	 * @param DateTimeImmutable $now      When it was published.
	 *
	 * @return int How many restrictions were marked.
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-006-geheimhoudingenregister-overview-and-awaiting-bekrachtiging-kpi
	 */
	public function publishedOn(string $kind, string $targetId, DateTimeImmutable $now): int {
		$field = self::KINDS[$kind]['target'];
		$rows  = SystemObjectRows::read(
			objectService: $this->objectService,
			logger: $this->logger,
			filters: ['schema' => self::SCHEMA_RESTRICTION, $field => $targetId],
			warning: 'Decidiq: lifted restrictions could not be read; none is marked published'
		);

		$marked = 0;
		foreach ($rows as $restriction) {
			if ((string)($restriction[$field] ?? '') !== $targetId || ($restriction['awaitingPublication'] ?? null) !== true) {
				continue;
			}

			$marked += $this->mark(restriction: $restriction, now: $now);
		}

		return $marked;
	}//end publishedOn()

	/**
	 * Write the publication onto one restriction.
	 *
	 * @param array<string, mixed> $restriction The restriction.
	 * @param DateTimeImmutable    $now         When its target was published.
	 *
	 * @return int 1 when saved, 0 when not.
	 */
	private function mark(array $restriction, DateTimeImmutable $now): int {
		$id = (string)($restriction['id'] ?? ($restriction['@self']['id'] ?? ''));
		unset($restriction['@self']);
		$restriction['awaitingPublication'] = false;
		$restriction['targetPublishedAt']   = $now->format(DateTimeInterface::ATOM);

		try {
			$this->objectService->saveObject(
				object: $restriction,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: 'decidiq', schema: self::SCHEMA_RESTRICTION),
					uuid: $id
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: a lifted restriction could not be marked published',
				['restriction' => $id, 'error' => $e->getMessage()]
			);
			return 0;
		}

		return 1;
	}//end mark()
}//end class
