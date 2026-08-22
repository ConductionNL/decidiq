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

use PHPUnit\Framework\TestCase;

/**
 * Tests for decidesk_register.json schema definitions.
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
	 * Test that the register JSON is valid OpenAPI 3.0.0 with x-openregister extensions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/p1-schemas-and-data-model/tasks.md#task-1
	 */
	public function testRegisterIsValidOpenApi(): void {
		self::assertSame(expected: '3.0.0', actual: $this->register['openapi']);
		self::assertArrayHasKey(key: 'x-openregister', array: $this->register);
		self::assertSame(expected: 'decidesk', actual: $this->register['x-openregister']['app']);
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
			'Product',
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
			expectedCount: 39,
			haystack: $this->schemas,
			message: 'Register must contain exactly 39 schemas (unified Decision supertype model, board portal retired, board-self-evaluation added, BoardProxy + GovernanceReport declared for the services that already write them)'
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
			'Product' => 'schema:Product',
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

		// The decisionType discriminator folds in the former standalone schemas.
		$decisionType = $schema['properties']['decisionType']['enum'];
		self::assertContains(needle: 'motion', haystack: $decisionType);
		self::assertContains(needle: 'amendment', haystack: $decisionType);
		self::assertContains(needle: 'resolution', haystack: $decisionType);

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

		$seedObjects = ($this->register['x-openregister']['seedData']['objects'] ?? []);
		self::assertNotEmpty(
			actual: $seedObjects,
			message: 'Register must declare x-openregister.seedData.objects'
		);

		foreach ($coreSchemaSlugs as $slug) {
			$seeds = ($seedObjects[$slug] ?? []);
			self::assertGreaterThanOrEqual(
				expected: 3,
				actual: count($seeds),
				message: "Schema '{$slug}' must have at least 3 seed objects"
			);

			foreach ($seeds as $seed) {
				// The importer takes register + schema from the import context, so a seed
				// carries no @self envelope; its identity is a non-empty `slug` property.
				self::assertArrayNotHasKey(
					key: '@self',
					array: $seed,
					message: "Seed in '{$slug}' must not carry an @self envelope under seedData"
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
		$seeds = ($this->register['x-openregister']['seedData']['objects']['decision'] ?? []);
		self::assertNotEmpty(actual: $seeds, message: 'Register must seed decision objects');

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
		$seeds = ($this->register['x-openregister']['seedData']['objects']['engagement-record'] ?? []);
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
}//end class
