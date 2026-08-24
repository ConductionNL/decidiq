<?php

/**
 * Decidiq Translation Adapter Interface
 *
 * Pluggable boundary for an external translation source. The default
 * `LogTranslationAdapter` implementation logs and returns the original
 * text; production deployments rebind the binding to an openconnector-
 * backed translation source.
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

/**
 * Translation adapter contract.
 *
 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
 */
interface ITranslationAdapter {
	/**
	 * Translate a body of text from a source to a target locale.
	 *
	 * @param string $text The text to translate
	 * @param string $sourceLocale ISO 639-1 source locale (e.g. nl)
	 * @param string $targetLocale ISO 639-1 target locale (e.g. en)
	 *
	 * @spec openspec/changes/board-meeting-resolutions/tasks.md#task-6.3
	 *
	 * @return array{success: bool, text: string, provider: string, message: string}
	 */
	public function translate(string $text, string $sourceLocale, string $targetLocale): array;
}//end interface
