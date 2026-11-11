<?php

/**
 * Decidiq Log Translation Adapter
 *
 * Default dormant ITranslationAdapter implementation. Logs the request and
 * either returns the original text or delegates to openconnector when its
 * translation source service is registered in the DI container. This keeps
 * the multilingual reconciliation pipeline functional in environments where
 * no translation provider has been configured yet.
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
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service;

use OCA\Decidiq\Support\FleetAppId;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Dormant default translation adapter.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class LogTranslationAdapter implements ITranslationAdapter {

	/**
	 * Integriq translation services, named relative to that app's root.
	 *
	 * Relative rather than fully qualified: each name is expanded across every
	 * namespace integriq has shipped under at resolve time.
	 *
	 * @var string[]
	 */
	/**
	 * The message of an answer that returns the original text, by provider.
	 *
	 * @var array<string, string>
	 */
	private const UNTRANSLATED_MESSAGES = [
		'noop' => 'Source and target locales are equal.',
		'log' => 'No translation provider configured; original text returned.',
	];

	public const OPENCONNECTOR_SERVICES = [
		'Service\\TranslationService',
		'Service\\TranslationSourceService',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy openconnector lookup)
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Translate text — delegates to openconnector when available; otherwise
	 * returns the original text untouched and flags the provider as `log`.
	 *
	 * @param string $text Source text
	 * @param string $sourceLocale ISO 639-1 source locale
	 * @param string $targetLocale ISO 639-1 target locale
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{success: bool, text: string, provider: string, message: string}
	 */
	public function translate(string $text, string $sourceLocale, string $targetLocale): array {
		if ($sourceLocale === $targetLocale) {
			return self::untranslated(text: $text, provider: 'noop');
		}

		$delegate = $this->resolveOpenConnector();
		if ($delegate !== null) {
			$translated = $this->askDelegate(
				delegate: $delegate,
				call: static fn (object $service): mixed => $service->translate($text, $sourceLocale, $targetLocale)
			);
			if ($translated !== null) {
				return $translated;
			}
		}

		$this->logger->info(
			'Decidiq: LogTranslationAdapter — translation requested (dormant default)',
			[
				'sourceLocale' => $sourceLocale,
				'targetLocale' => $targetLocale,
				'length' => strlen($text),
			]
		);

		return self::untranslated(text: $text, provider: 'log');

	}//end translate()

	/**
	 * The answer that returns the original text: `noop` for equal locales,
	 * `log` when no provider translated it.
	 *
	 * @param string $text     The source text
	 * @param string $provider noop or log
	 *
	 * @return array{success: bool, text: string, provider: string, message: string} The answer.
	 */
	private static function untranslated(string $text, string $provider): array {
		return [
			'success' => true,
			'text' => $text,
			'provider' => $provider,
			'message' => self::UNTRANSLATED_MESSAGES[$provider],
		];
	}//end untranslated()

	/**
	 * Ask the openconnector delegate to translate; null when it cannot, gives
	 * no usable answer, or fails (logged, then the log adapter answers).
	 *
	 * @param object   $delegate The openconnector translation service
	 * @param \Closure $call     Calls the delegate's translate() with the request
	 *
	 * @return array{success: bool, text: string, provider: string, message: string}|null The answer, or null.
	 */
	private function askDelegate(object $delegate, \Closure $call): ?array {
		try {
			if (method_exists($delegate, 'translate') === false) {
				return null;
			}

			return self::delegateAnswer(result: $call($delegate));
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Decidiq: openconnector translation delegate failed; falling back to log adapter',
				['exception' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end askDelegate()

	/**
	 * The adapter's answer for what the delegate returned: an array with text,
	 * or a non-empty string; null for anything else.
	 *
	 * @param mixed $result The delegate's answer
	 *
	 * @return array{success: bool, text: string, provider: string, message: string}|null The answer, or null.
	 */
	private static function delegateAnswer(mixed $result): ?array {
		if (is_array($result) === true && isset($result['text']) === true) {
			return self::arrayAnswer(result: $result);
		}

		if (is_string($result) === true && $result !== '') {
			return [
				'success' => true,
				'text' => $result,
				'provider' => 'openconnector',
				'message' => 'Translated via openconnector.',
			];
		}

		return null;
	}//end delegateAnswer()

	/**
	 * The adapter's answer for a delegate array that carries text, keeping the
	 * delegate's own success flag and message when it gives them.
	 *
	 * @param array<string, mixed> $result The delegate's answer
	 *
	 * @return array{success: bool, text: string, provider: string, message: string} The answer.
	 */
	private static function arrayAnswer(array $result): array {
		return [
			'success' => (bool)($result['success'] ?? true),
			'text' => (string)$result['text'],
			'provider' => 'openconnector',
			'message' => (string)($result['message'] ?? 'Translated via openconnector.'),
		];
	}//end arrayAnswer()

	/**
	 * Try to resolve an openconnector translation service from the DI container.
	 *
	 * @return object|null
	 */
	private function resolveOpenConnector(): ?object {
		// This list used to hold fully-qualified 'OCA\OpenConnector' names, so
		// class_exists() was false for both on any instance running the renamed
		// app and this method always returned null — which reads as
		// "translation is not configured" rather than as an error.
		foreach (self::OPENCONNECTOR_SERVICES as $relative) {
			$service = FleetAppId::getService($this->container, 'integriq', $relative);
			if ($service !== null) {
				return $service;
			}
		}

		return null;
	}//end resolveOpenConnector()
}//end class
