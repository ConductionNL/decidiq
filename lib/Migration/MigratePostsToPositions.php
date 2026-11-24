<?php
/**
 * Decidiq MigratePostsToPositions.
 *
 * One-shot, idempotent, resume-safe repair migration for
 * configurable-types-domain-model task 3.5: copies every `post` onto a
 * `position-type`, every membership's `post` onto a `position-hold`, and points
 * every appointment's `targetPosts` at the copied position types.
 *
 * @category Migration
 * @package  OCA\Decidiq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Migration;

use OCA\Decidiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Decidiq\Service\AppointmentPositionHolds;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves posts onto positions and the holding of a post onto position holds.
 *
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
 */
class MigratePostsToPositions implements IRepairStep {
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
	 * How many rows of one schema are read at most.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

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
		return 'Copy Decidiq posts onto positions and position holds';

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
	 * Copy positions, then holds, then repoint appointments, inside the caller's system scope.
	 *
	 * @param object  $objectService The OR ObjectService.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	private function migrateAll(object $objectService, IOutput $output): void {
		$posts     = $this->postsById(objectService: $objectService);
		$positions = $this->copyPositions(objectService: $objectService, output: $output, posts: $posts);
		$holds     = $this->copyHolds(objectService: $objectService, output: $output, posts: $posts, positions: $positions);
		$repointed = $this->repointAppointments(
			objectService: $objectService,
			output: $output,
			posts: $posts,
			positions: $positions
		);

		$output->info(
			'Decidiq post migration complete: ' . count($positions) . ' position(s) known, '
			. $holds . ' hold(s) and ' . $repointed . ' appointment(s) written.'
		);

	}//end migrateAll()

	/**
	 * Every stored post, keyed by its identifier.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,array<string,mixed>> Posts by identifier.
	 */
	private function postsById(object $objectService): array {
		$posts = [];
		foreach ($this->readRows(objectService: $objectService, schema: 'post', limit: self::LIMIT) as $post) {
			$identifier = $this->identifierOf(object: $post);
			if ($identifier !== '') {
				$posts[$identifier] = $post;
			}
		}

		return $posts;

	}//end postsById()

	/**
	 * Copy each post that has no position type yet.
	 *
	 * @param object                             $objectService The OR ObjectService.
	 * @param IOutput                            $output        Progress reporting.
	 * @param array<string,array<string,mixed>>  $posts         Posts by identifier.
	 *
	 * @return array<string,string> Position type identifier by post identifier.
	 */
	private function copyPositions(object $objectService, IOutput $output, array $posts): array {
		$positions = $this->positionsByOrigin(objectService: $objectService);

		foreach ($posts as $postId => $post) {
			if (isset($positions[$postId]) === true) {
				continue;
			}

			$body = $this->resolveBody(objectService: $objectService, reference: (string)($post['governanceBody'] ?? ''));
			if ($body === '') {
				// `governanceBody` is required on a position type, and a position
				// belonging to no body is not a position.
				continue;
			}

			$saved = $this->save(
				objectService: $objectService,
				output: $output,
				schema: 'position-type',
				payload: self::positionPayload(post: $post, body: $body, origin: $postId)
			);
			if ($saved !== '') {
				$positions[$postId] = $saved;
			}
		}

		return $positions;

	}//end copyPositions()

	/**
	 * Position types already copied, keyed by the post they came from.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,string> Position type identifier by post identifier.
	 */
	private function positionsByOrigin(object $objectService): array {
		$index = [];
		foreach ($this->readRows(objectService: $objectService, schema: 'position-type', limit: self::LIMIT) as $type) {
			$origin = trim((string)($type[self::ORIGIN_KEY] ?? ''));
			$id     = $this->identifierOf(object: $type);
			if ($origin !== '' && $id !== '') {
				$index[$origin] = $id;
			}
		}

		return $index;

	}//end positionsByOrigin()

	/**
	 * The position type a post becomes.
	 *
	 * @param array<string,mixed> $post   The post.
	 * @param string              $body   The already-resolved governance body.
	 * @param string              $origin The post's identifier.
	 *
	 * @return array<string,string> The payload.
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public static function positionPayload(array $post, string $body, string $origin): array {
		$role = trim((string)($post['role'] ?? ''));
		$name = trim((string)($post['label'] ?? ''));
		if ($name === '') {
			$name = ucfirst(str_replace('-', ' ', $role));
		}

		return array_filter(
			[
				'name' => $name,
				'key' => $role,
				'governanceBody' => $body,
				self::ORIGIN_KEY => $origin,
			],
			static fn (string $value): bool => $value !== ''
		);

	}//end positionPayload()

	/**
	 * Give every membership that names a post a hold on the post's position.
	 *
	 * @param object                            $objectService The OR ObjectService.
	 * @param IOutput                           $output        Progress reporting.
	 * @param array<string,array<string,mixed>> $posts         Posts by identifier.
	 * @param array<string,string>              $positions     Position type by post identifier.
	 *
	 * @return int How many holds were written.
	 */
	private function copyHolds(object $objectService, IOutput $output, array $posts, array $positions): int {
		$existing = $this->existingHolds(objectService: $objectService);
		$written  = 0;

		foreach ($this->readRows(objectService: $objectService, schema: 'membership', limit: self::LIMIT) as $membership) {
			$postId = $this->postIdentifier(
				objectService: $objectService,
				posts: $posts,
				reference: (string)($membership['post'] ?? '')
			);
			$membershipId = $this->identifierOf(object: $membership);
			$position     = ($positions[$postId] ?? '');
			if ($membershipId === '' || $position === '' || isset($existing[$membershipId . '|' . $position]) === true) {
				continue;
			}

			$hold = self::holdPayload(
				membership: $membership,
				membershipId: $membershipId,
				post: ($posts[$postId] ?? []),
				position: $position,
				body: $this->resolveBody(objectService: $objectService, reference: (string)($membership['governanceBody'] ?? ''))
			);
			if ($hold === null) {
				continue;
			}

			if ($this->save(objectService: $objectService, output: $output, schema: 'position-hold', payload: $hold) !== '') {
				$existing[$membershipId . '|' . $position] = true;
				$written++;
			}
		}//end foreach

		return $written;

	}//end copyHolds()

	/**
	 * The identifier of the post a stored reference names.
	 *
	 * A stored reference is normally the post's uuid, but a seeded one can
	 * still hold the slug it was written with.
	 *
	 * @param object                            $objectService The OR ObjectService.
	 * @param array<string,array<string,mixed>> $posts         Posts by identifier.
	 * @param string                            $reference     The reference as stored.
	 *
	 * @return string The post identifier, or the reference unchanged when it names none.
	 */
	private function postIdentifier(object $objectService, array $posts, string $reference): string {
		$reference = trim($reference);
		if ($reference === '' || isset($posts[$reference]) === true) {
			return $reference;
		}

		return $this->resolveReference(objectService: $objectService, schema: 'post', reference: $reference);

	}//end postIdentifier()

	/**
	 * Holds already stored, keyed by membership and position.
	 *
	 * @param object $objectService The OR ObjectService.
	 *
	 * @return array<string,bool> Keyed `membership|position`.
	 */
	private function existingHolds(object $objectService): array {
		$index = [];
		foreach ($this->readRows(objectService: $objectService, schema: 'position-hold', limit: self::LIMIT) as $hold) {
			$index[(string)($hold['membership'] ?? '') . '|' . (string)($hold['position'] ?? '')] = true;
		}

		return $index;

	}//end existingHolds()

	/**
	 * The hold a membership's post becomes, or null when it would need an invented start date.
	 *
	 * The post's dates described the holder, so they become the hold's; when the
	 * post has none, the membership's start is when the holder took it up.
	 *
	 * @param array<string,mixed> $membership   The membership.
	 * @param string              $membershipId The membership's identifier.
	 * @param array<string,mixed> $post         The post it named.
	 * @param string              $position     The post's position type.
	 * @param string              $body         The membership's resolved body.
	 *
	 * @return array<string,string>|null The payload.
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public static function holdPayload(
		array $membership,
		string $membershipId,
		array $post,
		string $position,
		string $body
	): ?array {
		$start = self::day(value: (string)($post['startDate'] ?? ''));
		if ($start === '') {
			$start = self::day(value: (string)($membership['startDate'] ?? ''));
		}

		if ($start === '') {
			return null;
		}

		return array_filter(
			[
				'membership' => $membershipId,
				'position' => $position,
				'governanceBody' => $body,
				'holdType' => AppointmentPositionHolds::DEFAULT_HOLD_TYPE,
				'startDate' => $start,
				'endDate' => self::day(value: (string)($post['endDate'] ?? '')),
			],
			static fn (string $value): bool => $value !== ''
		);

	}//end holdPayload()

	/**
	 * Point every appointment's target posts at their position types.
	 *
	 * An appointment is rewritten only when every entry maps: a half-moved list
	 * would pair candidates with the wrong index.
	 *
	 * @param object                            $objectService The OR ObjectService.
	 * @param IOutput                           $output        Progress reporting.
	 * @param array<string,array<string,mixed>> $posts         Posts by identifier.
	 * @param array<string,string>              $positions     Position type by post identifier.
	 *
	 * @return int How many appointments were rewritten.
	 */
	private function repointAppointments(object $objectService, IOutput $output, array $posts, array $positions): int {
		$rewritten = 0;
		foreach ($this->readRows(objectService: $objectService, schema: 'decision', limit: self::LIMIT) as $decision) {
			$stored = array_map(
				fn (mixed $target): string => $this->postIdentifier(objectService: $objectService, posts: $posts, reference: (string)$target),
				array_values((array)($decision['targetPosts'] ?? []))
			);
			$targets = self::repointedTargets(targets: $stored, positions: $positions);
			$id      = $this->identifierOf(object: $decision);
			if ($targets === null || $id === '') {
				continue;
			}

			$payload = $decision;
			$payload['targetPosts'] = $targets;
			unset($payload['@self']);

			if ($this->save(objectService: $objectService, output: $output, schema: 'decision', payload: $payload, uuid: $id) !== '') {
				$rewritten++;
			}
		}

		return $rewritten;

	}//end repointAppointments()

	/**
	 * The target list with every post replaced by its position, or null when nothing should be written.
	 *
	 * Null when the list is empty, names no post, or names a post that has no
	 * position (moving part of it would break the index pairing).
	 *
	 * @param array<mixed>         $targets   The stored targets.
	 * @param array<string,string> $positions Position type by post identifier.
	 *
	 * @return list<string>|null The rewritten list.
	 *
	 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-006-a-membership-may-hold-a-position-for-a-duration-with-a-type
	 */
	public static function repointedTargets(array $targets, array $positions): ?array {
		$known   = array_flip(array_values($positions));
		$changed = false;
		$out     = [];
		foreach ($targets as $target) {
			$target = (string)$target;
			if (isset($positions[$target]) === true) {
				$out[]   = $positions[$target];
				$changed = true;
				continue;
			}

			if (isset($known[$target]) === false) {
				return null;
			}

			$out[] = $target;
		}

		if ($changed === false) {
			return null;
		}

		return $out;

	}//end repointedTargets()

	/**
	 * Save one object; a failure is reported and answers ''.
	 *
	 * @param object              $objectService The OR ObjectService.
	 * @param IOutput             $output        Progress reporting.
	 * @param string              $schema        The target schema.
	 * @param array<string,mixed> $payload       The object.
	 * @param string|null         $uuid          The object to update, null to create.
	 *
	 * @return string The saved object's identifier, or '' on failure.
	 */
	private function save(object $objectService, IOutput $output, string $schema, array $payload, ?string $uuid = null): string {
		try {
			$objectService->setRegister(self::REGISTER);
			$objectService->setSchema($schema);
			$saved = $objectService->saveObject(
				object: $payload,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: $schema), uuid: $uuid)
			);

			return $this->identifierOf(object: $this->toArray(entity: $saved));
		} catch (Throwable $e) {
			$output->warning('Failed to migrate one ' . $schema . ': ' . $e->getMessage());
			$this->logger->warning(
				'Decidiq: post migration failed for one row',
				['schema' => $schema, 'error' => $e->getMessage(), 'object' => $uuid]
			);
			return '';
		}//end try

	}//end save()

	/**
	 * The calendar day of a date or date-time, or '' when it has none.
	 *
	 * @param string $value A date or date-time.
	 *
	 * @return string The day, as YYYY-MM-DD.
	 */
	private static function day(string $value): string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value), $match) !== 1) {
			return '';
		}

		return $match[0];

	}//end day()
}//end class
