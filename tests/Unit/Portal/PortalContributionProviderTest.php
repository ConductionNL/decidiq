<?php

/**
 * Unit tests for the Portaliq portal contribution provider.
 *
 * Pins Decidiq's ADR-046 contract-v2.2 contribution: the dependency-free
 * duck-typed shape (inert without portaliq), the v2 getAudiences() + v1
 * getAudience() pair, the `citizen` read + inbox manifest (scoping map, default
 * subjectRef scoping, minTrust, the inbox `kind`) and the subject-safe field
 * projections. Also pins every scopeField and projected read field against the
 * shipped register JSON at HEAD so a schema drift (renamed scope property,
 * dropped whitelist field) fails here instead of silently scoping portal reads
 * to nothing or dropping a projected column.
 *
 * Subjects use the nil-UUID pattern per the change design.md Seed Data section —
 * self-evidently fake, never colliding with live data. The provider is
 * constructed directly — it is a plain dependency-free class by contract
 * (amendment A1), so no mocks and no container are involved.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Portal;

use OCA\Decidiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pin the declarative portal contribution manifest.
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */
final class PortalContributionProviderTest extends TestCase {

	/**
	 * Server-derived subject fixture for the citizen audience (nil UUIDs).
	 *
	 * @var array<string, mixed>
	 */
	private const CITIZEN_SUBJECT = [
		'subjectRef' => '00000000-0000-0000-0000-000000000001',
		'audience' => 'citizen',
		'organisation' => '00000000-0000-0000-0000-000000000002',
		'trust' => 'low',
	];

	/**
	 * The provider under test (direct construction — no container).
	 *
	 * @var PortalContributionProvider
	 */
	private PortalContributionProvider $provider;

	/**
	 * Construct the provider directly before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new PortalContributionProvider();

	}//end setUp()

	/**
	 * The provider is plain: no parent, no interface, no constructor, and its
	 * source references no portaliq symbol (inert without portaliq, A1).
	 *
	 * @return void
	 */
	public function testProviderIsPlainAndDependencyFree(): void {
		$reflection = new ReflectionClass(PortalContributionProvider::class);

		self::assertFalse(condition: $reflection->getParentClass(), message: 'Provider must not extend any class');
		self::assertSame(expected: [], actual: $reflection->getInterfaceNames(), message: 'Provider must implement no interface');
		self::assertNull(actual: $reflection->getConstructor(), message: 'Provider must declare no constructor');

		// The reflection interface check above already proves there is no
		// implements clause. The docblock legitimately names portaliq in prose;
		// the invariant here is that no *code* references a portaliq symbol —
		// no import and no FQCN.
		$source = (string)file_get_contents(filename: (string)$reflection->getFileName());
		self::assertStringNotContainsString(needle: 'use OCA\\Portaliq', haystack: $source, message: 'Provider must not import a portaliq symbol');
		self::assertStringNotContainsString(needle: 'OCA\\Portaliq\\', haystack: $source, message: 'Provider must not reference a portaliq FQCN');

	}//end testProviderIsPlainAndDependencyFree()

	/**
	 * The getAudiences() (v2) and getAudience() (v1) methods agree on `citizen`.
	 *
	 * @return void
	 */
	public function testAudiencesOnBothContractVersions(): void {
		self::assertSame(expected: ['citizen'], actual: $this->provider->getAudiences());
		self::assertSame(expected: 'citizen', actual: $this->provider->getAudience());
		self::assertContains(needle: $this->provider->getAudience(), haystack: $this->provider->getAudiences());

	}//end testAudiencesOnBothContractVersions()

	/**
	 * Any audience other than `citizen` — including empty — fails closed to null.
	 *
	 * @return void
	 */
	public function testUnknownAudienceYieldsNull(): void {
		self::assertNull(actual: $this->provider->getContribution(['audience' => 'client']));
		self::assertNull(actual: $this->provider->getContribution(['audience' => 'signer']));
		self::assertNull(actual: $this->provider->getContribution(['audience' => '']));
		self::assertNull(actual: $this->provider->getContribution([]));

	}//end testUnknownAudienceYieldsNull()

	/**
	 * The citizen manifest ships the four documented collections, each scoped by
	 * the DEFAULT subjectRef (no scopeClaim), gated `low`, and read-only.
	 *
	 * @return void
	 */
	public function testCitizenManifestShape(): void {
		$manifest = $this->provider->getContribution(self::CITIZEN_SUBJECT);

		self::assertIsArray(actual: $manifest);
		self::assertSame(expected: 'Decidiq', actual: $manifest['label']);
		self::assertCount(expectedCount: 2, haystack: $manifest['actions'], message: 'Exactly createReaction + createBudgetProposal this wave');
		self::assertSame(expected: [], actual: $manifest['notifications'], message: 'No manifest-level notification dispatch this wave');

		$byId = [];
		foreach ($manifest['collections'] as $collection) {
			$byId[$collection['id']] = $collection;
		}

		self::assertSame(
			expected: ['citizenReactions', 'citizenVotes', 'citizenBudgetProposals', 'citizenNotifications'],
			actual: array_keys($byId),
			message: 'Exactly the four documented citizen collections, in order'
		);

		foreach ($byId as $collection) {
			self::assertSame(expected: 'decidesk', actual: $collection['register']);
			self::assertTrue(condition: $collection['listable']);
			self::assertSame(expected: 'low', actual: $collection['minTrust'], message: 'Password edge — DigiD/eHerkenning deferred');
			self::assertArrayNotHasKey(key: 'scopeClaim', array: $collection, message: 'Citizen scopes by the pseudonymous subjectRef, not a claim');
			self::assertArrayNotHasKey(key: 'via', array: $collection, message: 'No via joins this wave');
			self::assertNotSame(expected: '', actual: $collection['scopeField'], message: 'Every citizen collection is per-subject scoped');
		}

	}//end testCitizenManifestShape()

	/**
	 * Each collection scopes by the correct field and projects exactly the
	 * documented subject-safe whitelist (with the forbidden columns dropped).
	 *
	 * @return void
	 */
	public function testCitizenCollectionScopingAndProjection(): void {
		$byId = $this->collectionsById();

		self::assertSame(expected: 'consultation-reaction', actual: $byId['citizenReactions']['schema']);
		self::assertSame(expected: 'submitterId', actual: $byId['citizenReactions']['scopeField']);
		self::assertSame(
			expected: ['body', 'submittedAt', 'moderationStatus', 'voteCount', 'proposalTitle', 'proposalAmount'],
			actual: $byId['citizenReactions']['fields']
		);
		foreach (['moderationReason', 'publicationDate', 'depublicationDate', 'submitterId'] as $forbidden) {
			self::assertNotContains(needle: $forbidden, haystack: $byId['citizenReactions']['fields'], message: "Reactions must drop {$forbidden}");
		}

		self::assertSame(expected: 'citizen-vote', actual: $byId['citizenVotes']['schema']);
		self::assertSame(expected: 'voterId', actual: $byId['citizenVotes']['scopeField']);
		self::assertSame(
			expected: ['voteValue', 'motionId', 'proposalId', 'citizenPanelId', 'weight', 'isProxy', 'castAt', 'notes'],
			actual: $byId['citizenVotes']['fields']
		);

		self::assertSame(expected: 'budget-proposal', actual: $byId['citizenBudgetProposals']['schema']);
		self::assertSame(expected: 'submitter', actual: $byId['citizenBudgetProposals']['scopeField']);
		self::assertSame(
			expected: ['title', 'description', 'requestedAmount', 'category', 'status', 'votesFor', 'votesAgainst'],
			actual: $byId['citizenBudgetProposals']['fields']
		);

		self::assertSame(expected: 'notification', actual: $byId['citizenNotifications']['schema']);
		self::assertSame(expected: 'recipientId', actual: $byId['citizenNotifications']['scopeField']);
		self::assertSame(expected: 'inbox', actual: $byId['citizenNotifications']['kind'], message: 'The notification collection is the inbox');
		self::assertSame(
			expected: ['type', 'subject', 'content', 'channel', 'status', 'sentAt', 'readAt'],
			actual: $byId['citizenNotifications']['fields']
		);

	}//end testCitizenCollectionScopingAndProjection()

	/**
	 * Only `citizenNotifications` carries `kind: inbox`; the read collections do not.
	 *
	 * @return void
	 */
	public function testOnlyNotificationsCollectionIsInbox(): void {
		$byId = $this->collectionsById();

		self::assertArrayNotHasKey(key: 'kind', array: $byId['citizenReactions']);
		self::assertArrayNotHasKey(key: 'kind', array: $byId['citizenVotes']);
		self::assertArrayNotHasKey(key: 'kind', array: $byId['citizenBudgetProposals']);
		self::assertSame(expected: 'inbox', actual: $byId['citizenNotifications']['kind']);

	}//end testOnlyNotificationsCollectionIsInbox()

	/**
	 * The citizen manifest declares exactly `createReaction` and
	 * `createBudgetProposal`, both `type: create`, both `minTrust: low`
	 * (REQ-DKPCA-004).
	 *
	 * @return void
	 */
	public function testCitizenManifestDeclaresExactlyTheTwoCreateActions(): void {
		$actionsById = $this->actionsById();

		self::assertSame(
			expected: ['createReaction', 'createBudgetProposal'],
			actual: array_keys($actionsById),
			message: 'Exactly the two documented citizen create actions, in order'
		);

		foreach ($actionsById as $action) {
			self::assertSame(expected: 'create', actual: $action['type']);
			self::assertSame(expected: 'low', actual: $action['minTrust'], message: 'Account-less participation is the point');
			self::assertSame(expected: 'decidesk', actual: $action['register']);
		}

	}//end testCitizenManifestDeclaresExactlyTheTwoCreateActions()

	/**
	 * `createReaction` (REQ-DKPCA-001): exact client whitelist, scope field +
	 * defaults stamp the intake state, parent constraint requires an open
	 * consultation.
	 *
	 * @return void
	 */
	public function testCreateReactionActionShape(): void {
		$action = $this->actionsById()['createReaction'];

		self::assertSame(expected: 'consultation-reaction', actual: $action['schema']);
		self::assertSame(expected: 'submitterId', actual: $action['scopeField'], message: 'Scope is stamped from subjectRef, never client-writable');
		self::assertSame(expected: ['consultation', 'body'], actual: $action['fields']);
		self::assertSame(expected: 'pending', actual: $action['defaults']['moderationStatus']);
		self::assertArrayHasKey(key: 'submittedAt', array: $action['defaults']);
		self::assertNotSame(expected: '', actual: $action['defaults']['submittedAt']);

		foreach (['submitterId', 'moderationStatus', 'moderationReason', 'publicationDate', 'depublicationDate', 'voteCount'] as $forbidden) {
			self::assertNotContains(needle: $forbidden, haystack: $action['fields'], message: "createReaction must never client-whitelist {$forbidden}");
		}

		self::assertSame(
			expected: [
				'field' => 'consultation',
				'parentSchema' => 'public-consultation',
				'statusField' => 'status',
				'statusValue' => 'open',
			],
			actual: $action['parentConstraint']
		);

	}//end testCreateReactionActionShape()

	/**
	 * `createBudgetProposal` (REQ-DKPCA-002): exact client whitelist, scope
	 * field + defaults stamp the intake state (no `submittedAt` — the schema
	 * has none), parent constraint requires a submission-phase budget round.
	 *
	 * @return void
	 */
	public function testCreateBudgetProposalActionShape(): void {
		$action = $this->actionsById()['createBudgetProposal'];

		self::assertSame(expected: 'budget-proposal', actual: $action['schema']);
		self::assertSame(expected: 'submitter', actual: $action['scopeField'], message: 'Scope is stamped from subjectRef, never client-writable');
		self::assertSame(
			expected: ['participatoryBudget', 'title', 'description', 'requestedAmount', 'category'],
			actual: $action['fields']
		);
		self::assertSame(expected: ['status' => 'submitted'], actual: $action['defaults'], message: 'No submittedAt — budget-proposal has no such property');

		foreach (['submitter', 'status', 'votesFor', 'votesAgainst'] as $forbidden) {
			self::assertNotContains(needle: $forbidden, haystack: $action['fields'], message: "createBudgetProposal must never client-whitelist {$forbidden}");
		}

		self::assertSame(
			expected: [
				'field' => 'participatoryBudget',
				'parentSchema' => 'participatory-budget',
				'statusField' => 'status',
				'statusValue' => 'submission',
			],
			actual: $action['parentConstraint']
		);

	}//end testCreateBudgetProposalActionShape()

	/**
	 * Write-IDOR / lifecycle invariant (REQ-DKPCA-003): the scope field and
	 * every staff-only field are always in the server-side stamp
	 * (`scopeField`/`defaults`), never in either action's client whitelist —
	 * and a non-`citizen` audience still returns null.
	 *
	 * @return void
	 */
	public function testScopeAndStaffFieldsAreNeverClientWhitelisted(): void {
		$staffOnly = ['moderationReason', 'publicationDate', 'depublicationDate', 'voteCount', 'votesFor', 'votesAgainst'];

		foreach ($this->actionsById() as $actionId => $action) {
			self::assertNotContains(
				needle: $action['scopeField'],
				haystack: $action['fields'],
				message: "{$actionId}: scope field must never be client-writable"
			);

			foreach ($staffOnly as $forbidden) {
				self::assertNotContains(
					needle: $forbidden,
					haystack: $action['fields'],
					message: "{$actionId}: staff-only field '{$forbidden}' must never be client-writable"
				);
			}
		}

		self::assertNull(actual: $this->provider->getContribution(['audience' => 'client']));

	}//end testScopeAndStaffFieldsAreNeverClientWhitelisted()

	/**
	 * Register-drift pin: every scopeField and every projected field exists as a
	 * property on the declared schema in the shipped register JSON at HEAD.
	 *
	 * @return void
	 */
	public function testManifestMatchesShippedRegisterSchemas(): void {
		$propertiesBySlug = $this->schemaPropertiesBySlug();

		foreach ($this->collectionsById() as $collection) {
			$slug = $collection['schema'];
			self::assertArrayHasKey(key: $slug, array: $propertiesBySlug, message: "Schema slug '{$slug}' must exist in the register");

			$properties = $propertiesBySlug[$slug];

			self::assertArrayHasKey(
				key: $collection['scopeField'],
				array: $properties,
				message: "scopeField '{$collection['scopeField']}' must exist on schema '{$slug}'"
			);

			foreach ($collection['fields'] as $field) {
				self::assertArrayHasKey(
					key: $field,
					array: $properties,
					message: "Projected field '{$field}' must exist on schema '{$slug}'"
				);
			}
		}

	}//end testManifestMatchesShippedRegisterSchemas()

	/**
	 * Register-drift pin for the create actions (REQ-DKPCA-001/002, tasks.md
	 * T07): every whitelisted + stamped field exists on the shipped schema,
	 * and each parent schema's status enum includes the required open state.
	 *
	 * @return void
	 */
	public function testCreateActionsMatchShippedRegisterSchemas(): void {
		$schemas = $this->schemasBySlug();

		foreach ($this->actionsById() as $actionId => $action) {
			$slug = $action['schema'];
			self::assertArrayHasKey(key: $slug, array: $schemas, message: "Schema slug '{$slug}' must exist in the register");

			$properties = ($schemas[$slug]['properties'] ?? []);

			self::assertArrayHasKey(
				key: $action['scopeField'],
				array: $properties,
				message: "{$actionId}: scopeField '{$action['scopeField']}' must exist on schema '{$slug}'"
			);

			foreach ($action['fields'] as $field) {
				self::assertArrayHasKey(
					key: $field,
					array: $properties,
					message: "{$actionId}: whitelisted field '{$field}' must exist on schema '{$slug}'"
				);
			}

			foreach (array_keys($action['defaults']) as $field) {
				self::assertArrayHasKey(
					key: $field,
					array: $properties,
					message: "{$actionId}: stamped default field '{$field}' must exist on schema '{$slug}'"
				);
			}

			$constraint = $action['parentConstraint'];
			$parentSlug = $constraint['parentSchema'];
			$parentSchema = ($schemas[$parentSlug] ?? null);
			self::assertNotNull(actual: $parentSchema, message: "Parent schema slug '{$parentSlug}' must exist in the register");

			$statusProperty = ($parentSchema['properties'][$constraint['statusField']] ?? null);
			self::assertIsArray(actual: $statusProperty, message: "Parent schema '{$parentSlug}' must declare a '{$constraint['statusField']}' property");
			self::assertContains(
				needle: $constraint['statusValue'],
				haystack: ($statusProperty['enum'] ?? []),
				message: "Parent schema '{$parentSlug}' status enum must include '{$constraint['statusValue']}'"
			);
		}//end foreach

	}//end testCreateActionsMatchShippedRegisterSchemas()

	/**
	 * Resolve the citizen manifest's collections keyed by their id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function collectionsById(): array {
		$manifest = $this->provider->getContribution(self::CITIZEN_SUBJECT);
		$byId = [];
		foreach (($manifest['collections'] ?? []) as $collection) {
			$byId[$collection['id']] = $collection;
		}

		return $byId;
	}//end collectionsById()

	/**
	 * Resolve the citizen manifest's create actions keyed by their id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function actionsById(): array {
		$manifest = $this->provider->getContribution(self::CITIZEN_SUBJECT);
		$byId = [];
		foreach (($manifest['actions'] ?? []) as $action) {
			$byId[$action['id']] = $action;
		}

		return $byId;
	}//end actionsById()

	/**
	 * Build a map of schema slug => property-name => property, from the shipped
	 * register JSON at HEAD.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemaPropertiesBySlug(): array {
		$path = __DIR__ . '/../../../lib/Settings/decidesk_register.json';
		$json = file_get_contents(filename: $path);
		self::assertNotFalse(condition: $json, message: 'Register JSON file must exist');

		$register = json_decode(json: $json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

		$bySlug = [];
		foreach (($register['components']['schemas'] ?? []) as $schema) {
			$slug = ($schema['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			$bySlug[$slug] = ($schema['properties'] ?? []);
		}

		return $bySlug;
	}//end schemaPropertiesBySlug()

	/**
	 * Build a map of schema slug => full schema definition (properties + enum
	 * metadata), from the shipped register JSON at HEAD.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemasBySlug(): array {
		$path = __DIR__ . '/../../../lib/Settings/decidesk_register.json';
		$json = file_get_contents(filename: $path);
		self::assertNotFalse(condition: $json, message: 'Register JSON file must exist');

		$register = json_decode(json: $json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

		$bySlug = [];
		foreach (($register['components']['schemas'] ?? []) as $schema) {
			$slug = ($schema['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			$bySlug[$slug] = $schema;
		}

		return $bySlug;
	}//end schemasBySlug()
}//end class
