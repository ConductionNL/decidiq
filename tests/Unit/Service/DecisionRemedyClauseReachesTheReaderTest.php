<?php

/**
 * The whole path, from taking a decision to what an anonymous reader is served.
 *
 * WHY THIS FILE EXISTS BESIDE THREE GREEN UNIT SUITES
 * ---------------------------------------------------
 * Every link in this chain had its own passing tests while the chain itself was
 * broken in two independent places:
 *
 *   1. `DecisionPublicationService::publish()` called the remedy guard, but read
 *      the decision type through `setSchema('decision-template')` against a
 *      register that did not carry that schema. The call threw, a
 *      `catch (\Throwable)` logged a warning, and both the guard and the stamp
 *      were skipped. The unit suite's fake accepted every slug, so it could not
 *      see it.
 *   2. `PublicationPayloadService::buildDecisionPayload()` is an allow-list. It
 *      copied `legalBasis` and not `legalRemedyClause`, so even a correctly
 *      stamped decision reached the citizen telling them the legal ground the
 *      besluit rests on and not how to object to it. Its unit test asserted the
 *      nine fields the list carried, which is a test of the list against itself.
 *
 * A bug that lives BETWEEN two components passes both components' tests. So
 * this walks the real classes in order, and each step's input is the previous
 * step's OUTPUT, never a hand-built fixture:
 *
 *   publish() -> the decision that was actually stored
 *             -> PublicationPayloadService::build()
 *             -> OriSerializer::serializeCollection('publications', ...)
 *             -> what OriController::index() returns to an anonymous caller
 *
 * The register the first step resolves against is the one the app SHIPS, read
 * through `SettingsService::shippedRegisterDescriptor()`. Detach
 * `decision-template` from it and this file reddens.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Service
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

namespace OCA\Decidiq\Tests\Unit\Service;

use OCA\Decidiq\Service\DecisionPublicationService;
use OCA\Decidiq\Service\OriSerializer;
use OCA\Decidiq\Service\PublicationConfigService;
use OCA\Decidiq\Service\PublicationPayloadService;
use OCA\Decidiq\Service\SettingsService;
use OCA\Decidiq\Tests\Unit\Support\RegisterScopedObjectServiceFake;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A published besluit tells the person reading it how to contest it.
 */
class DecisionRemedyClauseReachesTheReaderTest extends TestCase {

	/**
	 * The register every step of the chain reads and writes through.
	 *
	 * @var RegisterScopedObjectServiceFake
	 */
	private RegisterScopedObjectServiceFake $register;

	/**
	 * A decision type declaring bezwaar, six weeks, at the college.
	 *
	 * @return array<string, mixed> The type.
	 */
	private function typeDeclaringBezwaar(): array {
		return [
			'id' => 'type-1',
			'name' => 'Besluit op aanvraag',
			'legalRemedies' => [
				[
					'kind' => 'bezwaar',
					'termDays' => 42,
					'body' => 'het college van burgemeester en wethouders',
				],
			],
		];
	}

	/**
	 * An adopted, unpublished decision of that type.
	 *
	 * @param string $typeId The type it names.
	 *
	 * @return array<string, mixed> The decision.
	 */
	private function adoptedDecision(string $typeId = 'type-1'): array {
		return [
			'id' => 'decision-1',
			'title' => 'Vaststelling subsidieplafond 2027',
			'text' => 'Het college stelt het subsidieplafond vast op 1.200.000 euro.',
			'outcome' => 'adopted',
			'isPublished' => 'internal',
			'legalBasis' => 'Algemene subsidieverordening art. 4',
			'bodyName' => 'College van burgemeester en wethouders',
			'decisionDate' => '2026-09-19',
			'type' => $typeId,
		];
	}

	/**
	 * Step one: take the decision through the real publish route.
	 *
	 * @param array<string, mixed>|null $type The type the register carries, or null for none.
	 * @param array<string, mixed>|null $decision The decision to publish; the adopted one by default.
	 *
	 * @return array{status: int, data: array<string, mixed>} The publish envelope.
	 */
	private function publish(?array $type, ?array $decision = null): array {
		$rows = ['decision' => ['decision-1' => ($decision ?? $this->adoptedDecision())]];
		if ($type !== null) {
			$rows['decision-template'] = ['type-1' => $type];
		}

		$this->register = new RegisterScopedObjectServiceFake(rows: $rows);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->register);

		$service = new DecisionPublicationService(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);

		return $service->publish(decisionId: 'decision-1', actorUid: 'griffier');
	}

	/**
	 * Step two: build the public payload from what was actually stored.
	 *
	 * @param array<string, mixed> $storedDecision The decision as the register holds it.
	 *
	 * @return array<string, mixed> The allow-list payload.
	 */
	private function payloadFor(array $storedDecision): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$payloadService = new PublicationPayloadService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			new PublicationConfigService($appConfig),
		);

		$payload = $payloadService->build(
			sourceType: 'decision',
			source: $storedDecision,
			bodyId: null,
			version: 1
		);

		// The payload is only publicly readable once publicationDate is set;
		// PublicationService sets it right after build(), and the serializer
		// drops anything outside that window. Relative to now rather than a
		// literal, so the window is open whatever hour the suite runs at — a
		// fixed timestamp made these tests pass or fail by time of day.
		$payload['publicationDate'] = (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM);
		$payload['depublicationDate'] = null;

		return $payload;
	}

	/**
	 * Step three: what an anonymous caller of the ORI harvest feed receives.
	 *
	 * This is the exact call `OriController::index('publications')` makes, with
	 * the exact resource and collection type its maps supply.
	 *
	 * @param array<string, mixed> $payload The stored payload.
	 *
	 * @return array<string, mixed> The one serialized item.
	 */
	private function whatTheReaderReceives(array $payload): array {
		$items = (new OriSerializer())->serializeCollection(
			resource: 'publications',
			type: 'Publication',
			objects: [$payload]
		);

		self::assertCount(1, $items, 'The publication was not served to an anonymous reader at all');

		return $items[0];
	}

	/**
	 * END TO END. The clause survives every hop to the citizen reading it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function testAPublishedDecisionReachesAnAnonymousReaderCarryingItsRemedyClause(): void {
		$result = $this->publish(type: $this->typeDeclaringBezwaar());
		self::assertSame(Http::STATUS_OK, $result['status'], 'The decision did not publish');

		$stored = $this->register->lastWriteTo(schema: 'decision');
		self::assertIsArray($stored, 'Nothing was stored for the decision');
		self::assertArrayHasKey(
			'legalRemedyClause',
			$stored,
			'The decision was stored without the clause, so no later hop can carry it'
		);

		$payload = $this->payloadFor(storedDecision: $stored);
		self::assertArrayHasKey(
			'legalRemedyClause',
			$payload,
			'The allow-list payload dropped the clause, so the publication cannot carry it'
		);

		$received = $this->whatTheReaderReceives(payload: $payload);

		self::assertSame('Besluit', ($received['oriType'] ?? null));
		self::assertSame(
			'Algemene subsidieverordening art. 4',
			($received['legal_basis'] ?? null),
			'The reader lost the legal ground'
		);

		$clause = ($received['legal_remedy_clause'] ?? null);
		self::assertIsArray(
			$clause,
			'An anonymous reader was told the legal ground this besluit rests on and NOT how to object to it.'
		);
		self::assertSame('bezwaar', ($clause['kind'] ?? null));
		self::assertSame(42, ($clause['termDays'] ?? null));
		self::assertSame('het college van burgemeester en wethouders', ($clause['body'] ?? null));
		self::assertStringContainsString(
			'binnen zes weken bezwaar maken',
			(string)($clause['text'] ?? ''),
			'The sentence the reader sees does not tell them what to do or how long they have'
		);
	}

	/**
	 * Every field the payload carries is one the schema declares.
	 *
	 * OpenRegister DROPS an undeclared property on write, in silence. A payload
	 * built with a field the shipped `PublicationPayload` schema does not
	 * declare therefore stores without it and reports success, and the reader
	 * is served a publication missing exactly the field somebody just added.
	 * That is the defect this whole change is an instance of, one layer down.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/public-publication/spec.md
	 */
	public function testEveryPayloadFieldIsDeclaredBySchemaSoNothingIsDroppedOnWrite(): void {
		$this->publish(type: $this->typeDeclaringBezwaar());
		$stored = $this->register->lastWriteTo(schema: 'decision');
		self::assertIsArray($stored);

		$payload = $this->payloadFor(storedDecision: $stored);

		$descriptor = SettingsService::shippedRegisterDescriptor();
		$declared = array_keys($descriptor['components']['schemas']['PublicationPayload']['properties'] ?? []);

		$undeclared = array_values(array_diff(array_keys($payload), $declared));

		self::assertSame(
			expected: [],
			actual: $undeclared,
			message: 'PublicationPayloadService writes a field the PublicationPayload schema does not '
				. 'declare. OpenRegister drops it on write with no error, so the reader never sees it.'
		);
	}

	/**
	 * A type declaring no remedies is refused, and nothing is saved.
	 *
	 * The other half of REQ-DWP-007, asserted on the same chain: the refusal
	 * has to happen BEFORE the write, or a half-informed besluit is already
	 * stored by the time anyone objects.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function testATypeDeclaringNoRemediesIsRefusedAndNothingReachesAReader(): void {
		$result = $this->publish(type: ['id' => 'type-1', 'name' => 'Besluit zonder rechtsmiddel']);

		self::assertSame(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			$result['status'],
			'A decision whose type declares no remedies was published anyway'
		);
		self::assertStringContainsString(
			'legal remedies',
			(string)($result['data']['message'] ?? ''),
			'The refusal did not say what was missing'
		);
		self::assertNull(
			$this->register->lastWriteTo(schema: 'decision'),
			'The publication was refused, but the decision was written anyway'
		);
		self::assertSame([], $this->register->writes(), 'Something was written despite the refusal');
	}

	/**
	 * A type declaring `geen` publishes, and says so in words.
	 *
	 * `geen` is how a type states on purpose that no remedy is open, and it is
	 * what makes refusing an UNSET declaration safe. The reader must be told
	 * that, not left with an absent field that looks the same as the bug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function testATypeDeclaringGeenTellsTheReaderNoRemedyIsOpen(): void {
		$result = $this->publish(type: ['id' => 'type-1', 'legalRemedies' => [['kind' => 'geen']]]);
		self::assertSame(Http::STATUS_OK, $result['status']);

		$stored = $this->register->lastWriteTo(schema: 'decision');
		self::assertIsArray($stored);

		$received = $this->whatTheReaderReceives(payload: $this->payloadFor(storedDecision: $stored));
		$clause = ($received['legal_remedy_clause'] ?? null);

		self::assertIsArray($clause, 'A besluit with no remedy open said nothing at all to the reader');
		self::assertSame('geen', ($clause['kind'] ?? null));
		self::assertStringContainsString(
			'geen bezwaar of beroep open',
			(string)($clause['text'] ?? ''),
			'The reader was not told that no remedy is open'
		);
	}

	/**
	 * An unstamped decision publishes no empty clause.
	 *
	 * An untyped decision carries nothing to stamp. Emitting an empty clause
	 * would read to a citizen as "no remedy is open", which is a statement
	 * `geen` exists to make deliberately and this case has no right to make.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md (REQ-DWP-007)
	 */
	public function testAnUntypedDecisionPublishesWithNoClauseRatherThanAnEmptyOne(): void {
		$result = $this->publish(type: null, decision: $this->adoptedDecision(typeId: ''));
		self::assertSame(Http::STATUS_OK, $result['status']);

		$stored = $this->register->lastWriteTo(schema: 'decision');
		self::assertIsArray($stored);

		$received = $this->whatTheReaderReceives(payload: $this->payloadFor(storedDecision: $stored));

		self::assertArrayNotHasKey(
			'legal_remedy_clause',
			$received,
			'An untyped decision served the reader an empty clause, which reads as "no remedy is open"'
		);
	}
}//end class
