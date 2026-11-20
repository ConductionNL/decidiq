<?php

/**
 * The commitments register: the schema, its declared lifecycle, its
 * notification rules and public read predicate, and the municipality seed.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Settings;

use DateTimeImmutable;
use OCA\Decidiq\Tests\Unit\Support\MergedRegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * Register and seed tests for toezeggingen-ingekomen-stukken tasks 1 and 2.
 *
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/tasks.md#task-1-register-fragment--toezegging--ingekomenstuk-schemas-with-declarative-dialects
 */
class CommitmentsRegisterTest extends TestCase {

	/**
	 * The schema slug the commitment ships under.
	 *
	 * @var string
	 */
	private const SLUG = 'governance-commitment';

	/**
	 * The commitment carries its required fields, each titled and typed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-001-toezegging-schema-on-openregister
	 */
	public function testTheCommitmentCarriesItsFields(): void {
		$schema     = MergedRegisterSchema::forSlug(slug: self::SLUG);
		$properties = $schema['properties'];

		self::assertSame(expected: 'schema:Action', actual: $schema['x-schema-org']);
		self::assertSame(expected: ['text', 'madeBy', 'meeting', 'directedTo', 'lifecycle'], actual: $schema['required']);
		self::assertSame(expected: ['Person', 'uuid'], actual: [$properties['madeBy']['$ref'], $properties['madeBy']['format']]);
		self::assertSame(expected: ['Meeting', 'uuid'], actual: [$properties['meeting']['$ref'], $properties['meeting']['format']]);
		self::assertSame(expected: ['governance-body', 'uuid'], actual: [$properties['directedTo']['$ref'], $properties['directedTo']['format']]);
		self::assertSame(expected: 'date', actual: $properties['deadline']['format']);

		$fields = [
			'text',
			'madeBy',
			'meeting',
			'agendaItem',
			'directedTo',
			'deadline',
			'lifecycle',
			'settlementNotes',
			'settlementEvidence',
			'relatedMotion',
			'publicationDate',
		];
		foreach ($fields as $field) {
			self::assertArrayHasKey(key: $field, array: $properties);
			self::assertNotSame(expected: '', actual: (string)($properties[$field]['title'] ?? ''), message: $field . ' has no title');
		}

	}//end testTheCommitmentCarriesItsFields()

	/**
	 * The lifecycle is declared in the canonical dialect: open, then in
	 * execution, then disposed; lapsing is possible before disposal only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-002-declarative-lifecycle
	 */
	public function testTheLifecycleIsDeclaredInTheCanonicalDialect(): void {
		$schema    = MergedRegisterSchema::forSlug(slug: self::SLUG);
		$lifecycle = $schema['x-openregister-lifecycle'];

		self::assertSame(expected: 'lifecycle', actual: $lifecycle['field']);
		self::assertSame(expected: 'open', actual: $lifecycle['initial']);
		self::assertArrayNotHasKey(key: 'initialState', array: $lifecycle);
		self::assertSame(expected: ['open', 'in-execution', 'disposed', 'lapsed'], actual: $lifecycle['states']);
		self::assertSame(expected: ['disposed', 'lapsed'], actual: $lifecycle['terminal']);
		self::assertSame(
			expected: [
				['from' => 'open', 'to' => 'in-execution'],
				['from' => 'open', 'to' => 'lapsed'],
				['from' => 'in-execution', 'to' => 'disposed'],
				['from' => 'in-execution', 'to' => 'lapsed'],
			],
			actual: $lifecycle['transitions']
		);
		self::assertSame(expected: [], actual: array_diff($lifecycle['states'], $schema['properties']['lifecycle']['enum']));

	}//end testTheLifecycleIsDeclaredInTheCanonicalDialect()

	/**
	 * Registration, disposal and both reminders are declared rules with a
	 * Dutch and an English subject; the reminders skip terminal commitments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-004-declarative-reminders
	 */
	public function testTheNotificationsAreDeclared(): void {
		$rules = MergedRegisterSchema::forSlug(slug: self::SLUG)['x-openregister-notifications'];

		self::assertSame(expected: 'created', actual: $rules['toezeggingRegistered']['trigger']['type']);
		self::assertSame(
			expected: ['field' => 'lifecycle', 'operator' => 'equals', 'value' => 'disposed', 'from' => 'in-execution'],
			actual: $rules['toezeggingAfgedaan']['trigger']['condition']
		);
		foreach (['toezeggingRappelBeforeDeadline', 'toezeggingRappelOverdue'] as $reminder) {
			self::assertSame(expected: 'scheduled', actual: $rules[$reminder]['trigger']['type']);
			self::assertSame(expected: ['open', 'in-execution'], actual: $rules[$reminder]['trigger']['filter']['lifecycle']);
		}

		foreach ($rules as $name => $rule) {
			self::assertNotSame(expected: '', actual: (string)($rule['subject']['nl'] ?? ''), message: $name . ' has no Dutch subject');
			self::assertNotSame(expected: '', actual: (string)($rule['subject']['en'] ?? ''), message: $name . ' has no English subject');
		}

	}//end testTheNotificationsAreDeclared()

	/**
	 * A commitment is readable anonymously only once its publication date
	 * has passed; signed-in users read every commitment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate
	 */
	public function testThePublicReadsOnlyPublishedCommitments(): void {
		$read = MergedRegisterSchema::forSlug(slug: self::SLUG)['authorization']['read'];

		self::assertContains(
			needle: ['group' => 'public', 'match' => ['publicationDate' => ['$lte' => '$now']], 'crossOrganisation' => true],
			haystack: $read
		);
		self::assertContains(needle: 'authenticated', haystack: $read);
		self::assertNotContains(needle: 'public', haystack: $read, message: 'an unconditional public read would expose unpublished commitments');

	}//end testThePublicReadsOnlyPublishedCommitments()

	/**
	 * The incoming-document schema is retired, not deleted: incoming
	 * correspondence is an agenda item now.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/ingekomen-stukken-register/spec.md#requirement-req-001-ingekomenstuk-schema-on-openregister
	 */
	public function testTheIncomingDocumentSchemaIsRetired(): void {
		$schema = MergedRegisterSchema::forSlug(slug: 'ingekomen-stuk');

		self::assertFalse(condition: $schema['x-openregister']['active']);
		self::assertFalse(condition: $schema['x-openregister']['hardDelete']);

	}//end testTheIncomingDocumentSchemaIsRetired()

	/**
	 * The municipality example set seeds an overdue open commitment, one in
	 * execution and a disposed one with its settlement and motion, and every
	 * seeded commitment fits the schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/toezeggingen-ingekomen-stukken/tasks.md#task-2-seed-data--realistic-dutch-municipal-objects-for-both-schemas
	 */
	public function testTheExampleSetSeedsEachKindOfCommitment(): void {
		$seeds = self::seededCommitments();
		$today = (new DateTimeImmutable('today'))->format('Y-m-d');

		self::assertGreaterThanOrEqual(expected: 3, actual: count($seeds));
		foreach ($seeds as $seed) {
			$payload = array_diff_key($seed, ['@self' => true, 'slug' => true]);
			$refused = MergedRegisterSchema::violations(slug: self::SLUG, payload: $payload);
			self::assertSame(expected: [], actual: $refused, message: (string)$seed['text']);
		}

		$overdueOpen = array_filter(
			$seeds,
			static fn (array $seed): bool => ($seed['lifecycle'] === 'open' && isset($seed['deadline']) === true && $seed['deadline'] < $today)
		);
		$inExecution = array_filter($seeds, static fn (array $seed): bool => $seed['lifecycle'] === 'in-execution');
		$settled     = array_filter(
			$seeds,
			static fn (array $seed): bool => ($seed['lifecycle'] === 'disposed'
				&& (string)($seed['settlementNotes'] ?? '') !== ''
				&& isset($seed['relatedMotion']) === true)
		);

		self::assertNotSame(expected: [], actual: $overdueOpen, message: 'no open commitment past its deadline');
		self::assertNotSame(expected: [], actual: $inExecution, message: 'no commitment in execution');
		self::assertNotSame(expected: [], actual: $settled, message: 'no disposed commitment with its settlement and motion');

	}//end testTheExampleSetSeedsEachKindOfCommitment()

	/**
	 * The commitments the municipality profile seeds.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function seededCommitments(): array {
		$profile = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/municipality.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		return array_values($profile['x-openregister']['seedData']['objects'][self::SLUG] ?? []);

	}//end seededCommitments()

}//end class
