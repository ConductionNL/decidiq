<?php

/**
 * Imposing a confidentiality restriction, over an in-memory register.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Service;

use DateTime;
use OCA\Decidiq\Exception\ConfidentialityImposeRefusedException;
use OCA\Decidiq\Service\ConfidentialityImposer;
use OCA\Decidiq\Service\GovernanceScopeGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ConfidentialityImposer.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
class ConfidentialityImposerTest extends TestCase {

	/**
	 * Objects per schema, by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $objects = [];

	/**
	 * Every save: [schema, uuid-or-null, object, rbac].
	 *
	 * @var list<array{0: string, 1: string|null, 2: array<string, mixed>, 3: bool}>
	 */
	private array $saves = [];

	/**
	 * Every read: [schema, id, rbac].
	 *
	 * @var list<array{0: string, 1: string, 2: bool}>
	 */
	private array $reads = [];

	/**
	 * Ids the caller may not read.
	 *
	 * @var list<string>
	 */
	private array $hiddenFromCaller = [];

	/**
	 * Schemas whose save throws.
	 *
	 * @var list<string>
	 */
	private array $failingSaves = [];

	/**
	 * Seed the register: two grounds, a paper, an agenda item and a decision.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = [
			'confidentiality-ground' => [
				'ground-gw' => ['id' => 'ground-gw', 'name' => 'Gemeentewet', 'citation' => 'Gemeentewet art. 87-89', 'active' => true],
				'ground-old' => ['id' => 'ground-old', 'name' => 'Retired', 'citation' => 'x', 'active' => false],
			],
			'digital-document' => ['doc-1' => ['id' => 'doc-1', 'title' => 'Grondexploitatie', 'public' => true]],
			'agenda-item' => ['item-1' => ['id' => 'item-1', 'title' => 'Grondaankoop', 'confidentiality' => 'internal', 'meeting' => 'meet-1']],
			'decision' => ['dec-1' => ['id' => 'dec-1', 'title' => 'Aankoop perceel', 'isPublished' => 'internal', 'lifecycle' => 'proposed']],
		];
	}//end setUp()

	/**
	 * The imposer over the register.
	 *
	 * @param array<string, bool> $groups    Group memberships of `griffier` (group => member).
	 * @param bool                $signatory Whether the caller is in the body's signatory scope.
	 * @param bool                $admin     Whether the caller is a Nextcloud administrator.
	 *
	 * @return ConfidentialityImposer
	 */
	private function imposer(array $groups=[], bool $signatory=true, bool $admin=false): ConfidentialityImposer {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options=null, bool $_rbac=true): ?ObjectEntity {
				$schema        = (string)($options?->schema ?? '');
				$this->reads[] = [$schema, (string)$id, $_rbac];
				if ($_rbac === true && in_array((string)$id, $this->hiddenFromCaller, true) === true) {
					return null;
				}

				$row = ($this->objects[$schema][(string)$id] ?? null);
				return $row === null ? null : self::entity(row: $row);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options, bool $_rbac=true): ObjectEntity {
				$schema = (string)$options->target->schema;
				if (in_array($schema, $this->failingSaves, true) === true) {
					throw new RuntimeException('save refused');
				}

				$this->saves[] = [$schema, $options->uuid, $object, $_rbac];
				$id            = ($options->uuid ?? ($schema . '-new'));
				$object['id']  = $id;
				$this->objects[$schema][$id] = $object;
				return self::entity(row: $object);
			}
		);

		$scopes = $this->createMock(originalClassName: GovernanceScopeGuard::class);
		$scopes->method('isInBodyScope')->willReturnCallback(
			static fn (string $userId, string $bodyId, string $scope): bool => $signatory && $bodyId === 'body-college' && $scope === 'signatory'
		);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($admin);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => ($groups[$group] ?? false));
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-21T10:00:00+01:00'));

		return new ConfidentialityImposer(
			objectService: $objects,
			scopeGuard: $scopes,
			groupManager: $groupManager,
			time: $time,
			logger: new NullLogger()
		);
	}//end imposer()

	/**
	 * An entity over a row.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $row): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($row['id'] ?? ''));
		unset($row['id']);
		$entity->setObject($row);
		return $entity;
	}//end entity()

	/**
	 * A complete request.
	 *
	 * @param array<string, mixed> $overrides Values to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function params(array $overrides=[]): array {
		return array_merge(
			['scope' => 'document', 'target' => 'doc-1', 'ground' => 'ground-gw', 'imposedBy' => 'executive-board', 'imposedByBody' => 'body-college'],
			$overrides
		);
	}//end params()

	/**
	 * The refusal status of a call.
	 *
	 * @param callable $call The call.
	 *
	 * @return int The status, or 0 when nothing was refused.
	 */
	private static function refusal(callable $call): int {
		try {
			$call();
		} catch (ConfidentialityImposeRefusedException $e) {
			return $e->getStatus();
		}

		return 0;
	}//end refusal()

	/**
	 * The college's secretary imposes on a paper: the restriction holds ground, organ, body, time and target.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-college-imposes-geheimhouding-on-a-document-for-the-raad
	 */
	public function testImposingOnAPaperRecordsTheRestriction(): void {
		$result = $this->imposer()->impose(userId: 'griffier', params: self::params());

		$restriction = $result['restriction'];
		$this->assertSame('document', $restriction['scope']);
		$this->assertSame('doc-1', $restriction['targetDocument']);
		$this->assertArrayNotHasKey('targetAgendaItem', $restriction);
		$this->assertSame('ground-gw', $restriction['ground']);
		$this->assertSame('executive-board', $restriction['imposedBy']);
		$this->assertSame('body-college', $restriction['imposedByBody']);
		$this->assertSame('imposed', $restriction['lifecycle']);
		$this->assertSame('2026-11-21T10:00:00+01:00', $restriction['imposedAt']);
		// A paper has no classifier: the restriction itself keeps it off public surfaces.
		$this->assertSame('not-needed', $result['classifier']);
		$this->assertCount(1, $this->saves);
		$this->assertSame('confidentiality-restriction', $this->saves[0][0]);
		$this->assertTrue($this->saves[0][3], 'The restriction is stored with the caller\'s own rights.');
	}//end testImposingOnAPaperRecordsTheRestriction()

	/**
	 * Imposing on an agenda item sets its confidentiality to confidential, every other field kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-imposing-on-an-agenda-item-drives-the-existing-confidentiality-enum
	 */
	public function testImposingOnAnAgendaItemClosesIt(): void {
		$result = $this->imposer()->impose(userId: 'griffier', params: self::params(['scope' => 'item', 'target' => 'item-1']));

		$this->assertSame('closed', $result['classifier']);
		$this->assertSame('item-1', $result['restriction']['targetAgendaItem']);
		$item = $this->objects['agenda-item']['item-1'];
		$this->assertSame('confidential', $item['confidentiality']);
		$this->assertSame('Grondaankoop', $item['title']);
		$this->assertSame('meet-1', $item['meeting']);
		$this->assertSame(['agenda-item', 'item-1'], [$this->saves[1][0], $this->saves[1][1]]);
		$this->assertFalse($this->saves[1][3], 'Closing runs as the system.');
	}//end testImposingOnAnAgendaItemClosesIt()

	/**
	 * Imposing on a decision sets its publication state to confidential, every other field kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testImposingOnADecisionClosesIt(): void {
		$result = $this->imposer()->impose(userId: 'griffier', params: self::params(['scope' => 'decision', 'target' => 'dec-1']));

		$this->assertSame('closed', $result['classifier']);
		$decision = $this->objects['decision']['dec-1'];
		$this->assertSame('confidential', $decision['isPublished']);
		$this->assertSame('proposed', $decision['lifecycle']);
		$this->assertSame('Aankoop perceel', $decision['title']);
	}//end testImposingOnADecisionClosesIt()

	/**
	 * A target already confidential is not saved again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#scenario-imposing-on-an-agenda-item-drives-the-existing-confidentiality-enum
	 */
	public function testATargetAlreadyClosedIsLeftAlone(): void {
		$this->objects['agenda-item']['item-1']['confidentiality'] = 'confidential';

		$result = $this->imposer()->impose(userId: 'griffier', params: self::params(['scope' => 'item', 'target' => 'item-1']));

		$this->assertSame('not-needed', $result['classifier']);
		$this->assertCount(1, $this->saves);
	}//end testATargetAlreadyClosedIsLeftAlone()

	/**
	 * A classifier save that fails is reported, and the restriction stays.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testAFailedClassifierSaveIsReported(): void {
		$this->failingSaves = ['decision'];

		$result = $this->imposer()->impose(userId: 'griffier', params: self::params(['scope' => 'decision', 'target' => 'dec-1']));

		$this->assertSame('not-updated', $result['classifier']);
		$this->assertSame('imposed', $result['restriction']['lifecycle']);
		$this->assertSame('internal', $this->objects['decision']['dec-1']['isPublished']);
	}//end testAFailedClassifierSaveIsReported()

	/**
	 * A caller without the body's authority is refused with 403 before any object is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testACallerWithoutAuthorityIsRefusedFirst(): void {
		$imposer = $this->imposer(signatory: false);

		$this->assertSame(403, self::refusal(fn () => $imposer->impose(userId: 'member', params: self::params())));
		$this->assertSame([], $this->reads);
		$this->assertSame([], $this->saves);
	}//end testACallerWithoutAuthorityIsRefusedFirst()

	/**
	 * Signatory scope of another body does not count.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testAuthorityIsPerBody(): void {
		$imposer = $this->imposer();

		$this->assertSame(403, self::refusal(fn () => $imposer->impose(userId: 'griffier', params: self::params(['imposedByBody' => 'body-raad']))));
	}//end testAuthorityIsPerBody()

	/**
	 * The secretariat and administrators may impose for any body.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testTheSecretariatAndAdministratorsMayImpose(): void {
		$this->assertTrue($this->imposer(groups: ['decidiq-secretariat' => true], signatory: false)->mayImpose(userId: 'griffier', bodyId: 'body-raad'));
		$this->assertTrue($this->imposer(signatory: false, admin: true)->mayImpose(userId: 'admin', bodyId: 'body-raad'));
		$this->assertTrue($this->imposer(groups: ['decidiq-administrators' => true], signatory: false)->mayImpose(userId: 'beheer', bodyId: 'body-raad'));
		$this->assertFalse($this->imposer(signatory: false)->mayImpose(userId: 'member', bodyId: 'body-raad'));
		$this->assertFalse($this->imposer()->mayImpose(userId: '', bodyId: 'body-college'));
	}//end testTheSecretariatAndAdministratorsMayImpose()

	/**
	 * No restriction without a structured ground: a missing, unknown or retired ground is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-002-configurable-ground-list-with-dual-gemeentewet-article-labels
	 */
	public function testAGroundIsRequiredAndMustBeInUse(): void {
		$imposer = $this->imposer();

		$this->assertSame(400, self::refusal(fn () => $imposer->impose(userId: 'griffier', params: self::params(['ground' => '']))));
		$this->assertSame(422, self::refusal(fn () => $imposer->impose(userId: 'griffier', params: self::params(['ground' => 'ground-none']))));
		$this->assertSame(422, self::refusal(fn () => $imposer->impose(userId: 'griffier', params: self::params(['ground' => 'ground-old']))));
		$this->assertSame([], $this->saves);
	}//end testAGroundIsRequiredAndMustBeInUse()

	/**
	 * A target the caller cannot read, or that does not exist, answers 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testAnUnreadableTargetIsNotFound(): void {
		$this->hiddenFromCaller = ['doc-1'];
		$imposer = $this->imposer();

		$this->assertSame(404, self::refusal(fn () => $imposer->impose(userId: 'griffier', params: self::params())));
		$this->assertSame(404, self::refusal(fn () => $imposer->impose(userId: 'griffier', params: self::params(['scope' => 'decision', 'target' => 'dec-none']))));
		$this->assertSame([], $this->saves);
	}//end testAnUnreadableTargetIsNotFound()

	/**
	 * An unknown scope or organ, or a missing value, is a 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
	 */
	public function testTheRequestIsValidated(): void {
		$this->assertSame(400, self::refusal(static fn () => ConfidentialityImposer::request(params: self::params(['scope' => 'meeting']))));
		$this->assertSame(400, self::refusal(static fn () => ConfidentialityImposer::request(params: self::params(['imposedBy' => 'mayor']))));
		$this->assertSame(400, self::refusal(static fn () => ConfidentialityImposer::request(params: self::params(['target' => null]))));
		$this->assertSame(400, self::refusal(static fn () => ConfidentialityImposer::request(params: self::params(['imposedByBody' => ['x']]))));
		$this->assertSame('doc-1', ConfidentialityImposer::request(params: self::params(['target' => ' doc-1 ']))['target']);
	}//end testTheRequestIsValidated()
}//end class
