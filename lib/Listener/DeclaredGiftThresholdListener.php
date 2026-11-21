<?php

/**
 * Declared Gift Threshold Listener
 *
 * Stamps `thresholdStatus` on a declared gift before it is saved: `above` when
 * its estimated value is at or above the gift threshold of its body, `below`
 * otherwise (REQ-INT-006). The threshold is the body's configured
 * `giftThresholdAmount` on its `body-governance-configuration`, or EUR 50 when
 * the body has none. The integrity notification filters on the stamp
 * (REQ-INT-009), because a created-trigger filter can only compare a field for
 * equality and the threshold lives on another object.
 *
 * The stamp is the server's: a value the client sends is replaced. The
 * threshold is registration policy only, so nothing is ever refused here.
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
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-006-gift-threshold-per-gedragscode-drives-badge-and-notification
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\EventDispatcher\IEventListener;
use OCP\EventDispatcher\Event;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps a declared gift against its body's threshold.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-006-gift-threshold-per-gedragscode-drives-badge-and-notification
 */
class DeclaredGiftThresholdListener implements IEventListener {
	/**
	 * The schema this listener stamps.
	 *
	 * @var string
	 */
	public const SCHEMA = 'declared-gift';

	/**
	 * The threshold when the body has no configuration (model code of conduct).
	 *
	 * @var float
	 */
	public const DEFAULT_THRESHOLD = 50.0;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $configurations Reads the body's governance configuration.
	 * @param LoggerInterface        $logger         Logs a configuration that cannot be read.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $configurations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp the gift being created or updated.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-006-gift-threshold-per-gedragscode-drives-badge-and-notification
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->stamp(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->stamp(event: $event, entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Stamp the gift the event saves.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The before-save event.
	 * @param object|null                             $entity The object being saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-006-gift-threshold-per-gedragscode-drives-badge-and-notification
	 */
	private function stamp(ObjectCreatingEvent|ObjectUpdatingEvent $event, ?object $entity): void {
		if ($entity === null) {
			return;
		}

		$gift = array_merge((new EntityPayloadReader())->payloadOf(entity: $entity), $event->getModifiedData());
		if (strtolower((string)($gift['_schemaSlug'] ?? self::SCHEMA)) !== self::SCHEMA) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), ['thresholdStatus' => $this->statusOf(gift: $gift)]));
	}//end stamp()

	/**
	 * The gift's status against its body's threshold, or null without a value.
	 *
	 * @param array<string, mixed> $gift The gift.
	 *
	 * @return string|null `above`, `below`, or null.
	 *
	 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-006-gift-threshold-per-gedragscode-drives-badge-and-notification
	 */
	public function statusOf(array $gift): ?string {
		$value = ($gift['estimatedValue'] ?? null);
		if (is_numeric($value) === false) {
			return null;
		}

		if ((float)$value >= $this->thresholdOf(body: self::bodyOf(gift: $gift))) {
			return 'above';
		}

		return 'below';
	}//end statusOf()

	/**
	 * The gift threshold of a body: its configured amount, or the default.
	 *
	 * A configuration that cannot be read falls back to the default, so a
	 * gift of EUR 50 or more is never kept quiet by a failed lookup.
	 *
	 * @param string|null $body The body's uuid.
	 *
	 * @return float The threshold in EUR.
	 *
	 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-006-gift-threshold-per-gedragscode-drives-badge-and-notification
	 */
	public function thresholdOf(?string $body): float {
		if ($body === null) {
			return self::DEFAULT_THRESHOLD;
		}

		try {
			$found = $this->configurations->findAll(
				config: [
					'filters' => ['register' => 'decidiq', 'schema' => 'body-governance-configuration', 'governanceBody' => $body],
					'limit' => 1,
				],
				_rbac: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: the gift threshold of a body could not be read; the default applies',
				['body' => $body, 'error' => $e->getMessage()]
			);
			return self::DEFAULT_THRESHOLD;
		}

		foreach ($found as $configuration) {
			$amount = (self::rowOf(configuration: $configuration)['giftThresholdAmount'] ?? null);
			if (is_numeric($amount) === true) {
				return (float)$amount;
			}
		}

		return self::DEFAULT_THRESHOLD;
	}//end thresholdOf()

	/**
	 * The gift's body uuid, from a plain or an expanded reference.
	 *
	 * @param array<string, mixed> $gift The gift.
	 *
	 * @return string|null The uuid.
	 */
	private static function bodyOf(array $gift): ?string {
		$body = ($gift['governanceBody'] ?? null);
		if (is_array($body) === true) {
			$body = ($body['id'] ?? ($body['uuid'] ?? null));
		}

		if (is_string($body) === false || trim($body) === '') {
			return null;
		}

		return $body;
	}//end bodyOf()

	/**
	 * A configuration as a row, whether OpenRegister returned an array or an entity.
	 *
	 * @param mixed $configuration The configuration.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function rowOf(mixed $configuration): array {
		if (is_object($configuration) === true) {
			return (new EntityPayloadReader())->payloadOf(entity: $configuration);
		}

		return (array)$configuration;
	}//end rowOf()
}//end class
