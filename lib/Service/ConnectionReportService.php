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
 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Kernel\AppIdentity;
use OCA\Decidiq\Kernel\Contracts\ConnectionReportServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends connection reports and refresh requests to integriq.
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
 */
class ConnectionReportService implements ConnectionReportServiceInterface {

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
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
	 */
	public function reportBindings(): void {
		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return;
		}

		$observations = [
			'eidas' => $this->observeSigning(),
			'translation' => $this->observeTranslation(),
		];

		foreach ($observations as $key => [$status, $message]) {
			$this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: AppIdentity::APP_ID,
					key: $key,
					status: $status,
					message: $message,
				)
			);
		}
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
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-conn-001-decidiq-declares-its-outside-connections-in-one-static-file
	 */
	public function refreshFromSave(array $saved): void {
		$eventClass = $this->resolveEventClass(eventClass: self::REFRESH_EVENT);
		if ($eventClass === null) {
			return;
		}

		foreach (self::REFRESH_KEYS as $key => $configKeys) {
			if (array_intersect($configKeys, array_keys($saved)) === []) {
				continue;
			}

			$this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: AppIdentity::APP_ID,
					key: $key,
				)
			);
		}
	}//end refreshFromSave()

	/**
	 * What decidiq sees bound for eIDAS signing, as a status and a message.
	 *
	 * The delegating service hands every request to filinq (ADR-125), so it
	 * is reported configured; the log-only fallback is reported simulated.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
	 * @spec openspec/changes/sign-through-the-filinq-rail/tasks.md#task-4
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

		// The delegating service is bound only when filinq's signing event
		// loads (DomainServiceRegistrar), so it means filinq signs.
		return ['configured', 'filinq signs on its signing rail. Decidiq does not test it.'];
	}//end observeSigning()

	/**
	 * What decidiq sees bound for translation, as a status and a message.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
	 */
	public function observeTranslation(): array {
		try {
			$adapter = $this->container->get(ITranslationAdapter::class);
		} catch (Throwable $e) {
			return ['error', 'Decidiq could not load the translation adapter: ' . $e->getMessage()];
		}

		if (($adapter instanceof NextcloudTranslationAdapter) === false) {
			return ['configured', 'A translation adapter is bound: ' . get_debug_type($adapter) . '. Decidiq does not test it.'];
		}

		if ($adapter->isAvailable() === true) {
			return ['configured', 'A Nextcloud translation provider answers. Decidiq does not test it.'];
		}

		return [
			'unconfigured',
			'No Nextcloud translation provider is installed, so queued translations fail. Install a translation provider app to translate minutes.',
		];
	}//end observeTranslation()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/specs/admin-settings/spec.md#requirement-req-adm-conn-002-decidiq-reports-which-signing-and-translation-services-answer
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
	 * @return void
	 */
	private function send(string $key, callable $build): void {
		try {
			$event = $build();
			if (($event instanceof Event) === true) {
				$this->eventDispatcher->dispatchTyped($event);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Decidiq: could not send a connection event to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
		}
	}//end send()
}//end class
