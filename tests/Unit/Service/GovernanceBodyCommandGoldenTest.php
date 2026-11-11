<?php

/**
 * Golden tests for GovernanceBodyCommandService.
 *
 * Pins the exact sequence of reads and writes, and every refusal, for the
 * branches GovernanceBodyCommandServiceTest leaves out: a missing required
 * field, a non-boolean active, the attribute filter, a roster entry without a
 * uid or role, an existing person, a person name, a label, the expanded
 * relation shape, the @self id shape and the stores that return no id.
 *
 * @category  Test
 * @package   OCA\Decidiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/decidiq
 *
 * @spec openspec/changes/governance-body-events/specs/governance-body-events/spec.md
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\GovernanceBodyCommandService;
use OCA\Decidiq\Service\RegisterObjectStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins every store call and refusal of the governance-body command.
 */
final class GovernanceBodyCommandGoldenTest extends TestCase {
	/**
	 * Every store call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Scripted findAll answers per schema, consumed in order.
	 *
	 * @var array<string, list<array<int, array<string, mixed>>>>
	 */
	private array $finds = [];

	/**
	 * Scripted save answers per schema, consumed in order.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $saves = [];

	/**
	 * The service over a scripted store.
	 *
	 * @return GovernanceBodyCommandService The service.
	 */
	private function service(): GovernanceBodyCommandService {
		$facade = $this->createMock(ObjectServiceInterface::class);
		$facade->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = $config['filters'];
				$this->calls[] = ['find', $filters];
				$queue = ($this->finds[$filters['schema']] ?? []);
				$this->finds[$filters['schema']] = array_slice($queue, 1);
				return ($queue[0] ?? []);
			}
		);
		$facade->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->calls[] = ['save', $schema, $object, $uuid];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$queue = ($this->saves[$schema] ?? []);
				$this->saves[$schema] = array_slice($queue, 1);
				$entity->method('jsonSerialize')->willReturn(($queue[0] ?? []));
				return $entity;
			}
		);

		return new GovernanceBodyCommandService(new RegisterObjectStore($facade));
	}//end service()

	/**
	 * The message an upsert throws.
	 *
	 * @param array<string, mixed>             $body    The body fields.
	 * @param array<int, array<string, mixed>> $members The roster.
	 *
	 * @return string The message, or 'none'.
	 */
	private function refusal(array $body, array $members = []): string {
		try {
			$this->service()->upsert('app', 'ref', $body, $members);
		} catch (RuntimeException $e) {
			return $e->getMessage();
		}

		return 'none';
	}//end refusal()

	/**
	 * A valid body.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function body(): array {
		return ['name' => 'N', 'bodyType' => 'T', 'domain' => 'D', 'active' => false];
	}//end body()

	/**
	 * Each refusal, before anything is read or written.
	 *
	 * @return void
	 */
	public function testRefusalsComeBeforeAnyStoreCall(): void {
		$body = $this->body();
		$this->assertSame('A governance-body command needs a name', $this->refusal(['name' => ''] + $body));
		$this->assertSame('A governance-body command needs a bodyType', $this->refusal(['bodyType' => ''] + $body));
		$this->assertSame('A governance-body command needs a domain', $this->refusal(['domain' => ''] + $body));
		$this->assertSame(
			'A governance-body command must state `active` as a boolean: it is never defaulted',
			$this->refusal(['active' => 1] + $body)
		);
		$this->assertSame(
			'A governance-body command must state `active` as a boolean: it is never defaulted',
			$this->refusal(['name' => 'N', 'bodyType' => 'T', 'domain' => 'D'])
		);
		$this->assertSame([], $this->calls);
	}//end testRefusalsComeBeforeAnyStoreCall()

	/**
	 * A body with no id from the store is refused before any roster write.
	 *
	 * @return void
	 */
	public function testABodyWithoutAnIdIsRefused(): void {
		$this->saves['governance-body'] = [['name' => 'N']];
		$this->assertSame('OpenRegister returned a governance body with no id', $this->refusal($this->body(), [['uid' => 'a']]));
		$this->assertCount(2, $this->calls);
	}//end testABodyWithoutAnIdIsRefused()

	/**
	 * Roster refusals: no uid, unknown role, and a person stored without an id.
	 *
	 * @return void
	 */
	public function testRosterRefusals(): void {
		$this->saves['governance-body'] = [['id' => 'b-1'], ['id' => 'b-1'], ['id' => 'b-1']];
		$this->assertSame('A roster entry needs a uid', $this->refusal($this->body(), [['uid' => '  ']]));
		$this->assertSame('Unknown membership role: boss', $this->refusal($this->body(), [['uid' => 'a', 'role' => 'boss']]));
		$this->saves['person'] = [['name' => 'a']];
		$this->assertSame('OpenRegister returned a person with no id', $this->refusal($this->body(), [['uid' => 'a']]));
	}//end testRosterRefusals()

	/**
	 * A new body with a new person and a new seat, every call in order.
	 *
	 * @return void
	 */
	public function testANewBodyWritesInOrder(): void {
		$this->finds['governance-body'] = [[['id' => 'x', 'sourceApp' => 'other', 'externalReference' => 'ref'], ['id' => 'y', 'sourceApp' => 'app', 'externalReference' => 'other']]];
		$this->finds['person'] = [[['id' => 'p-0', 'nextcloudUserId' => 'someone-else']]];
		$this->saves['governance-body'] = [['@self' => ['id' => 'b-1']]];
		$this->saves['person'] = [['id' => 'p-1']];
		$body = $this->body() + ['quorum' => 0, 'quorumRule' => null, 'jurisdiction' => '', 'termEnd' => '2027', 'sourceApp' => 'evil', 'colour' => 'red'];

		$result = $this->service()->upsert('app', 'ref', $body, [['uid' => ' alice ', 'name' => '  ', 'label' => '  ']]);

		$this->assertSame(['id' => 'b-1', 'created' => true], $result);
		$this->assertSame(
			[
				['find', ['register' => 'decidiq', 'schema' => 'governance-body', 'sourceApp' => 'app', 'externalReference' => 'ref']],
				['save', 'governance-body', ['name' => 'N', 'bodyType' => 'T', 'domain' => 'D', 'active' => false, 'sourceApp' => 'app', 'externalReference' => 'ref', 'quorum' => 0, 'termEnd' => '2027'], null],
				['find', ['register' => 'decidiq', 'schema' => 'person', 'nextcloudUserId' => 'alice']],
				['save', 'person', ['name' => 'alice', 'nextcloudUserId' => 'alice'], null],
				['find', ['register' => 'decidiq', 'schema' => 'membership', 'governanceBody' => 'b-1', 'person' => 'p-1']],
				['save', 'membership', ['governanceBody' => 'b-1', 'person' => 'p-1', 'role' => 'member', 'external' => false], null],
			],
			$this->calls
		);
	}//end testANewBodyWritesInOrder()

	/**
	 * An existing body, person and seat are updated in place.
	 *
	 * @return void
	 */
	public function testAnExistingBodyPersonAndSeatAreReused(): void {
		$this->finds['governance-body'] = [[['@self' => ['id' => 'b-9'], 'id' => 'b-9', 'sourceApp' => 'app', 'externalReference' => 'ref']]];
		$this->finds['person'] = [[['nextcloudUserId' => 'bob'], ['id' => 'p-9', 'nextcloudUserId' => 'bob']]];
		$this->finds['membership'] = [[['id' => 'm-0', 'governanceBody' => 'b-9', 'person' => 'p-x'], ['id' => 'm-9', 'governanceBody' => ['@self' => ['id' => 'b-9']], 'person' => ['id' => 'p-9']]]];
		$this->saves['governance-body'] = [['id' => 'b-9']];

		$result = $this->service()->upsert('app', 'ref', $this->body(), [['uid' => 'bob', 'role' => 'chair', 'external' => 1, 'label' => ' Voorzitter ', 'name' => 'Bob']]);

		$this->assertSame(['id' => 'b-9', 'created' => false], $result);
		$this->assertSame(['save', 'governance-body', ['name' => 'N', 'bodyType' => 'T', 'domain' => 'D', 'active' => false, 'sourceApp' => 'app', 'externalReference' => 'ref'], 'b-9'], $this->calls[1]);
		$this->assertSame(['find', ['register' => 'decidiq', 'schema' => 'membership', 'governanceBody' => 'b-9', 'person' => 'p-9']], $this->calls[3]);
		$this->assertSame(['save', 'membership', ['governanceBody' => 'b-9', 'person' => 'p-9', 'role' => 'chair', 'external' => true, 'label' => 'Voorzitter'], 'm-9'], $this->calls[4]);
		$this->assertCount(5, $this->calls);
	}//end testAnExistingBodyPersonAndSeatAreReused()

	/**
	 * A named new person keeps their name; a later entry's refusal follows the earlier entry's writes.
	 *
	 * @return void
	 */
	public function testANamedPersonAndARefusalMidRoster(): void {
		$this->saves['governance-body'] = [['id' => 'b-1']];
		$this->saves['person'] = [['id' => 'p-1']];
		$message = $this->refusal($this->body(), [['uid' => 'a', 'name' => ' Anne '], ['uid' => 'b', 'role' => 'nobody']]);

		$this->assertSame('Unknown membership role: nobody', $message);
		$this->assertSame(['save', 'person', ['name' => 'Anne', 'nextcloudUserId' => 'a'], null], $this->calls[3]);
		$this->assertCount(6, $this->calls);
	}//end testANamedPersonAndARefusalMidRoster()
}//end class
