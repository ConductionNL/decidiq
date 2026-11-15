<?php
/**
 * Decidiq MigrateParticipationsToCompositions.
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * configurable-types-domain-model change (task 3.5): copies every
 * `body-participation` row onto the generic `governance-body-composition`,
 * as a `representation` composition of the shared body.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use OCA\Decidiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies the participations of shared bodies onto compositions.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
 */
class MigrateParticipationsToCompositions implements IRepairStep {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The retired source schema slug.
	 *
	 * @var string
	 */
	private const SOURCE = 'body-participation';

	/**
	 * The generic target schema slug.
	 *
	 * @var string
	 */
	private const TARGET = 'governance-body-composition';

	/**
	 * The key recording which source row a copied record came from.
	 *
	 * @var string
	 */
	private const ORIGIN_KEY = 'migratedFromObject';

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Reports whether OpenRegister is usable.
	 * @param ContainerInterface       $container       Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface          $logger          Records what was migrated.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The logger the shared legacy-row reads report through.
	 *
	 * @return LoggerInterface The logger.
	 *
	 * @spec exclude Trait accessor; exposes an already-injected dependency.
	 */
	protected function migrationLogger(): LoggerInterface {
		return $this->logger;

	}//end migrationLogger()

	/**
	 * Repair-step label.
	 *
	 * @return string The label.
	 *
	 * @spec exclude Trivial repair-step label accessor.
	 */
	public function getName(): string {
		return 'Copy Decidiq shared-body participations onto body compositions';

	}//end getName()

	/**
	 * Run the copy.
	 *
	 * 🔴 FAIL SOFT. A repair step that throws fails the whole `occ upgrade`.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
	 */
	public function run(IOutput $output): void {
		$runner = new SystemMigrationRunner(settingsService: $this->settingsService, container: $this->container);
		$runner->run(output: $output, migrateAll: $this->migrateAll(...));

	}//end run()

	/**
	 * Copy every participation, inside the caller's system scope, and report the count.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		$existing = $this->originIndex(objectService: $objectService);
		$migrated = 0;
		$skipped  = 0;

		foreach ($this->readRows(objectService: $objectService, schema: self::SOURCE, limit: 10000) as $source) {
			$origin = $this->identifierOf(object: $source);
			if ($origin === '' || isset($existing[$origin]) === true) {
				continue;
			}

			$composition = $this->mapComposition(objectService: $objectService, source: $source, origin: $origin);
			if ($composition === null) {
				// Both bodies are required on the target: a participation of
				// nothing in nothing is not a composition.
				$skipped++;
				continue;
			}

			if ($this->save(objectService: $objectService, output: $output, composition: $composition) === true) {
				$existing[$origin] = true;
				$migrated++;
			}
		}//end foreach

		$output->info(
			'Decidiq participation migration complete: '.$migrated.' composition(s), '.$skipped.' without both bodies.'
		);

	}//end migrateAll()

	/**
	 * Save one composition, reporting a refusal instead of throwing.
	 *
	 * @param object              $objectService The OR ObjectService.
	 * @param IOutput             $output        Progress reporting.
	 * @param array<string,mixed> $composition   The composition payload.
	 *
	 * @return bool True when it was saved.
	 */
	private function save(object $objectService, IOutput $output, array $composition): bool {
		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema(self::TARGET);
			$objectService->saveObject(register: self::REGISTER, schema: self::TARGET, object: $composition);
		} catch (Throwable $e) {
			$output->warning('Failed to migrate a participation: '.$e->getMessage());
			$this->logger->warning(
				'Decidiq: governance-body-composition migration failed for one row',
				['error' => $e->getMessage(), 'origin' => $composition[self::ORIGIN_KEY]]
			);
			return false;
		}

		return true;

	}//end save()

	/**
	 * Compositions already copied, keyed by the source they came from.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,bool> Keyed by source identifier.
	 */
	private function originIndex(object $objectService): array {
		$index = [];

		foreach ($this->readRows(objectService: $objectService, schema: self::TARGET, limit: 10000) as $object) {
			$origin = trim((string) ($object[self::ORIGIN_KEY] ?? ''));
			if ($origin !== '') {
				$index[$origin] = true;
			}
		}

		return $index;

	}//end originIndex()

	/**
	 * Map one participation onto a representation composition, or null when a body is missing.
	 *
	 * @param object              $objectService The OR ObjectService.
	 * @param array<string,mixed> $source        The legacy row.
	 * @param string              $origin        The source identifier.
	 *
	 * @return array<string,mixed>|null The generic payload.
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
	 */
	private function mapComposition(object $objectService, array $source, string $origin): ?array {
		$composite = $this->resolveBody(objectService: $objectService, reference: (string) ($source['sharedBody'] ?? ''));
		$component = $this->resolveBody(objectService: $objectService, reference: (string) ($source['participant'] ?? ''));
		if ($composite === '' || $component === '') {
			return null;
		}

		$payload = [
			'composite'       => $composite,
			'component'       => $component,
			'compositionType' => 'representation',
			self::ORIGIN_KEY  => $origin,
		];

		return array_merge($payload, $this->numbers(source: $source), $this->dates(source: $source), $this->label(source: $source));

	}//end mapComposition()

	/**
	 * The seats and voting weight, when the participation recorded them as numbers.
	 *
	 * @param array<string,mixed> $source The legacy row.
	 *
	 * @return array<string,int|float> `seats` and `votingWeight`, each only when numeric.
	 */
	private function numbers(array $source): array {
		$numbers = [];

		$seats = ($source['seats'] ?? null);
		if (is_numeric($seats) === true) {
			$numbers['seats'] = (int) $seats;
		}

		$weight = ($source['votingWeight'] ?? null);
		if (is_numeric($weight) === true) {
			$numbers['votingWeight'] = (float) $weight;
		}

		return $numbers;

	}//end numbers()

	/**
	 * The accession and exit dates as the calendar dates the composition stores.
	 *
	 * A participation stored date-times; the composition stores dates. The day
	 * the date-time names is kept, so an exit stays on the day it was recorded.
	 *
	 * @param array<string,mixed> $source The legacy row.
	 *
	 * @return array<string,string> `accessionDate` and `exitDate`, each only when it names a day.
	 */
	private function dates(array $source): array {
		$dates = [];
		foreach (['accessionDate', 'exitDate'] as $field) {
			$day = $this->calendarDay(value: (string) ($source[$field] ?? ''));
			if ($day !== '') {
				$dates[$field] = $day;
			}
		}

		return $dates;

	}//end dates()

	/**
	 * The YYYY-MM-DD day a date or date-time names, or '' when it names none.
	 *
	 * @param string $value The stored value.
	 *
	 * @return string The day.
	 */
	private function calendarDay(string $value): string {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match) !== 1) {
			return '';
		}

		if (checkdate((int) $match[2], (int) $match[3], (int) $match[1]) === false) {
			return '';
		}

		return $match[1].'-'.$match[2].'-'.$match[3];

	}//end calendarDay()

	/**
	 * The participation's label, when it had one.
	 *
	 * @param array<string,mixed> $source The legacy row.
	 *
	 * @return array<string,string> `label`, only when not blank.
	 */
	private function label(array $source): array {
		$label = trim((string) ($source['label'] ?? ''));
		if ($label === '') {
			return [];
		}

		return ['label' => $label];

	}//end label()
}//end class
