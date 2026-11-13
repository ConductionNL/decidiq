<?php

/**
 * Fills a decision's deciding body from its meeting.
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
 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pre-save hook for decisions: a decision saved with a meeting and without a
 * `decidingBody` gets the meeting's governance body. A body already on the
 * decision, set by hand or earlier, is never overwritten. The fill goes back
 * through setModifiedData(), which OpenRegister merges before the write.
 * Fails soft: a meeting that cannot be read leaves the decision as entered.
 *
 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
 *
 * @implements IEventListener<Event>
 */
class DecisionDecidingBodyListener implements IEventListener {

	/**
	 * The schema slug of a decision.
	 *
	 * @var string
	 */
	public const SCHEMA = 'decision';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the meeting.
	 * @param LoggerInterface        $logger        Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fill the deciding body of a decision saved with a meeting.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = null;
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		}

		if ($entity === null) {
			return;
		}

		$decision = array_merge((array)$entity->getObject(), $event->getModifiedData());
		if (strtolower((string)($decision['_schemaSlug'] ?? self::SCHEMA)) !== self::SCHEMA) {
			return;
		}

		$body = $this->bodyFor(decision: $decision);
		if ($body !== null) {
			$event->setModifiedData(array_merge($event->getModifiedData(), ['decidingBody' => $body]));
		}
	}//end handle()

	/**
	 * The body to fill in, or null when the decision has one or no meeting names one.
	 *
	 * @param array<string, mixed> $decision The decision as it will be saved.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
	 */
	public function bodyFor(array $decision): ?string {
		if (self::referenceId(value: ($decision['decidingBody'] ?? null)) !== null) {
			return null;
		}

		$meetingId = self::referenceId(value: ($decision['meeting'] ?? null));
		if ($meetingId === null) {
			return null;
		}

		try {
			$meeting = $this->objectService->find(id: $meetingId, register: 'decidiq', schema: 'meeting', _rbac: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: the meeting of a decision could not be read; no deciding body filled',
				['meeting' => $meetingId, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($meeting === null) {
			return null;
		}

		return self::referenceId(value: (((array)$meeting->getObject())['governanceBody'] ?? null));
	}//end bodyFor()

	/**
	 * The id a reference holds: a uuid string or an expanded object.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/insight-decisions-report-by-period-and-body/specs/dashboard/spec.md#requirement-req-drp-001-a-decision-names-the-body-that-took-it
	 */
	public static function referenceId(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end referenceId()
}//end class
