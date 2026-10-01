<?php

/**
 * Unit tests for public votes and vote events on the ORI API.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Controller;

use OCA\Decidiq\Controller\OriController;
use OCA\Decidiq\Service\ObjectRelationFilter;
use OCA\Decidiq\Service\OriSerializer;
use OCA\Decidiq\Service\OriVotePublicationRule;
use OCA\Decidiq\Service\PersonParticipantLookup;
use OCA\Decidiq\Service\SettingsService;
use OCA\Decidiq\Service\VotingRecordService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A vote is public when its round is closed and not secret, its decision is
 * published, it has a value and its body publishes voting records. The
 * collection and the item endpoint apply the same rule.
 *
 * @covers \OCA\Decidiq\Controller\OriController
 * @covers \OCA\Decidiq\Service\OriVotePublicationRule
 * @covers \OCA\Decidiq\Service\OriSerializer
 * @uses   \OCA\Decidiq\Service\VotingRecordService
 * @uses   \OCA\Decidiq\Service\PersonParticipantLookup
 * @uses   \OCA\Decidiq\Service\ObjectRelationFilter
 * @uses   \OCA\Decidiq\Service\SettingsService
 * @uses   \OCA\Decidiq\Service\RoleGroupMapping
 *
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/ori-api/spec.md#requirement-req-mpr-006-the-public-ori-api-returns-public-votes-with-their-voter
 */
class OriVotePublicationTest extends TestCase {

	/**
	 * Objects per schema: schema => id => data.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * The `voter` query parameter the request carries.
	 *
	 * @var string|null
	 */
	private ?string $voter = null;

	/**
	 * Put one object in the store.
	 *
	 * @param string               $schema The schema slug
	 * @param string               $id     The object id
	 * @param array<string, mixed> $data   The object data
	 *
	 * @return void
	 */
	private function put(string $schema, string $id, array $data): void {
		$this->store[$schema][$id] = $data;
	}//end put()

	/**
	 * Change fields of a stored object.
	 *
	 * @param string               $schema The schema slug
	 * @param string               $id     The object id
	 * @param array<string, mixed> $fields The fields to set
	 *
	 * @return void
	 */
	private function patch(string $schema, string $id, array $fields): void {
		$this->store[$schema][$id] = (array_merge($this->store[$schema][$id], $fields));
	}//end patch()

	/**
	 * Wrap stored data in the entity OpenRegister returns.
	 *
	 * @param string               $schema The schema slug
	 * @param string               $id     The object id
	 * @param array<string, mixed> $data   The data
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $schema, string $id, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * An ObjectServiceInterface over the in-memory store. The anonymous
	 * caller's own reads find nothing, the way lane 23 measured OpenRegister
	 * answering anonymous callers; only system-context reads see the store.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true): ?ObjectEntity {
				$data = ($this->store[(string)$schema][(string)$id] ?? null);
				if ($data === null || $_rbac === true) {
					return null;
				}

				return self::entity(schema: (string)$schema, id: (string)$id, data: $data);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true): array {
				if ($_rbac === true) {
					return [];
				}

				$filters = ($config['filters'] ?? []);
				$schema = (string)($filters['schema'] ?? '');
				unset($filters['schema'], $filters['register']);
				$found = [];
				foreach (($this->store[$schema] ?? []) as $id => $data) {
					if (self::meetsFilters(data: $data, filters: $filters) === true) {
						$found[] = self::entity(schema: $schema, id: (string)$id, data: $data);
					}
				}

				return $found;
			}
		);
		$objectService->method('saveObject')->willThrowException(new \RuntimeException('A public read must never write.'));
		return $objectService;
	}//end objectService()

	/**
	 * Whether stored data meets equality filters (the relation filter matches any relation id).
	 *
	 * @param array<string, mixed> $data    The data
	 * @param array<string, mixed> $filters The filters
	 *
	 * @return bool
	 */
	private static function meetsFilters(array $data, array $filters): bool {
		foreach ($filters as $field => $value) {
			if ($field === ObjectRelationFilter::RELATION_FILTER_FIELD) {
				if (in_array($value, array_column(($data['relations'] ?? []), 'id'), true) === false) {
					return false;
				}

				continue;
			}

			if (($data[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end meetsFilters()

	/**
	 * The controller under test, with the real rule over the store.
	 *
	 * @return OriController
	 */
	private function controller(): OriController {
		$objectService = $this->objectService();
		$people = new PersonParticipantLookup(objectService: $objectService);
		$records = new VotingRecordService(objectService: $objectService, participants: $people, relationFilter: new ObjectRelationFilter());

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key) => ($key === 'voter' ? $this->voter : null));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new OriController(
			$request,
			$this->createMock(IConfig::class),
			$container,
			$this->createMock(LoggerInterface::class),
			new OriSerializer(),
			new OriVotePublicationRule(records: $records, people: $people, objectService: $objectService),
		);
	}//end controller()

	/**
	 * The ids of the items a collection lists.
	 *
	 * @param string $resource The ORI resource
	 *
	 * @return list<string>
	 */
	private function listed(string $resource): array {
		$data = $this->controller()->index(resource: $resource)->getData();
		return array_column($data['items'], 'id');
	}//end listed()

	/**
	 * The council publishes votes; Marie voted for a published motion.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->voter = null;
		$this->put('governance-body', 'raad', ['name' => 'Gemeenteraad Amsterdam', 'bodyType' => 'legislative', 'domain' => 'municipality', 'publishVotingRecords' => true]);
		$this->put('person', 'marie', ['name' => 'Marie Janssen', 'email' => 'm.janssen@amsterdam.nl']);
		$this->put('person', 'bas', ['name' => 'Bas Smit', 'email' => 'b.smit@amsterdam.nl']);
		$this->put('participant', 'p-marie', ['displayName' => 'Marie Janssen', 'role' => 'member', 'party' => 'GroenLinks', 'nextcloudUserId' => 'mjanssen', 'email' => 'm.janssen@amsterdam.nl']);
		$this->put('participant', 'p-bas', ['displayName' => 'Bas Smit', 'role' => 'member', 'party' => 'VVD', 'email' => 'b.smit@amsterdam.nl']);
		$this->put('membership', 'm-marie', ['role' => 'member', 'party' => 'D66', 'person' => 'marie', 'governanceBody' => 'raad', 'startDate' => '2022-03-16T00:00:00Z']);
		// The register declares no decision.meeting: the body that votes is the
		// decisive stage's assignedBody, the way the example sets link it.
		$this->put('decision', 'groen-dak', ['title' => 'Groen dak op het stadhuis', 'text' => 'De raad verzoekt het college een groen dak aan te leggen.', 'decisionType' => 'motion', 'isPublished' => 'public']);
		$this->put('decision-stage', 'stage-groen-dak', ['sequence' => 1, 'stageType' => 'decisive', 'status' => 'decided', 'decisionMakerType' => 'body', 'label' => 'Stemming in de raad', 'decision' => 'groen-dak', 'votingRound' => 'r-open', 'assignedBody' => 'raad']);
		$this->put('voting-round', 'r-open', ['votingMethod' => 'for-against-abstain', 'isSecret' => false, 'openedAt' => '2025-04-10T20:55:00Z', 'closedAt' => '2025-04-10T21:05:00Z', 'result' => 'adopted', 'decisionStage' => 'stage-groen-dak', 'votesFor' => 23, 'votesAgainst' => 8, 'votesAbstain' => 1]);
		$this->put('vote', 'v-marie', ['value' => 'for', 'castAt' => '2025-04-10T21:01:00Z', 'votingRound' => 'r-open', 'participant' => 'p-marie', 'authorizationRef' => 'secret-ref']);
		$this->put('vote', 'v-bas', ['value' => 'against', 'castAt' => '2025-04-10T21:02:00Z', 'votingRound' => 'r-open', 'participant' => 'p-bas']);
	}//end setUp()

	/**
	 * Every fixture is an object the merged register accepts, so the rule is
	 * tested against data OpenRegister can actually hold.
	 *
	 * @return void
	 */
	public function testTheFixturesAreValidRegisterObjects(): void {
		foreach ($this->store as $schema => $objects) {
			$merged = $this->mergedSchema(slug: $schema);
			self::assertNotSame([], $merged, $schema . ' is a register schema.');
			foreach ($objects as $id => $data) {
				self::assertSame('', self::schemaErrors(data: $data, schema: $merged), $schema . ' ' . $id);
			}
		}
	}//end testTheFixturesAreValidRegisterObjects()

	/**
	 * The merged schema for a slug, as the register import sends it
	 * (SettingsService merges the base register and every fragment, unioning enums).
	 *
	 * @param string $slug The schema slug
	 *
	 * @return array<string, mixed>
	 */
	private function mergedSchema(string $slug): array {
		$settings = new SettingsService(
			appConfig: $this->createMock(IAppConfig::class),
			appManager: $this->createMock(IAppManager::class),
			container: $this->createMock(ContainerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
		);
		foreach (($settings->mergedRegisterConfig()['components']['schemas'] ?? []) as $name => $schema) {
			if (($schema['slug'] ?? $name) === $slug) {
				return $schema;
			}
		}

		return [];
	}//end mergedSchema()

	/**
	 * Validation errors of an object against a merged schema (empty = valid).
	 * A reference is written here as a store key; OpenRegister holds a uuid.
	 *
	 * @param array<string, mixed> $data   The object
	 * @param array<string, mixed> $schema The merged schema
	 *
	 * @return string
	 */
	private static function schemaErrors(array $data, array $schema): string {
		$properties = $schema['properties'];
		foreach ($properties as $key => $property) {
			unset($properties[$key]['$ref'], $properties[$key]['facetable'], $properties[$key]['inversedBy']);
			if (isset($property['$ref'], $data[$key]) === true && is_string($data[$key]) === true) {
				$data[$key] = '6f1c1c38-4d3c-4d0e-9a55-2b8b2b1f0e01';
			}
		}

		$result = (new Validator())->validate(
			json_decode((string)json_encode($data)),
			json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties]))
		);
		if ($result->isValid() === true) {
			return '';
		}

		return (string)json_encode((new ErrorFormatter())->format($result->error()));
	}//end schemaErrors()

	/**
	 * Scenario "A resident's portal reads a member's record".
	 *
	 * @return void
	 */
	public function testAPublicVoteNamesItsVoterOptionEventAndGroup(): void {
		$this->voter = 'marie';

		$data = $this->controller()->index(resource: 'votes')->getData();

		self::assertSame(1, $data['count']);
		$vote = $data['items'][0];
		self::assertSame('Vote', $vote['@type']);
		self::assertSame('v-marie', $vote['id']);
		self::assertSame('marie', $vote['voter']);
		self::assertSame('yes', $vote['option']);
		self::assertSame('r-open', $vote['vote_event']);
		self::assertSame('D66', $vote['group'], 'The party of the membership on the vote date, not the participant record.');
		self::assertSame(
			['@context', '@type', 'id', 'voter', 'option', 'vote_event', 'group'],
			array_keys($vote),
			'Nothing else of the ballot reaches an anonymous caller.'
		);
	}//end testAPublicVoteNamesItsVoterOptionEventAndGroup()

	/**
	 * Without `?voter=` both public votes are listed; a voter with no match gets none.
	 *
	 * @return void
	 */
	public function testTheVoterParameterNarrowsTheCollection(): void {
		self::assertSame(['v-marie', 'v-bas'], $this->listed(resource: 'votes'));

		$this->voter = 'bas';
		self::assertSame(['v-bas'], $this->listed(resource: 'votes'));

		$this->voter = 'nobody';
		self::assertSame([], $this->listed(resource: 'votes'));
	}//end testTheVoterParameterNarrowsTheCollection()

	/**
	 * Each of the five conditions, broken in turn, withholds the vote from the
	 * collection and from the item endpoint.
	 *
	 * @param string               $schema The object to change
	 * @param string               $id     Its id
	 * @param array<string, mixed> $fields The fields that break the rule
	 *
	 * @return void
	 *
	 * @dataProvider brokenConditionProvider
	 */
	public function testABrokenConditionWithholdsTheVote(string $schema, string $id, array $fields): void {
		$this->patch(schema: $schema, id: $id, fields: $fields);

		self::assertNotContains('v-marie', $this->listed(resource: 'votes'));
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller()->show(resource: 'votes', id: 'v-marie')->getStatus()
		);
	}//end testABrokenConditionWithholdsTheVote()

	/**
	 * The five conditions of the rule, each broken.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function brokenConditionProvider(): array {
		return [
			'round still open'            => ['voting-round', 'r-open', ['closedAt' => null]],
			'secret round'                => ['voting-round', 'r-open', ['isSecret' => true]],
			'decision not published'      => ['decision', 'groen-dak', ['isPublished' => 'internal']],
			'vote anonymised'             => ['vote', 'v-marie', ['value' => null]],
			'body does not publish votes' => ['governance-body', 'raad', ['publishVotingRecords' => false]],
		];
	}//end brokenConditionProvider()

	/**
	 * Scenario "A council publishes, a supervisory board does not": a body
	 * without the setting keeps the default, and its votes stay private.
	 *
	 * @return void
	 */
	public function testABodyWithoutTheSettingPublishesNoVotes(): void {
		$this->put('governance-body', 'raad', ['name' => 'Raad van Commissarissen ACME B.V.', 'bodyType' => 'supervisory-board', 'domain' => 'corporate']);

		self::assertSame([], $this->listed(resource: 'votes'));
	}//end testABodyWithoutTheSettingPublishesNoVotes()

	/**
	 * A public vote is served by id with the same shape as in the collection.
	 *
	 * @return void
	 */
	public function testAPublicVoteIsServedById(): void {
		$response = $this->controller()->show(resource: 'votes', id: 'v-marie');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('marie', $response->getData()['voter']);
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller()->show(resource: 'votes', id: 'no-such-vote')->getStatus()
		);
	}//end testAPublicVoteIsServedById()

	/**
	 * Scenario "Vote events publish totals only".
	 *
	 * @return void
	 */
	public function testAVoteEventCarriesTotalsAndNoMemberValues(): void {
		$data = $this->controller()->index(resource: 'voteevents')->getData();

		self::assertSame(1, $data['count']);
		$event = $data['items'][0];
		self::assertSame('VoteEvent', $event['@type']);
		self::assertSame('r-open', $event['id']);
		self::assertSame('adopted', $event['result']);
		self::assertSame('groen-dak', $event['motion']);
		self::assertSame(
			[['option' => 'yes', 'value' => 23], ['option' => 'no', 'value' => 8], ['option' => 'abstain', 'value' => 1]],
			$event['counts']
		);
		self::assertSame(
			['@context', '@type', 'id', 'motion', 'result', 'start_date', 'end_date', 'counts'],
			array_keys($event)
		);
		self::assertSame(Http::STATUS_OK, $this->controller()->show(resource: 'voteevents', id: 'r-open')->getStatus());
	}//end testAVoteEventCarriesTotalsAndNoMemberValues()

	/**
	 * A round on an unpublished decision, or one still open, is no vote event.
	 *
	 * @return void
	 */
	public function testAnUnpublishedOrOpenRoundIsNoVoteEvent(): void {
		$this->patch(schema: 'decision', id: 'groen-dak', fields: ['isPublished' => 'confidential']);
		self::assertSame([], $this->listed(resource: 'voteevents'));
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->show(resource: 'voteevents', id: 'r-open')->getStatus());

		$this->patch(schema: 'decision', id: 'groen-dak', fields: ['isPublished' => 'public']);
		$this->patch(schema: 'voting-round', id: 'r-open', fields: ['closedAt' => null]);
		self::assertSame([], $this->listed(resource: 'voteevents'));
	}//end testAnUnpublishedOrOpenRoundIsNoVoteEvent()
}//end class
