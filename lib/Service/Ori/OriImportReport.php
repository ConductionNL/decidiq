<?php

/**
 * What an ORI import read, wrote, would write and could not resolve.
 *
 * @category Service
 * @package  OCA\Decidiq\Service\Ori
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Service\Ori;

/**
 * Counts per schema, the per-object preview, and every problem found.
 *
 * A problem is an object, reference or value the import could not carry
 * over. It is always reported; under `--strict` any problem fails the run.
 *
 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
 */
class OriImportReport {

	/**
	 * Source objects read, per source schema.
	 *
	 * @var array<string, int>
	 */
	private array $sourceCounts = [];

	/**
	 * Target objects created, updated and deleted, per target schema.
	 *
	 * @var array<string, array{created: int, updated: int, deleted: int}>
	 */
	private array $targetCounts = [];

	/**
	 * One line per object written or, in a dry run, that would be.
	 *
	 * @var array<int, array{action: string, schema: string, reference: string, label: string}>
	 */
	private array $preview = [];

	/**
	 * Every problem, in the order found.
	 *
	 * @var array<int, array{schema: string, source: string, problem: string}>
	 */
	private array $problems = [];

	/**
	 * Constructor.
	 *
	 * @param bool $dryRun Whether nothing was written.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the report's one fact, not a branch.
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function __construct(public readonly bool $dryRun=false) {
	}//end __construct()

	/**
	 * Record how many objects a source schema held.
	 *
	 * @param string $schema The source schema.
	 * @param int    $count  How many objects it held.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function read(string $schema, int $count): void {
		$this->sourceCounts[$schema] = (($this->sourceCounts[$schema] ?? 0) + $count);
	}//end read()

	/**
	 * Record one object written (or, in a dry run, that would be).
	 *
	 * @param string $action    created, updated or deleted.
	 * @param string $schema    The target schema.
	 * @param string $reference The object's import tag.
	 * @param string $label     A readable name for the preview.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function wrote(string $action, string $schema, string $reference, string $label=''): void {
		$counts = ($this->targetCounts[$schema] ?? ['created' => 0, 'updated' => 0, 'deleted' => 0]);
		$counts[$action] = (($counts[$action] ?? 0) + 1);
		$this->targetCounts[$schema] = $counts;
		$this->preview[] = ['action' => $action, 'schema' => $schema, 'reference' => $reference, 'label' => $label];
	}//end wrote()

	/**
	 * Record a problem.
	 *
	 * @param string $schema  The source schema.
	 * @param string $source  The source object's uuid or slug.
	 * @param string $problem What could not be carried over.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function problem(string $schema, string $source, string $problem): void {
		$this->problems[] = ['schema' => $schema, 'source' => $source, 'problem' => $problem];
	}//end problem()

	/**
	 * Source objects read, per source schema.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function sourceCounts(): array {
		return $this->sourceCounts;
	}//end sourceCounts()

	/**
	 * Target objects created, updated and deleted, per target schema.
	 *
	 * @return array<string, array{created: int, updated: int, deleted: int}>
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function targetCounts(): array {
		return $this->targetCounts;
	}//end targetCounts()

	/**
	 * One line per object written, or that would be.
	 *
	 * @return array<int, array{action: string, schema: string, reference: string, label: string}>
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function preview(): array {
		return $this->preview;
	}//end preview()

	/**
	 * Every problem, in the order found.
	 *
	 * @return array<int, array{schema: string, source: string, problem: string}>
	 *
	 * @spec openspec/changes/ori-adoption/specs/ori-adoption/spec.md
	 */
	public function problems(): array {
		return $this->problems;
	}//end problems()
}//end class
