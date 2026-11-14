<?php

/**
 * Merges the register fragments of a directory over a base configuration.
 *
 * Each OpenSpec change drops its own fragment file in lib/Settings/register.d
 * instead of editing the monolithic register (ADR-037), so concurrent builds
 * touch disjoint files. OpenAPI `components.schemas` and `paths` are keyed
 * objects, so disjoint fragments union cleanly by key.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\Settings;

use Closure;

/**
 * One merge run: the merged configuration and the signature of the fragments read.
 *
 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
 */
class RegisterFragmentMerger {

	/**
	 * Name and content hash of every fragment merged in this run.
	 *
	 * @var string
	 */
	private string $signature = '';

	/**
	 * Create a merger.
	 *
	 * @param Closure|null $onMalformed Called with (basename, reason) for a fragment that does not parse.
	 *
	 * @return void
	 */
	public function __construct(
		private ?Closure $onMalformed=null,
	) {
	}//end __construct()

	/**
	 * Merge every `*.json` fragment of a directory, in name order, over a base.
	 *
	 * @param array<string,mixed> $configData  The base configuration.
	 * @param string              $fragmentDir The directory holding the fragments.
	 *
	 * @return array{0: array<string,mixed>, 1: string} [mergedConfig, fragmentSignature]
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 */
	public function mergeDirectory(array $configData, string $fragmentDir): array {
		$this->signature = '';
		foreach (self::fragmentFiles(fragmentDir: $fragmentDir) as $fragmentFile) {
			$configData = $this->mergeFile(configData: $configData, fragmentFile: $fragmentFile);
		}

		return [$configData, $this->signature];
	}//end mergeDirectory()

	/**
	 * The fragment files of a directory, sorted; none when it is not a directory.
	 *
	 * @param string $fragmentDir The directory.
	 *
	 * @return list<string>
	 */
	private static function fragmentFiles(string $fragmentDir): array {
		if (is_dir($fragmentDir) === false) {
			return [];
		}

		$fragmentFiles = glob($fragmentDir . '/*.json');
		if (is_array($fragmentFiles) === false) {
			return [];
		}

		sort($fragmentFiles);
		return $fragmentFiles;
	}//end fragmentFiles()

	/**
	 * Merge one fragment file; an unreadable or malformed one is skipped.
	 *
	 * @param array<string,mixed> $configData   The configuration so far.
	 * @param string              $fragmentFile The fragment file.
	 *
	 * @return array<string,mixed>
	 */
	private function mergeFile(array $configData, string $fragmentFile): array {
		$fragmentContent = file_get_contents($fragmentFile);
		if ($fragmentContent === false) {
			return $configData;
		}

		$fragmentData = json_decode($fragmentContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			if ($this->onMalformed !== null) {
				($this->onMalformed)(basename($fragmentFile), json_last_error_msg());
			}

			return $configData;
		}

		$this->signature .= basename($fragmentFile) . ':' . md5($fragmentContent) . ';';
		return self::deepMerge(base: $configData, overlay: $fragmentData);
	}//end mergeFile()

	/**
	 * Deep-merge a register fragment onto the base config (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`, `paths`) are
	 * merged by key union (recursing on shared keys); list arrays are concatenated;
	 * scalars in the fragment overwrite the base. Disjoint fragments never collide.
	 *
	 * @param array<mixed> $base    The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge in.
	 *
	 * @return array<mixed> The merged config.
	 *
	 * @spec openspec/changes/archive/2026-05-11-p1-crud-operations/tasks.md#task-2.3
	 */
	public static function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			$base[$key] = self::mergeValue(current: ($base[$key] ?? null), value: $value);
		}

		return $base;
	}//end deepMerge()

	/**
	 * The merged value of one key: a scalar overlay, or a base that is not an
	 * array, is overwritten; two lists concatenate; anything else recurses by key.
	 *
	 * @param mixed $current The base value, null when the base lacks the key.
	 * @param mixed $value   The overlay value.
	 *
	 * @return mixed
	 */
	private static function mergeValue(mixed $current, mixed $value): mixed {
		if (is_array($value) === false || is_array($current) === false) {
			return $value;
		}

		if (self::isList(value: $current) === true && self::isList(value: $value) === true) {
			return array_merge($current, $value);
		}

		return self::deepMerge(base: $current, overlay: $value);
	}//end mergeValue()

	/**
	 * Whether an array is empty or keyed 0..n-1 in order.
	 *
	 * @param array<mixed> $value The array.
	 *
	 * @return bool
	 */
	private static function isList(array $value): bool {
		return ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
	}//end isList()
}//end class
