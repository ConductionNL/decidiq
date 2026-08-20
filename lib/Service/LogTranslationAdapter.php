<?php

/**
 * Decidesk Log Translation Adapter
 *
 * Default dormant ITranslationAdapter implementation. Logs the request and
 * either returns the original text or delegates to openconnector when its
 * translation source service is registered in the DI container. This keeps
 * the multilingual reconciliation pipeline functional in environments where
 * no translation provider has been configured yet.
 *
 * @category Service
 * @package  OCA\Decidesk\Service
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

namespace OCA\Decidesk\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Dormant default translation adapter.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class LogTranslationAdapter implements ITranslationAdapter {

	/**
	 * Candidate FQCNs of an openconnector translation source service.
	 *
	 * @var string[]
	 */
	public const OPENCONNECTOR_CANDIDATES = [
		'\\OCA\\OpenConnector\\Service\\TranslationService',
		'\\OCA\\OpenConnector\\Service\\TranslationSourceService',
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
			return [
				'success' => true,
				'text' => $text,
				'provider' => 'noop',
				'message' => 'Source and target locales are equal.',
			];
		}

		$delegate = $this->resolveOpenConnector();
		if ($delegate !== null) {
			try {
				if (method_exists($delegate, 'translate') === true) {
					/*
					 * @var string|array<string, mixed> $result
					 */

					$result = $delegate->translate($text, $sourceLocale, $targetLocale);
					if (is_array($result) === true && isset($result['text']) === true) {
						return [
							'success' => (bool)($result['success'] ?? true),
							'text' => (string)$result['text'],
							'provider' => 'openconnector',
							'message' => (string)($result['message'] ?? 'Translated via openconnector.'),
						];
					}

					if (is_string($result) === true && $result !== '') {
						return [
							'success' => true,
							'text' => $result,
							'provider' => 'openconnector',
							'message' => 'Translated via openconnector.',
						];
					}
				}//end if
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Decidesk: openconnector translation delegate failed; falling back to log adapter',
					['exception' => $e->getMessage()]
				);
			}//end try
		}//end if

		$this->logger->info(
			'Decidesk: LogTranslationAdapter — translation requested (dormant default)',
			[
				'sourceLocale' => $sourceLocale,
				'targetLocale' => $targetLocale,
				'length' => strlen($text),
			]
		);

		return [
			'success' => true,
			'text' => $text,
			'provider' => 'log',
			'message' => 'No translation provider configured; original text returned.',
		];

	}//end translate()

	/**
	 * Try to resolve an openconnector translation service from the DI container.
	 *
	 * @return object|null
	 */
	private function resolveOpenConnector(): ?object {
		foreach (self::OPENCONNECTOR_CANDIDATES as $candidate) {
			if (class_exists($candidate) === false) {
				continue;
			}

			try {
				$service = $this->container->get($candidate);
				if (is_object($service) === true) {
					return $service;
				}
			} catch (\Throwable) {
				continue;
			}
		}

		return null;
	}//end resolveOpenConnector()
}//end class
