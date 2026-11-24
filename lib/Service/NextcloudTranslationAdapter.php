<?php

/**
 * Decidiq Nextcloud Translation Adapter
 *
 * Default ITranslationAdapter implementation. Translates through Nextcloud's
 * own TaskProcessing API (`core:text2text:translate`), so any installed
 * translation provider (for example the Local Machine Translation app or an
 * LLM provider app) translates minutes. Without such a provider the adapter
 * answers `success: false`, so a queued translation fails visibly instead of
 * completing with the untranslated source text.
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

use OCA\Decidiq\AppInfo\Application;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Translation adapter backed by Nextcloud TaskProcessing.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
class NextcloudTranslationAdapter implements ITranslationAdapter {

	/**
	 * The TaskProcessing task type this adapter runs.
	 *
	 * @var string
	 */
	public const TASK_TYPE = TextToTextTranslate::ID;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy TaskProcessing lookup)
	 * @param LoggerInterface    $logger    Logger
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether an installed TaskProcessing provider can translate text.
	 *
	 * @return bool True when the translate task type is available.
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 */
	public function isAvailable(): bool {
		$manager = $this->manager();
		if ($manager === null) {
			return false;
		}

		try {
			return in_array(self::TASK_TYPE, $manager->getAvailableTaskTypeIds(), true);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidiq: the TaskProcessing task types could not be read',
				['error' => $e->getMessage()]
			);
			return false;
		}
	}//end isAvailable()

	/**
	 * Translate text through the TaskProcessing translate task type.
	 *
	 * @param string $text         Source text
	 * @param string $sourceLocale ISO 639-1 source locale
	 * @param string $targetLocale ISO 639-1 target locale
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{success: bool, text: string, provider: string, message: string}
	 */
	public function translate(string $text, string $sourceLocale, string $targetLocale): array {
		if ($sourceLocale === $targetLocale) {
			return self::answer(success: true, text: $text, provider: 'noop', message: 'Source and target locales are equal.');
		}

		$manager = $this->manager();
		if ($manager === null || $this->isAvailable() === false) {
			$this->logger->info(
				'Decidiq: translation requested but no Nextcloud translation provider is installed',
				['sourceLocale' => $sourceLocale, 'targetLocale' => $targetLocale]
			);

			return self::answer(
				success: false,
				text: $text,
				provider: 'none',
				message: 'No Nextcloud translation provider is installed; the minutes were not translated.'
			);
		}

		$provider = $this->providerId(manager: $manager);

		try {
			$input  = ['input' => $text, 'origin_language' => $sourceLocale, 'target_language' => $targetLocale];
			$result = $manager->runTask(new Task(self::TASK_TYPE, $input, Application::APP_ID, null));
		} catch (\Throwable $e) {
			$this->logger->warning('Decidiq: TaskProcessing translation failed', ['exception' => $e->getMessage()]);
			return self::answer(success: false, text: $text, provider: $provider, message: 'Translation failed: ' . $e->getMessage());
		}

		$translated = self::outputText(output: $result->getOutput());
		if ($result->getStatus() !== Task::STATUS_SUCCESSFUL || $translated === '') {
			$why = ($result->getErrorMessage() ?? 'the provider returned no text.');
			return self::answer(success: false, text: $text, provider: $provider, message: 'Translation failed: ' . $why);
		}

		return self::answer(success: true, text: $translated, provider: $provider, message: 'Translated via Nextcloud TaskProcessing.');
	}//end translate()

	/**
	 * The translated text in a task's output, or '' when there is none.
	 *
	 * @param mixed $output The task output
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return string
	 */
	private static function outputText(mixed $output): string {
		$value = null;
		if (is_array($output) === true) {
			$value = ($output['output'] ?? null);
		}

		if (is_string($value) === true) {
			return $value;
		}

		return '';
	}//end outputText()

	/**
	 * The adapter's answer.
	 *
	 * @param bool   $success  Whether the text was translated
	 * @param string $text     The translated text, or the source text on failure
	 * @param string $provider Which provider answered
	 * @param string $message  What happened
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{success: bool, text: string, provider: string, message: string}
	 */
	private static function answer(bool $success, string $text, string $provider, string $message): array {
		return ['success' => $success, 'text' => $text, 'provider' => $provider, 'message' => $message];
	}//end answer()

	/**
	 * The TaskProcessing manager, or null when the container cannot give one.
	 *
	 * @return IManager|null
	 */
	private function manager(): ?IManager {
		try {
			$manager = $this->container->get(IManager::class);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Decidiq: TaskProcessing manager unavailable',
				['error' => $e->getMessage()]
			);
			return null;
		}

		if (($manager instanceof IManager) === false) {
			return null;
		}

		return $manager;
	}//end manager()

	/**
	 * The preferred translate provider's id, for the queue entry's provenance.
	 *
	 * @param IManager $manager The TaskProcessing manager.
	 *
	 * @return string The provider id, or `taskprocessing` when it cannot be read.
	 */
	private function providerId(IManager $manager): string {
		try {
			return $manager->getPreferredProvider(self::TASK_TYPE)->getId();
		} catch (\Throwable) {
			return 'taskprocessing';
		}
	}//end providerId()
}//end class
