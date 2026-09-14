<?php

/**
 * Decidiq connection report service.
 *
 * Tells integriq's connection registry what only decidiq can see about its
 * outside connections: which eIDAS signing service and which translation
 * adapter the container binds. Integriq owns the rows the Integrations page
 * lists and works out each status itself (hydra change connection-registry,
 * design D4). Decidiq reports, and asks for a fresh resolve after a save.
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
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Support\FleetAppId;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends connection reports and refresh requests to integriq.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
 */
class ConnectionReportService {

	/**
	 * Integriq's report event (ADR-041). Named by string so decidiq stays
	 * installable without integriq: the class is only there when integriq is.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * Integriq's refresh event. Same reason for the string as above.
	 *
	 * @var string
	 */
	public const REFRESH_EVENT = 'OCA\Integriq\Event\ConnectionRefreshRequestedEvent';

	/**
	 * The connections decidiq reports on, as declared `reportedOnly` in
	 * `lib/Settings/connections.json`. A unit test keeps the two equal.
	 *
	 * @var array<int, string>
	 */
	public const REPORTED_KEYS = ['eidas', 'translation'];

	/**
	 * App-config keys per connection whose save asks integriq to resolve that
	 * connection again. Each list holds the connection's `requiredConfig`, plus
	 * any key that changes what the connection sends. A unit test keeps the
	 * required keys in step with `lib/Settings/connections.json`.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const REFRESH_KEYS = [
		'ori' => ['ori_endpoint', 'ori_bearer_secret'],
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container       Resolves the bound services.
	 * @param IEventDispatcher   $eventDispatcher Sends the integriq events (ADR-041).
	 * @param LoggerInterface    $logger          Records what could not be sent.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Report the bound signing service and translation adapter to integriq.
	 *
	 * Never throws. Without integriq nothing is resolved, sent or logged,
	 * because a missing optional app is not a fault.
	 *
	 * @return array<string, string> The status sent, keyed by connection key.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
	 */
	public function reportBindings(): array {
		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return [];
		}

		$observations = [
			'eidas' => $this->observeSigning(),
			'translation' => $this->observeTranslation(),
		];

		$sent = [];
		foreach ($observations as $key => [$status, $message]) {
			$delivered = $this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: Application::APP_ID,
					key: $key,
					status: $status,
					message: $message,
				)
			);
			if ($delivered === true) {
				$sent[$key] = $status;
			}
		}

		return $sent;
	}//end reportBindings()

	/**
	 * Ask integriq to resolve every connection whose settings the save touched.
	 *
	 * A save that names none of a connection's keys leaves that connection
	 * alone. Integriq reads the saved values itself and decides the status
	 * (design D6).
	 *
	 * @param array<string, mixed> $saved The payload the settings save carried.
	 *
	 * @return array<int, string> The connection keys a refresh was sent for.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-001-decidiq-declares-its-outside-connections-in-one-static-file
	 */
	public function refreshFromSave(array $saved): array {
		$eventClass = $this->resolveEventClass(eventClass: self::REFRESH_EVENT);
		if ($eventClass === null) {
			return [];
		}

		$refreshed = [];
		foreach (self::REFRESH_KEYS as $key => $configKeys) {
			if (array_intersect($configKeys, array_keys($saved)) === []) {
				continue;
			}

			$delivered = $this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: Application::APP_ID,
					key: $key,
				)
			);
			if ($delivered === true) {
				$refreshed[] = $key;
			}
		}

		return $refreshed;
	}//end refreshFromSave()

	/**
	 * What decidiq sees bound for eIDAS signing, as a status and a message.
	 *
	 * The delegating service looks its source up through integriq's
	 * `Db\SourceMapper`. When that lookup does not resolve, every signing
	 * request throws, so the report says error rather than configured.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
	 */
	public function observeSigning(): array {
		try {
			$service = $this->container->get(IEIDASSignatureService::class);
		} catch (Throwable $e) {
			return ['error', 'Decidiq could not load the signing service: ' . $e->getMessage()];
		}

		if ($service instanceof LogEIDASSignatureService) {
			return ['simulated', 'A log-only service answers here. Signing requests are logged and nothing is signed.'];
		}

		if (($service instanceof EIDASSignatureService) === false) {
			return ['configured', 'A signing service is bound: ' . get_debug_type($service) . '. Decidiq does not test it.'];
		}

		$sourceMapper = FleetAppId::getService($this->container, 'integriq', 'Db\SourceMapper');
		if ($sourceMapper === null || method_exists($sourceMapper, 'findBySlug') === false) {
			return ['error', 'Integriq offers no source lookup decidiq can use, so every signing request fails.'];
		}

		foreach ([EIDASSignatureService::DOCUDESK_SOURCE_SLUG, EIDASSignatureService::ESIGN_SOURCE_SLUG] as $slug) {
			if ($this->sourceExists(sourceMapper: $sourceMapper, slug: $slug) === true) {
				return ['configured', 'The integriq source ' . $slug . ' exists. Decidiq does not test it.'];
			}
		}

		return [
			'unconfigured',
			'No integriq source named ' . EIDASSignatureService::DOCUDESK_SOURCE_SLUG . ' or '
			. EIDASSignatureService::ESIGN_SOURCE_SLUG . ' exists. Add one to sign minutes.',
		];
	}//end observeSigning()

	/**
	 * What decidiq sees bound for translation, as a status and a message.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
	 */
	public function observeTranslation(): array {
		try {
			$adapter = $this->container->get(ITranslationAdapter::class);
		} catch (Throwable $e) {
			return ['error', 'Decidiq could not load the translation adapter: ' . $e->getMessage()];
		}

		if (($adapter instanceof LogTranslationAdapter) === false) {
			return ['configured', 'A translation adapter is bound: ' . get_debug_type($adapter) . '. Decidiq does not test it.'];
		}

		foreach (LogTranslationAdapter::OPENCONNECTOR_SERVICES as $relative) {
			if (FleetAppId::getService($this->container, 'integriq', $relative) !== null) {
				return ['configured', 'An integriq translation service answers. Decidiq does not test it.'];
			}
		}

		return ['simulated', 'No translation provider answers here. The queue keeps the original text.'];
	}//end observeTranslation()

	/**
	 * Whether the source lookup finds a source with this slug.
	 *
	 * A lookup that throws counts as not found, the same way the signing
	 * service treats it.
	 *
	 * @param object $sourceMapper The integriq source lookup.
	 * @param string $slug         The source slug.
	 *
	 * @return bool
	 */
	private function sourceExists(object $sourceMapper, string $slug): bool {
		try {
			return $sourceMapper->findBySlug(slug: $slug) !== null;
		} catch (Throwable) {
			return false;
		}
	}//end sourceExists()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * Build and dispatch one event, swallowing anything a listener throws.
	 *
	 * @param string             $key   The connection the event is about, for the log.
	 * @param callable(): object $build Builds the event.
	 *
	 * @return bool True when the event was dispatched without an exception.
	 */
	private function send(string $key, callable $build): bool {
		try {
			$event = $build();
			if (($event instanceof Event) === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not send a connection event to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end send()
}//end class
