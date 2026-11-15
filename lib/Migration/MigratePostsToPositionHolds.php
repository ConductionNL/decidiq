<?php
/**
 * Decidiq MigratePostsToPositionHolds.
 *
 * One-shot, idempotent, resume-safe repair migration for the
 * configurable-types-domain-model change (task 3.5, the Post half): copies
 * every `post` row onto a `position-type` of its body, every membership's
 * `post` onto a `position-hold` on that membership, and repoints an
 * appointment decision's `targetPosts` from the posts to their position types.
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
 * Copies posts onto position types and the posts memberships held onto position holds.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
 */
class MigratePostsToPositionHolds implements IRepairStep {
	use ReadsLegacyRows;

	/**
	 * The decidiq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'decidiq';

	/**
	 * The key recording which source row a copied record came from.
	 *
	 * @var string
	 */
	private const ORIGIN_KEY = 'migratedFromObject';

	/**
	 * The hold type a copied post becomes: a post recorded no hold type, and
	 * holding it was the regular case.
	 *
	 * @var string
	 */
	private const HOLD_TYPE = 'regular';

	/**
	 * Readable names for the role enum the retired schema keyed on, used when a post has no label.
	 *
	 * @var array<string,string>
	 */
	private const ROLE_NAMES = [
		'chair' => 'Chair',
		'vice-chair' => 'Vice-chair',
		'secretary' => 'Secretary',
		'treasurer' => 'Treasurer',
		'member' => 'Member',
	];

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
		return 'Copy Decidiq posts onto position types and the posts held onto position holds';

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
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public function run(IOutput $output): void {
		$runner = new SystemMigrationRunner(settingsService: $this->settingsService, container: $this->container);
		$runner->run(output: $output, migrateAll: $this->migrateAll(...));

	}//end run()

	/**
	 * Copy posts, then holds, then repoint appointment targets, and report the counts.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		$posts = $this->readRows(objectService: $objectService, schema: 'post', limit: 10000);
		[$positions, $copied, $bodiless] = $this->copyPosts(objectService: $objectService, output: $output, posts: $posts);
		[$holds, $unheld] = $this->copyHolds(objectService: $objectService, output: $output, positions: $positions);
		$repointed = $this->repointAppointments(objectService: $objectService, output: $output, positions: $positions);

		$output->info(
			'Decidiq post migration complete: '.$copied.' position type(s), '.$bodiless.' post(s) without a body, '
			.$holds.' position hold(s), '.$unheld.' membership post(s) not copied, '.$repointed.' appointment decision(s) repointed.'
		);

	}//end migrateAll()

	/**
	 * Copy every post onto a position type of its body.
	 *
	 * @param object                         $objectService The OR ObjectService.
	 * @param IOutput                        $output        Progress reporting.
	 * @param array<int,array<string,mixed>> $posts         The legacy posts.
	 *
	 * @return array{0: array<string,array{id: string, body: string, start: string}>, 1: int, 2: int}
	 *     The positions keyed by every name a post is referenced by, the copied count and the count without a body.
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	private function copyPosts(object $objectService, IOutput $output, array $posts): array {
		$copiedBefore = $this->originIndex(objectService: $objectService, schema: 'position-type');
		$positions    = [];
		$copied       = 0;
		$bodiless     = 0;

		foreach ($posts as $post) {
			$origin = $this->identifierOf(object: $post);
			if ($origin === '') {
				continue;
			}

			$body = $this->resolveBody(objectService: $objectService, reference: (string)($post['governanceBody'] ?? ''));
			$id   = ($copiedBefore[$origin]['id'] ?? '');
			if ($id === '') {
				if ($body === '') {
					// `governanceBody` is required on a position type, and a
					// post of no body is not a position anyone can hold.
					$bodiless++;
					continue;
				}

				$id = $this->save(
					objectService: $objectService,
					output: $output,
					schema: 'position-type',
					payload: $this->mapPositionType(post: $post, body: $body, origin: $origin)
				);
				if ($id === '') {
					continue;
				}

				$copied++;
			}//end if

			$position = [
				'id' => $id,
				'body' => ($copiedBefore[$origin]['governanceBody'] ?? $body),
				'start' => $this->calendarDay(value: (string)($post['startDate'] ?? '')),
			];
			foreach ($this->namesOf(row: $post) as $name) {
				$positions[$name] = $position;
			}
		}//end foreach

		return [$positions, $copied, $bodiless];

	}//end copyPosts()

	/**
	 * Copy every membership's post onto a position hold on that membership.
	 *
	 * @param object                                                $objectService The OR ObjectService.
	 * @param IOutput                                               $output        Progress reporting.
	 * @param array<string,array{id: string, body: string, start: string}> $positions     The positions by post name.
	 *
	 * @return array{0: int, 1: int} The copied count and the count of membership posts not copied.
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-007-one-person-carries-two-independent-end-terms
	 */
	private function copyHolds(object $objectService, IOutput $output, array $positions): array {
		$heldBefore = $this->originIndex(objectService: $objectService, schema: 'position-hold');
		$copied     = 0;
		$unheld     = 0;

		foreach ($this->readRows(objectService: $objectService, schema: 'membership', limit: 10000) as $membership) {
			$origin = $this->identifierOf(object: $membership);
			$post   = trim((string)($membership['post'] ?? ''));
			if ($origin === '' || $post === '' || isset($heldBefore[$origin]) === true) {
				continue;
			}

			$hold = $this->mapHold(membership: $membership, position: ($positions[$post] ?? null), origin: $origin);
			if ($hold === null) {
				// The post is unknown, or nothing says when the holder started:
				// a hold needs both, and inventing either would invent a fact.
				$unheld++;
				continue;
			}

			if ($this->save(objectService: $objectService, output: $output, schema: 'position-hold', payload: $hold) !== '') {
				$heldBefore[$origin] = ['id' => $origin];
				$copied++;
			}
		}//end foreach

		return [$copied, $unheld];

	}//end copyHolds()

	/**
	 * Repoint every appointment decision's target posts at their position types.
	 *
	 * @param object                                                $objectService The OR ObjectService.
	 * @param IOutput                                               $output        Progress reporting.
	 * @param array<string,array{id: string, body: string, start: string}> $positions     The positions by post name.
	 *
	 * @return int The number of decisions repointed.
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	private function repointAppointments(object $objectService, IOutput $output, array $positions): int {
		if ($positions === []) {
			return 0;
		}

		$repointed = 0;
		foreach ($this->readRows(objectService: $objectService, schema: 'decision', limit: 10000) as $decision) {
			$targets = $decision['targetPosts'] ?? null;
			$id      = $this->identifierOf(object: $decision);
			if ($id === '' || is_array($targets) === false || $targets === []) {
				continue;
			}

			$changed = false;
			$mapped  = [];
			foreach ($targets as $target) {
				$name = trim((string)$target);
				if (isset($positions[$name]) === true) {
					$mapped[] = $positions[$name]['id'];
					$changed  = true;
					continue;
				}

				// Already a position type, or a post this run could not copy: kept as it is.
				$mapped[] = $target;
			}

			if ($changed === false) {
				continue;
			}

			unset($decision['@self']);
			$decision['targetPosts'] = $mapped;
			if ($this->save(objectService: $objectService, output: $output, schema: 'decision', payload: $decision, uuid: $id) !== '') {
				$repointed++;
			}
		}//end foreach

		return $repointed;

	}//end repointAppointments()

	/**
	 * Save one object, reporting a refusal instead of throwing.
	 *
	 * @param object              $objectService The OR ObjectService.
	 * @param IOutput             $output        Progress reporting.
	 * @param string              $schema        The target schema slug.
	 * @param array<string,mixed> $payload       The object.
	 * @param string|null         $uuid          The object to update, or null to create.
	 *
	 * @return string The saved object's identifier, or '' when it was refused.
	 */
	private function save(object $objectService, IOutput $output, string $schema, array $payload, ?string $uuid=null): string {
		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema($schema);
			$saved = $objectService->saveObject(register: self::REGISTER, schema: $schema, object: $payload, uuid: $uuid);
		} catch (Throwable $e) {
			$output->warning('Failed to migrate a post ('.$schema.'): '.$e->getMessage());
			$this->logger->warning(
				'Decidiq: post migration failed for one row',
				['error' => $e->getMessage(), 'schema' => $schema]
			);
			return '';
		}

		$id = $this->identifierOf(object: $this->toArray(entity: $saved));
		if ($id === '' && $uuid !== null) {
			return $uuid;
		}

		return $id;

	}//end save()

	/**
	 * Objects already copied into one schema, keyed by the source they came from.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $schema        The target schema slug.
	 *
	 * @return array<string,array<string,mixed>> The copied objects keyed by source identifier.
	 */
	private function originIndex(object $objectService, string $schema): array {
		$index = [];

		foreach ($this->readRows(objectService: $objectService, schema: $schema, limit: 10000) as $object) {
			$origin = trim((string)($object[self::ORIGIN_KEY] ?? ''));
			if ($origin !== '') {
				$index[$origin] = ['id' => $this->identifierOf(object: $object), 'governanceBody' => (string)($object['governanceBody'] ?? '')];
			}
		}

		return $index;

	}//end originIndex()

	/**
	 * Every name a row may be referenced by: its identifier and the slug a seed gave it.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return array<int,string> The names.
	 */
	private function namesOf(array $row): array {
		$self  = (array)($row['@self'] ?? []);
		$names = [$this->identifierOf(object: $row), (string)($self['slug'] ?? ''), (string)($row['slug'] ?? '')];

		return array_values(array_unique(array_filter(array_map('trim', $names), static fn (string $name): bool => $name !== '')));

	}//end namesOf()

	/**
	 * Map one post onto a position type.
	 *
	 * @param array<string,mixed> $post   The legacy post.
	 * @param string              $body   The resolved governance body.
	 * @param string              $origin The source identifier.
	 *
	 * @return array<string,mixed> The position type.
	 */
	private function mapPositionType(array $post, string $body, string $origin): array {
		$role  = trim((string)($post['role'] ?? ''));
		$label = trim((string)($post['label'] ?? ''));
		if ($label === '') {
			$label = (self::ROLE_NAMES[$role] ?? 'Post');
		}

		$payload = ['name' => $label, 'governanceBody' => $body, self::ORIGIN_KEY => $origin];
		if ($role !== '') {
			$payload['key'] = $role;
		}

		return $payload;

	}//end mapPositionType()

	/**
	 * Map one membership's post onto a position hold, or null when it cannot be held.
	 *
	 * The holder's dates are the membership's: a post's own dates described
	 * the position, so its start is only the fallback when the membership
	 * recorded none.
	 *
	 * @param array<string,mixed>                              $membership The membership.
	 * @param array{id: string, body: string, start: string}|null $position   The copied position.
	 * @param string                                           $origin     The membership identifier.
	 *
	 * @return array<string,mixed>|null The hold.
	 */
	private function mapHold(array $membership, ?array $position, string $origin): ?array {
		if ($position === null) {
			return null;
		}

		$start = $this->calendarDay(value: (string)($membership['startDate'] ?? ''));
		if ($start === '') {
			$start = $position['start'];
		}

		if ($start === '') {
			return null;
		}

		$hold = [
			'membership' => $origin,
			'position' => $position['id'],
			'holdType' => self::HOLD_TYPE,
			'startDate' => $start,
			self::ORIGIN_KEY => $origin,
		];
		if ($position['body'] !== '') {
			$hold['governanceBody'] = $position['body'];
		}

		$end = $this->calendarDay(value: (string)($membership['endDate'] ?? ''));
		if ($end !== '') {
			$hold['endDate'] = $end;
		}

		return $hold;

	}//end mapHold()

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

		if (checkdate((int)$match[2], (int)$match[3], (int)$match[1]) === false) {
			return '';
		}

		return $match[1].'-'.$match[2].'-'.$match[3];

	}//end calendarDay()
}//end class
