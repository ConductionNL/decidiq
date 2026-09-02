<?php

/**
 * Unit tests for the Decidiq register JSON (decidesk_register.json).
 *
 * Validates that all schemas are defined with correct properties, types,
 * required fields, enum values, relations, and seed data.
 *
 * ADR-005 (unify-decision-supertype): the standalone Motion / Amendment /
 * Resolution schemas were folded into a single Decision supertype carrying a
 * `decisionType` discriminator (motion | amendment | resolution | …). The
 * board-portal schemas (Board, BoardMember, BoardMeeting, Resolution,
 * BoardVote, BoardMinutes, BoardMaterial, BoardAuditLogEntry) were retired by
 * retire-board-portal. DecisionStage (decision-route-and-stages) plus the
 * popolo decision-maker schemas (Person, Membership, Post, ContactDetail) and
 * the publication / transcript schemas were added downstream.
 * board-self-evaluation added EvaluationTemplate, BoardEvaluation and
 * EvaluationResponse. These tests assert the resulting unified 37-schema
 * model.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit;

use OCA\Decidiq\AppInfo\Application;
use OCA\Decidiq\Service\DecisionTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for decidesk_register.json schema definitions.
 *
 * @uses \OCA\Decidiq\Service\DecisionTypeRegistry
 *
 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
 */
class RegisterJsonTest extends TestCase {

	/**
	 * The decoded register JSON data.
	 *
	 * @var array<string,mixed>
	 */
	private array $register;

	/**
	 * The schemas from the register.
	 *
	 * @var array<string,mixed>
	 */
	private array $schemas;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../lib/Settings/decidesk_register.json';
		$json = file_get_contents(filename: $path);
		self::assertNotFalse(condition: $json, message: 'Register JSON file must exist');
		$this->register = json_decode(json: $json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		$this->schemas = ($this->register['components']['schemas'] ?? []);

	}//end setUp()

	/**
	 * Every example object this app ships, keyed by schema slug.
	 *
	 * 🔴 THE SEEDS MOVED OUT OF THE REGISTER, SO THESE ASSERTIONS FOLLOW THEM.
	 * Until the seed-profiles change, `decidesk_register.json` and its 26
	 * `register.d` fragments each carried their own
	 * `x-openregister.seedData.objects`, and installing the app planted all 334
	 * of them. They now live in `lib/Settings/profiles/*.json`, one file per
	 * example set, and nothing is planted until an operator picks one. A test
	 * that still read the register would report "no decision seeds" and be
	 * describing the intended state as a failure.
	 *
	 * The union across sets is the right subject here: these tests ask whether
	 * the app SHIPS demonstrable data for a schema, not which set carries it.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Objects per schema slug.
	 */
	private function profileSeeds(): array {
		$files = glob(__DIR__ . '/../../lib/Settings/profiles/*.json');
		self::assertNotEmpty(actual: $files, message: 'App must ship at least one example set');

		$merged = [];
		foreach ($files as $file) {
			$json = file_get_contents(filename: $file);
			self::assertNotFalse(condition: $json, message: 'Example set must be readable: ' . basename($file));
			$data = json_decode(json: $json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

			foreach (($data['x-openregister']['seedData']['objects'] ?? []) as $slug => $objects) {
				foreach ($objects as $object) {
					// Sets overlap by design: a person can belong to a council and
					// a company board alike, so de-duplicate on the slug that
					// OpenRegister's own idempotency check uses.
					$merged[$slug][($object['slug'] ?? spl_object_hash((object)$object))] = $object;
				}
			}
		}

		return array_map(static fn (array $bySlug): array => array_values($bySlug), $merged);

	}//end profileSeeds()

	/**
	 * Every schema slug the code asks OpenRegister for must actually exist.
	 *
	 * 🔴 A ONE-CHARACTER SLUG TYPO DISABLED AN ENTIRE BACKFILL, SILENTLY.
	 *
	 * `GovernanceRoleScopeProjector::reconcileAll()` called
	 * `setSchema('governancebody')`. The register declares `governance-body`,
	 * hyphenated, like every other slug in this app. `findAll()` therefore threw,
	 * and its ONLY caller is a repair step that catches `\Throwable` and
	 * downgrades it to a warning — correctly, because a repair must never fail an
	 * upgrade. So every upgrade this app has ever run printed
	 *
	 *   Governance RBAC scope backfill skipped: Schema slug "governancebody" is
	 *   not carried by register "decidiq"
	 *
	 * and REQ-RBAC-001 never projected a single body. Nothing failed. Nothing was
	 * done.
	 *
	 * A `setSchema()` argument is a runtime lookup against a name the register
	 * owns, so the two can drift with nothing to notice. This compares them.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-rbac-001-governance-body-roles-project-into-openregister-rbac-scopes
	 */
	public function testEverySchemaSlugTheCodeAsksForIsDeclared(): void {
		$declared = [];
		$files = array_merge(
			[__DIR__ . '/../../lib/Settings/decidesk_register.json'],
			(glob(__DIR__ . '/../../lib/Settings/register.d/*.json') ?: [])
		);
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			foreach (($data['components']['schemas'] ?? []) as $name => $schema) {
				if (is_array($schema) === false) {
					continue;
				}

				// A schema declares its slug; when it does not, OpenRegister derives
				// one by kebab-casing the definition key.
				$slug = ($schema['slug'] ?? strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name)));
				$declared[$slug] = true;
			}
		}

		self::assertNotEmpty(actual: $declared, message: 'The register must declare schemas');

		$asked = [];
		$phpFiles = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(__DIR__ . '/../../lib')
		);
		foreach ($phpFiles as $phpFile) {
			if ($phpFile->isFile() === false || $phpFile->getExtension() !== 'php') {
				continue;
			}

			$body = (string)file_get_contents($phpFile->getPathname());
			if (preg_match_all("/setSchema\(\s*'([a-zA-Z0-9_-]+)'\s*\)/", $body, $matches) === 0) {
				continue;
			}

			foreach ($matches[1] as $slug) {
				$asked[$slug][] = basename($phpFile->getPathname());
			}
		}

		self::assertNotEmpty(actual: $asked, message: 'Some code must ask for a schema by slug');

		$undeclared = [];
		foreach ($asked as $slug => $callers) {
			if (isset($declared[$slug]) === false) {
				$undeclared[] = $slug . ' (asked for in ' . implode(', ', array_unique($callers)) . ')';
			}
		}

		self::assertSame(
			expected: [],
			actual: $undeclared,
			message: 'setSchema() names a slug the register does not declare, so the lookup throws at runtime'
		);

	}//end testEverySchemaSlugTheCodeAsksForIsDeclared()

	/**
	 * Test that the register JSON is valid OpenAPI 3.0.0 with x-openregister extensions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
	 */
	public function testRegisterIsValidOpenApi(): void {
		self::assertSame(expected: '3.0.0', actual: $this->register['openapi']);
		self::assertArrayHasKey(key: 'x-openregister', array: $this->register);
		// PINNED TO THE REGISTER SLUG, and NOT to Application::APP_ID — which
		// now happen to read the same, and must not be conflated.
		//
		// Measured, not assumed: with this field alone set to the new app id,
		// the seeded Goal objects stopped appearing on the Goals index —
		// 'Goals: index lists all five seeded goals' failed twice in a row,
		// then passed as soon as the field went back to 'decidesk', with
		// nothing else changed.
		//
		// The mechanism is now known rather than inferred: for a
		// `type: application` configuration,
		// ImportHandler::autoCreateRegisterIfApplication() reads
		// `$slug = $xOpenregister['app'] ?? $appId` — this field IS the register
		// slug. So it is load-bearing at IMPORT time, not the descriptive
		// metadata it looks like, and moving it ALONE points the import at a
		// register that does not exist yet.
		//
		// It moves here because the slug moves with it, and because
		// MigrateRegisterSlug renames the existing register row first, so the
		// import updates that row rather than forking a second, empty one.
		// Asserting the literal rather than Application::APP_ID is deliberate:
		// pinning it to the constant would re-break this the next time an app
		// id moves without its register.
		self::assertSame(expected: 'decidiq', actual: $this->register['x-openregister']['app']);
		self::assertSame(expected: 'application', actual: $this->register['x-openregister']['type']);

	}//end testRegisterIsValidOpenApi()

	/**
	 * Test that all required schemas are defined.
	 *
	 * ADR-005 unified Motion / Amendment / Resolution into the Decision
	 * supertype and retire-board-portal removed the 8 board-portal schemas.
	 * decision-route-and-stages added DecisionStage; popolo-decision-makers
	 * added Person, Membership, Post and ContactDetail; the publication +
	 * transcript work added ConsultationReaction, Transcript, PublicationPayload
	 * and PublicationRecord. board-self-evaluation added EvaluationTemplate,
	 * BoardEvaluation and EvaluationResponse. The register now defines
	 * exactly 37 schemas.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-unify-decision-supertype/proposal.md
	 * @spec openspec/changes/archive/2026-06-14-decision-route-and-stages/proposal.md
	 * @spec openspec/changes/archive/2026-06-14-popolo-decision-makers/proposal.md
	 */
	public function testAllSchemasExist(): void {
		$expected = [
			// Meeting + governance core.
			'GovernanceBody',
			'Meeting',
			'Participant',
			'AgendaItem',
			'VotingRound',
			'Vote',
			'ActionItem',
			'Minutes',
			'Transcript',
			// Unified decision supertype (ADR-005) + route stages.
			'Decision',
			'DecisionStage',
			// Popolo decision-makers.
			'Person',
			'Membership',
			'Post',
			'ContactDetail',
			// Document / commerce supporting schemas.
			'DigitalDocument',
			'MonetaryAmount',
			'Offer',
			'Order',
			'Report',
			// Citizen-participation schemas.
			'BudgetProposal',
			'CitizenPanel',
			'CitizenVote',
			'ConsultationReaction',
			'Deliberation',
			'ParticipatoryBudget',
			'PublicConsultation',
			// Notification + preferences.
			'Notification',
			'NotificationPreference',
			// Analytics-leaf migration schema.
			'EngagementRecord',
			// Governance support.
			'ConflictOfInterest',
			// Publication pipeline (publish-decisions-via-opencatalogi).
			'PublicationPayload',
			'PublicationRecord',
			// Board self-evaluation (board-self-evaluation).
			'EvaluationTemplate',
			'BoardEvaluation',
			'EvaluationResponse',
			// Rows the services already wrote but the register never declared:
			// ProxyVoteService persisted proxies (it pointed at 'vote', whose
			// required value/castAt made every /api/proxies register 422) and
			// GovernanceReportingService persisted annual reports (its
			// 'governance-report' write threw "Schema not found", was swallowed,
			// and left /api/governance-reports/{id}/export unaddressable).
			'BoardProxy',
			'GovernanceReport',
		];

		self::assertCount(
			expectedCount: 38,
			haystack: $this->schemas,
			message: 'Register must contain exactly 38 schemas (unified Decision supertype model, board portal retired, board-self-evaluation added, BoardProxy + GovernanceReport declared for the services that already write them, Product retired to pipelinq)'
		);

		foreach ($expected as $name) {
			self::assertArrayHasKey(key: $name, array: $this->schemas, message: "Schema '{$name}' must exist");
		}

		// The board portal and standalone motion/amendment/resolution schemas
		// were removed by ADR-005 + retire-board-portal — assert they are gone.
		foreach (['Motion', 'Amendment', 'Resolution', 'Board', 'BoardMember', 'BoardMeeting', 'BoardVote', 'BoardMaterial'] as $removed) {
			self::assertArrayNotHasKey(
				key: $removed,
				array: $this->schemas,
				message: "Schema '{$removed}' must have been removed by the decision-supertype refactor"
			);
		}

	}//end testAllSchemasExist()

	/**
	 * Test schema.org type annotations are present on the core schemas.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
	 */
	public function testSchemaOrgTypeAnnotations(): void {
		$expectedTypes = [
			'GovernanceBody' => 'schema:Organization',
			'Meeting' => 'schema:Event',
			'Participant' => 'schema:Person',
			'AgendaItem' => 'custom:AgendaItem',
			'VotingRound' => 'custom:VotingRound',
			'Vote' => 'custom:Vote',
			'Decision' => 'custom:Decision',
			'DecisionStage' => 'custom:DecisionStage',
			'ActionItem' => 'custom:ActionItem',
			'Minutes' => 'custom:Minutes',
			'Person' => 'foaf:Person',
			'Membership' => 'org:Membership',
			'Post' => 'org:Post',
			'ContactDetail' => 'popolo:ContactDetail',
			'DigitalDocument' => 'schema:DigitalDocument',
			'MonetaryAmount' => 'schema:MonetaryAmount',
			'Offer' => 'schema:Offer',
			'Order' => 'schema:Order',
			'Report' => 'schema:Report',
		];

		foreach ($expectedTypes as $name => $type) {
			$schemaType = ($this->schemas[$name]['x-openregister']['schemaType'] ?? null);
			self::assertSame(
				expected: $type,
				actual: $schemaType,
				message: "Schema '{$name}' must have schemaType '{$type}'"
			);
		}

	}//end testSchemaOrgTypeAnnotations()

	/**
	 * Test GovernanceBody schema has correct required fields and bodyType enum.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
	 */
	public function testGovernanceBodySchema(): void {
		$schema = $this->schemas['GovernanceBody'];
		self::assertSame(expected: ['name', 'bodyType', 'domain'], actual: $schema['required']);

		$bodyTypeEnum = $schema['properties']['bodyType']['enum'];
		self::assertContains(needle: 'legislative', haystack: $bodyTypeEnum);
		self::assertContains(needle: 'association', haystack: $bodyTypeEnum);
		self::assertContains(needle: 'corporate-board', haystack: $bodyTypeEnum);
		self::assertContains(needle: 'operational', haystack: $bodyTypeEnum);
		self::assertContains(needle: 'citizen-panel', haystack: $bodyTypeEnum);
		// The retire-board-portal change folded the board concept into
		// governance bodies, adding the supervisory-board and executive-board
		// body types.
		self::assertContains(needle: 'supervisory-board', haystack: $bodyTypeEnum);
		self::assertContains(needle: 'executive-board', haystack: $bodyTypeEnum);
		// The batch-2 declarative cores added the advisory-body, works-council
		// and shared-body types (advisory-opinion-workflow,
		// works-council-consultation, shared-governance-bodies).
		self::assertContains(needle: 'advisory-body', haystack: $bodyTypeEnum);
		self::assertContains(needle: 'works-council', haystack: $bodyTypeEnum);
		self::assertContains(needle: 'shared-body', haystack: $bodyTypeEnum);
		// organisation-facet-composition models factions as a bodyType
		// discriminator (ADR-006) instead of the superseded parallel
		// Fractie schema set.
		self::assertContains(needle: 'faction', haystack: $bodyTypeEnum);
		self::assertCount(expectedCount: 11, haystack: $bodyTypeEnum);

	}//end testGovernanceBodySchema()

	/**
	 * Test Meeting schema has correct lifecycle enum values.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
	 */
	public function testMeetingLifecycleEnum(): void {
		$lifecycle = $this->schemas['Meeting']['properties']['lifecycle']['enum'];
		$expected = ['draft', 'scheduled', 'opened', 'paused', 'adjourned', 'closed'];
		self::assertSame(expected: $expected, actual: $lifecycle);

	}//end testMeetingLifecycleEnum()

	/**
	 * Test the meeting-agenda-gaps-v1 additive schema properties: Schema.org
	 * eventAttendanceMode / virtualLocation annotations on Meeting, the
	 * general_assembly meetingType, the seriesPattern object and AgendaItem
	 * parentItem. (The BoardMeeting notice-period assertions were dropped when
	 * retire-board-portal removed the BoardMeeting schema.)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/meeting-management/spec.md
	 */
	public function testMeetingAgendaGapsAdditiveProperties(): void {
		$meeting = $this->schemas['Meeting']['properties'];

		$attendance = $meeting['eventAttendanceMode'];
		self::assertSame(
			expected: [
				'schema:OfflineEventAttendanceMode',
				'schema:OnlineEventAttendanceMode',
				'schema:MixedEventAttendanceMode',
			],
			actual: $attendance['enum']
		);
		self::assertSame(
			expected: 'schema:eventAttendanceMode',
			actual: ($attendance['x-openregister']['schemaType'] ?? null)
		);

		$virtualLocation = $meeting['virtualLocation'];
		self::assertSame(expected: 'uri', actual: $virtualLocation['format']);
		self::assertSame(
			expected: 'schema:VirtualLocation',
			actual: ($virtualLocation['x-openregister']['schemaType'] ?? null)
		);

		self::assertContains(needle: 'general_assembly', haystack: $meeting['meetingType']['enum']);
		self::assertSame(expected: 'object', actual: $meeting['seriesPattern']['type']);
		self::assertSame(
			expected: ['daily', 'weekly', 'monthly'],
			actual: $meeting['seriesPattern']['properties']['frequency']['enum']
		);

		self::assertSame(
			expected: 'string',
			actual: $this->schemas['AgendaItem']['properties']['parentItem']['type']
		);

	}//end testMeetingAgendaGapsAdditiveProperties()

	/**
	 * Test the unified Decision supertype carries the decisionType discriminator
	 * (ADR-005) covering the former Motion / Amendment / Resolution kinds plus
	 * the corporate decision kinds, and the lifecycle / outcome enums.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-unify-decision-supertype/specs/decision-management/spec.md
	 */
	public function testDecisionSupertypeSchema(): void {
		$schema = $this->schemas['Decision'];

		// `decisionDate` and `outcome` are deliberately NOT here. They are
		// required only in terminal states (decided/enacted/archived); an
		// in-flight motion has neither, and listing them unconditionally made
		// OpenRegister refuse to create one at all
		// ("400 The required properties (decisionDate, outcome) are missing").
		// The completeness rule is enforced at the transition boundary instead —
		// see DecisionTransitionGuard::getMissingTerminalFields().
		self::assertSame(
			expected: ['title', 'text', 'decisionType'],
			actual: $schema['required']
		);

		// The relaxation must stay documented on the schema itself, so a future
		// reader does not "restore" the unconditional requirement.
		self::assertArrayHasKey(key: 'x-decidesk-terminal-completeness', array: $schema);

		// The decisionType discriminator folds in the former standalone
		// schemas, but its vocabulary is CONFIGURATION, not a schema enum
		// (decision-types-as-configuration): valid values live in the
		// decision_types app setting, and DecisionTypeRegistry seeds and
		// enforces them. The declaration stays a free string on purpose.
		$decisionType = $schema['properties']['decisionType'];
		self::assertSame(expected: 'string', actual: $decisionType['type']);
		self::assertArrayNotHasKey(key: 'enum', array: $decisionType);
		self::assertContains(needle: 'motion', haystack: DecisionTypeRegistry::DEFAULT_TYPES);
		self::assertContains(needle: 'amendment', haystack: DecisionTypeRegistry::DEFAULT_TYPES);
		self::assertContains(needle: 'resolution', haystack: DecisionTypeRegistry::DEFAULT_TYPES);

		$lifecycle = $schema['properties']['lifecycle']['enum'];
		self::assertContains(needle: 'draft', haystack: $lifecycle);
		self::assertContains(needle: 'voting', haystack: $lifecycle);
		self::assertContains(needle: 'decided', haystack: $lifecycle);
		self::assertContains(needle: 'withdrawn', haystack: $lifecycle);

		self::assertSame(expected: 'array', actual: $schema['properties']['coSigners']['type']);

	}//end testDecisionSupertypeSchema()

	/**
	 * Test VotingRound has votingMethod enum and isSecret boolean.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
	 */
	public function testVotingRoundSchema(): void {
		$schema = $this->schemas['VotingRound'];
		self::assertSame(expected: ['votingMethod', 'isSecret'], actual: $schema['required']);

		$methods = $schema['properties']['votingMethod']['enum'];
		self::assertContains(needle: 'for-against-abstain', haystack: $methods);
		self::assertContains(needle: 'ranked-choice', haystack: $methods);
		self::assertContains(needle: 'weighted', haystack: $methods);
		self::assertContains(needle: 'show-of-hands', haystack: $methods);

		self::assertSame(expected: 'boolean', actual: $schema['properties']['isSecret']['type']);

	}//end testVotingRoundSchema()

	/**
	 * Test Decision schema has mailEnabled for email-to-decision linking.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
	 */
	public function testDecisionMailEnabled(): void {
		$schema = $this->schemas['Decision'];
		self::assertTrue(
			condition: $schema['x-openregister']['mailEnabled'],
			message: 'Decision schema must have mailEnabled=true for _mail metadata'
		);

		self::assertSame(expected: ['adopted', 'rejected'], actual: $schema['properties']['outcome']['enum']);
		// P3-citizen-participation: isPublished is a string enum (internal | public | confidential)
		// controlling citizen transparency portal visibility, not a boolean ORI publish flag.
		self::assertSame(expected: 'string', actual: $schema['properties']['isPublished']['type']);
		self::assertSame(
			expected: ['internal', 'public', 'confidential'],
			actual: $schema['properties']['isPublished']['enum']
		);

	}//end testDecisionMailEnabled()

	/**
	 * Test that core schemas have seed data with @self envelopes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-2
	 */
	public function testSeedDataPresent(): void {
		// Seed data lives under the top-level `x-openregister.seedData.objects` map, keyed by
		// schema SLUG. This is the only location OpenRegister's ImportHandler::importSeedData()
		// reads. The former `x-openregister-seeds` schema annotation was out-of-vocabulary, so
		// Schema::setConfiguration() dropped it and no seed ever planted (fix-inert-seeds).
		$coreSchemaSlugs = [
			'governance-body',
			'meeting',
			'participant',
			'agenda-item',
			'decision',
			'decision-stage',
			'voting-round',
			'vote',
			'action-item',
			'minutes',
		];

		$seedObjects = $this->profileSeeds();
		self::assertNotEmpty(
			actual: $seedObjects,
			message: 'The example sets must declare x-openregister.seedData.objects'
		);

		foreach ($coreSchemaSlugs as $slug) {
			$seeds = ($seedObjects[$slug] ?? []);
			self::assertGreaterThanOrEqual(
				expected: 3,
				actual: count($seeds),
				message: "Schema '{$slug}' must have at least 3 seed objects"
			);

			foreach ($seeds as $seed) {
				// 🔴 THIS ASSERTION USED TO SAY THE OPPOSITE, AND THE INVERSION IS
				// THE WHOLE MECHANISM OF THE seed-profiles CHANGE.
				//
				// While the seeds lived in the app's own configuration, the
				// importer took register + schema from the import context, so an
				// @self envelope was redundant and the test forbade it.
				//
				// An example set declares NO `components.registers`, deliberately:
				// ImportHandler::importRegister() calls setApplication($appId)
				// unconditionally when it updates an existing register, so a
				// profile that declared `decidiq` would re-point the register at
				// that profile's config id and hydrate over its `authorization`
				// baseline. With no register in the descriptor there is no import
				// context to inherit, and importSeedDataObjects() would fall back
				// to "register 0" and drop every object. @self is what resolves
				// each object instead, and importSeedDataObjects() only consults
				// `@self.register` / `@self.schema` when `@self.configuration` is
				// also present, so all three are required rather than decorative.
				//
				// Verified on a live instance: this shape imported the objects and
				// left application=decidiq, the version and the authorization block
				// byte-identical.
				self::assertArrayHasKey(
					key: '@self',
					array: $seed,
					message: "Seed in '{$slug}' must carry an @self envelope so it resolves its own register"
				);
				self::assertSame(
					expected: ['configuration' => 'decidiq', 'register' => 'decidiq', 'schema' => $slug],
					actual: $seed['@self'],
					message: "Seed in '{$slug}' must resolve the decidiq register and its own schema"
				);
				self::assertNotEmpty(
					actual: ($seed['slug'] ?? ''),
					message: "Seed in '{$slug}' must have a non-empty slug"
				);
			}//end foreach
		}//end foreach

	}//end testSeedDataPresent()

	/**
	 * The shipped decision seeds obey the rule the schema can no longer state.
	 *
	 * Relaxing `required[]` is what lets an in-flight motion exist; it must not
	 * become a licence to ship a decided decision with no result. Every seed in
	 * a terminal outcome state carries `outcome` (from the schema enum) and
	 * `decisionDate`; every in-flight seed carries neither, so the demo data
	 * actually demonstrates the case that used to be refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/decision-management/spec.md
	 */
	public function testDecisionSeedsRespectTerminalCompleteness(): void {
		$seeds = ($this->profileSeeds()['decision'] ?? []);
		self::assertNotEmpty(actual: $seeds, message: 'The example sets must seed decision objects');

		$terminalStates = ['decided', 'enacted', 'archived'];
		$outcomeEnum = $this->schemas['Decision']['properties']['outcome']['enum'];

		$inFlightSeen = 0;
		$terminalSeen = 0;

		foreach ($seeds as $seed) {
			$slug = ($seed['slug'] ?? '?');
			$lifecycle = ($seed['lifecycle'] ?? null);

			if ($lifecycle !== null && in_array($lifecycle, $terminalStates, true) === true) {
				$terminalSeen++;
				self::assertContains(
					needle: ($seed['outcome'] ?? null),
					haystack: $outcomeEnum,
					message: "Terminal seed '{$slug}' (lifecycle {$lifecycle}) must record an outcome from the schema enum"
				);
				self::assertNotEmpty(
					actual: ($seed['decisionDate'] ?? ''),
					message: "Terminal seed '{$slug}' (lifecycle {$lifecycle}) must record a decisionDate"
				);
				continue;
			}

			if ($lifecycle === null) {
				// Seeds that never declare a lifecycle are not asserted either way.
				continue;
			}

			$inFlightSeen++;
			self::assertArrayNotHasKey(
				key: 'outcome',
				array: $seed,
				message: "In-flight seed '{$slug}' (lifecycle {$lifecycle}) must NOT carry an outcome — "
					. 'lifecycle and outcome are orthogonal (ADR-005) and there is no placeholder value in the enum'
			);
			self::assertArrayNotHasKey(
				key: 'decisionDate',
				array: $seed,
				message: "In-flight seed '{$slug}' (lifecycle {$lifecycle}) must NOT carry a decisionDate"
			);
		}//end foreach

		self::assertGreaterThan(
			expected: 0,
			actual: $terminalSeen,
			message: 'The seeds must include at least one terminal decision'
		);
		self::assertGreaterThan(
			expected: 0,
			actual: $inFlightSeen,
			message: 'The seeds must include at least one IN-FLIGHT decision — that is the case this change unblocks, '
				. 'and without one the register would never exercise it'
		);

	}//end testDecisionSeedsRespectTerminalCompleteness()

	/**
	 * Guard the phantom: the out-of-vocabulary seed annotation must never come back.
	 *
	 * `x-openregister-seeds` (and its singular `x-openregister-seed`) are absent from
	 * OpenRegister's Schema::ANNOTATION_VOCABULARY, so setConfiguration() drops them
	 * silently — declarations survive review while planting nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fix-inert-seeds/specs/register-seed-data/spec.md
	 */
	public function testNoOutOfVocabularySeedAnnotation(): void {
		$encoded = json_encode($this->register);

		self::assertStringNotContainsString(
			needle: 'x-openregister-seeds',
			haystack: $encoded,
			message: 'x-openregister-seeds is not in OpenRegister ANNOTATION_VOCABULARY and is dropped silently'
		);
		self::assertStringNotContainsString(
			needle: 'x-openregister-seed"',
			haystack: $encoded,
			message: 'x-openregister-seed is not in OpenRegister ANNOTATION_VOCABULARY and is dropped silently'
		);

	}//end testNoOutOfVocabularySeedAnnotation()

	/**
	 * Test that relations are declared with x-openregister-relations.
	 *
	 * ADR-005 + decision-route-and-stages: agenda items, voting rounds and
	 * stages now hang off the unified Decision / DecisionStage schemas rather
	 * than the removed Motion / Amendment schemas.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-decision-route-and-stages/proposal.md
	 */
	public function testRelationsAreConfigured(): void {
		$expectedRelations = [
			'Meeting' => ['governanceBody'],
			'Participant' => ['governanceBody'],
			'AgendaItem' => ['meeting'],
			'VotingRound' => ['decisionStage'],
			'Vote' => ['votingRound', 'participant'],
			'Decision' => ['route', 'amends'],
			'DecisionStage' => ['decision', 'votingRound'],
			'ActionItem' => ['decision', 'meeting'],
			'Minutes' => ['meeting'],
		];

		// The canonical relation dialect is a property-level `$ref` (ADR-062 rule 7); the bespoke
		// per-schema `x-openregister-relations` block was retired 2026-07-08. This test asserted the
		// retired block, so it went red when the core schemas were migrated and stayed red — while
		// the two schemas that still carried the retired block (BoardEvaluation, EvaluationResponse)
		// declared relations no engine read and materialised no property to hold them.
		foreach ($expectedRelations as $schemaName => $relations) {
			$properties = ($this->schemas[$schemaName]['properties'] ?? []);
			foreach ($relations as $relation) {
				self::assertArrayHasKey(
					key: $relation,
					array: $properties,
					message: "Schema '{$schemaName}' must materialise relation '{$relation}' as a property"
				);
				// To-one relations carry `$ref` on the property; to-many relations are an
				// array whose `items` carries the `$ref` (e.g. Decision.route).
				$ref = ($properties[$relation]['$ref'] ?? ($properties[$relation]['items']['$ref'] ?? ''));
				self::assertNotEmpty(
					actual: $ref,
					message: "Relation '{$relation}' on '{$schemaName}' must be a property-level \$ref "
						. '(or items.$ref for to-many) per ADR-062 rule 7'
				);
			}//end foreach
		}//end foreach

		// Guard the retired dialect: it is inert, so a reintroduced block is a silent no-op.
		foreach ($this->schemas as $schemaName => $schema) {
			self::assertArrayNotHasKey(
				key: 'x-openregister-relations',
				array: $schema,
				message: "Schema '{$schemaName}' uses the retired x-openregister-relations dialect (ADR-062 rule 7)"
			);
		}

		// ADR-005: no surviving relation may point at a removed schema.
		foreach ($this->schemas as $schemaName => $schema) {
			foreach (($schema['x-openregister-relations'] ?? []) as $relName => $rel) {
				self::assertNotContains(
					needle: ($rel['schema'] ?? ''),
					haystack: ['Motion', 'Amendment', 'Resolution'],
					message: "Relation '{$relName}' on '{$schemaName}' must not point at a removed schema"
				);
			}
		}

	}//end testRelationsAreConfigured()

	/**
	 * Test that each schema has a slug and version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
	 */
	public function testSchemasHaveSlugsAndVersions(): void {
		foreach ($this->schemas as $name => $schema) {
			self::assertNotEmpty(
				actual: ($schema['slug'] ?? ''),
				message: "Schema '{$name}' must have a slug"
			);
			self::assertNotEmpty(
				actual: ($schema['version'] ?? ''),
				message: "Schema '{$name}' must have a version"
			);
			self::assertSame(
				expected: 'object',
				actual: ($schema['type'] ?? ''),
				message: "Schema '{$name}' must be type 'object'"
			);
		}//end foreach

	}//end testSchemasHaveSlugsAndVersions()

	/**
	 * Test Participant has role enum with all required values.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
	 */
	public function testParticipantRoleEnum(): void {
		$roles = $this->schemas['Participant']['properties']['role']['enum'];
		$expected = ['chair', 'vice-chair', 'secretary', 'member', 'observer', 'guest'];
		self::assertSame(expected: $expected, actual: $roles);

	}//end testParticipantRoleEnum()

	/**
	 * Test that EngagementRecord schema is defined with correct structure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-2.2
	 */
	public function testEngagementRecordSchemaExists(): void {
		$schema = $this->schemas['EngagementRecord'];

		self::assertSame(expected: 'engagement-record', actual: $schema['slug']);
		self::assertSame(expected: 'custom:EngagementRecord', actual: $schema['x-openregister']['schemaType']);
		self::assertContains(needle: 'meeting', haystack: $schema['required']);
		self::assertContains(needle: 'participant', haystack: $schema['required']);

		// Derived counts are exposed to the analytics leaf as calculations.
		$calcs = ($schema['x-openregister-calculations'] ?? []);
		self::assertArrayHasKey(key: 'speechCount', array: $calcs, message: 'speechCount calculation must exist');
		self::assertArrayHasKey(key: 'questionCount', array: $calcs, message: 'questionCount calculation must exist');
		self::assertArrayHasKey(key: 'topicCount', array: $calcs, message: 'topicCount calculation must exist');

		// Relations to Meeting and Participant must be declared as canonical property-level
		// $refs (ADR-062 rule 7), not the retired x-openregister-relations block.
		self::assertNotEmpty(
			actual: ($schema['properties']['meeting']['$ref'] ?? ''),
			message: 'EngagementRecord.meeting must be a property-level $ref'
		);
		self::assertNotEmpty(
			actual: ($schema['properties']['participant']['$ref'] ?? ''),
			message: 'EngagementRecord.participant must be a property-level $ref'
		);

	}//end testEngagementRecordSchemaExists()

	/**
	 * Every declared notification trigger must use OpenRegister's canonical vocabulary.
	 *
	 * OpenRegister's NotificationAnnotationValidator::VALID_TRIGGERS accepts only
	 * created | updated | transition | scheduled | threshold | calculatedChange. A drifted
	 * value (e.g. 'create' for 'created') matches no dispatch branch, so the notification is
	 * inert — declared, reviewed, and never firing. Found live on ConsultationReaction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/done-spec-fixes/specs/declarative-dialect-integrity/spec.md
	 */
	public function testNotificationTriggersUseCanonicalVocabulary(): void {
		$validTriggers = ['created', 'updated', 'transition', 'scheduled', 'threshold', 'calculatedChange'];

		foreach ($this->schemas as $schemaName => $schema) {
			$notifications = ($schema['x-openregister-notifications'] ?? []);
			foreach ($notifications as $notificationName => $spec) {
				$triggerType = ($spec['trigger']['type'] ?? '');
				self::assertContains(
					needle: $triggerType,
					haystack: $validTriggers,
					message: "Notification '{$notificationName}' on '{$schemaName}' declares trigger.type "
						. "'{$triggerType}', which is not in OpenRegister's VALID_TRIGGERS — it can never fire"
				);
			}
		}

	}//end testNotificationTriggersUseCanonicalVocabulary()

	/**
	 * Test that Meeting schema aggregations include action-item and engagement metrics.
	 *
	 * These aggregations power the analytics integration leaf (ADR-019, ADR-031),
	 * replacing the in-app ActionItemAnalyticsService dashboard methods.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-2.2
	 */
	public function testMeetingSchemaHasAnalyticsLeafAggregations(): void {
		$aggs = ($this->schemas['Meeting']['x-openregister-aggregations'] ?? []);

		self::assertArrayHasKey(
			key: 'actionItemCount',
			array: $aggs,
			message: 'Meeting must have actionItemCount aggregation for analytics leaf'
		);
		self::assertArrayHasKey(
			key: 'completedActionItemCount',
			array: $aggs,
			message: 'Meeting must have completedActionItemCount aggregation for analytics leaf'
		);
		self::assertArrayHasKey(
			key: 'engagementRecordCount',
			array: $aggs,
			message: 'Meeting must have engagementRecordCount aggregation for analytics leaf'
		);

		// ActionItemCompletionRate is derived from actionItemCount + completedActionItemCount.
		$calcs = ($this->schemas['Meeting']['x-openregister-calculations'] ?? []);
		self::assertArrayHasKey(
			key: 'actionItemCompletionRate',
			array: $calcs,
			message: 'Meeting must have actionItemCompletionRate calculation for analytics leaf'
		);

	}//end testMeetingSchemaHasAnalyticsLeafAggregations()

	/**
	 * Test that EngagementRecord has at least 3 seed objects.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-engagement-analytics-to-analytics-leaf/tasks.md#task-2.2
	 */
	public function testEngagementRecordHasSeedData(): void {
		// Keyed by schema slug under the seedData map — the location the importer reads.
		$seeds = ($this->profileSeeds()['engagement-record'] ?? []);
		self::assertGreaterThanOrEqual(
			expected: 3,
			actual: count($seeds),
			message: 'EngagementRecord must have at least 3 seed objects'
		);

		foreach ($seeds as $seed) {
			self::assertNotEmpty(
				actual: ($seed['slug'] ?? ''),
				message: 'EngagementRecord seed must have a non-empty slug'
			);
		}

	}//end testEngagementRecordHasSeedData()


	/**
	 * Test that every cross-schema `$ref` names a slug that actually exists.
	 *
	 * 🔴 A `$ref` NAMES A SLUG, NOT THE DEFINITION KEY. `ImportHandler` resolves
	 * a property's `$ref` through two maps, and BOTH are keyed by slug
	 * (`$this->schemasMap[$schema->getSlug()] = $schema`). When neither matches
	 * there is no `else` branch: the raw string is left in the stored property
	 * and the reference silently points at nothing. At runtime
	 * `SchemaMapper::find()` matches `uuid`, `LOWER(slug)` or a numeric `id` —
	 * never `title` — so a definition key that is not also the slug resolves to
	 * no schema and the relation endpoint 404s.
	 *
	 * This bites ONLY where the key and the slug differ, which is why it hid for
	 * so long: a one-word key lowercases onto its own slug (`Person` ->
	 * `person`) and works by coincidence, so 127 of this register's 269
	 * references were always fine while 142 — every multi-word one, `GovernanceBody`
	 * alone 69 times — were dead. The same defect is documented upstream in
	 * `generate_mock_register.py` ("THE SLUG, NOT THE DEFINITION KEY"), which
	 * measured larpinq referencing `event`/`item` whose real slugs are
	 * `larping_event`/`larping_item`.
	 *
	 * `testRelationsAreConfigured()` could not catch this: it asserts a relation's
	 * `$ref` is NOT EMPTY, which a dead reference satisfies perfectly. Existence
	 * is not resolution, so this test resolves every one of them.
	 *
	 * Covers the whole shipped set — the base register, the `register.d`
	 * fragments and the generated mock — because the importer reads them all and
	 * a reference is equally dead in any of them.
	 *
	 * @return void
	 *
	 * @spec exclude Register-integrity guard; asserts a shipped JSON invariant, no behavioural spec.
	 */
	public function testEverySchemaRefResolvesToADeclaredSlug(): void {
		$settingsDir = __DIR__ . '/../../lib/Settings';
		$files       = array_merge(
			[$settingsDir . '/decidesk_register.json', $settingsDir . '/decidiq_mock_register.json'],
			(glob($settingsDir . '/register.d/*.json') ?: [])
		);

		// Collect every slug the shipped registers declare.
		$slugs = [];
		$docs  = [];
		foreach ($files as $file) {
			if (file_exists($file) === false) {
				continue;
			}

			$decoded     = json_decode(
				json: (string)file_get_contents(filename: $file),
				associative: true,
				depth: 512,
				flags: JSON_THROW_ON_ERROR
			);
			$docs[$file] = $decoded;

			foreach (($decoded['components']['schemas'] ?? []) as $schema) {
				if (is_array($schema) === true && empty($schema['slug']) === false) {
					$slugs[strtolower((string)$schema['slug'])] = true;
				}
			}
		}//end foreach

		self::assertNotEmpty(actual: $slugs, message: 'The register set must declare at least one schema slug');

		// Every non-internal `$ref` must name one of them.
		$dangling = [];
		foreach ($docs as $file => $doc) {
			foreach ($this->collectRefs(node: $doc) as $ref) {
				// `#/...` is an intra-document JSON pointer, not an OpenRegister schema reference.
				if (str_starts_with($ref, '#') === true) {
					continue;
				}

				if (isset($slugs[strtolower($ref)]) === false) {
					$dangling[] = basename($file) . ': $ref "' . $ref . '"';
				}
			}
		}

		self::assertSame(
			expected: [],
			actual: array_values(array_unique($dangling)),
			message: 'Every cross-schema $ref must name a declared schema slug, not the definition key — '
				. 'an unresolved $ref is left verbatim by ImportHandler and 404s at runtime'
		);

	}//end testEverySchemaRefResolvesToADeclaredSlug()


	/**
	 * Recursively collect every `$ref` string value in a decoded register.
	 *
	 * @param mixed $node The node to walk.
	 *
	 * @return array<int,string> The `$ref` values found, in document order.
	 *
	 * @spec exclude Test helper.
	 */
	private function collectRefs(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		$found = [];
		foreach ($node as $key => $value) {
			if ($key === '$ref' && is_string($value) === true) {
				$found[] = $value;
				continue;
			}

			$found = array_merge($found, $this->collectRefs(node: $value));
		}

		return $found;

	}//end collectRefs()
}//end class
