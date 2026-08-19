<?php

/**
 * Unit tests for MigrateLegacyTemplatesToDecisionTemplate repair step.
 *
 * @category Test
 * @package  OCA\Decidesk\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Decidesk\Tests\Unit\Migration;

use OCA\Decidesk\Migration\MigrateLegacyTemplatesToDecisionTemplate;
use OCA\Decidesk\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the legacy-template-to-DecisionTemplate migration repair step.
 *
 * @spec openspec/changes/unified-decision-templates/tasks.md#task-1
 */
class MigrateLegacyTemplatesToDecisionTemplateTest extends TestCase {

	/**
	 * Mock SettingsService.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock IOutput.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * The repair step under test.
	 *
	 * @var MigrateLegacyTemplatesToDecisionTemplate
	 */
	private MigrateLegacyTemplatesToDecisionTemplate $migration;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->output = $this->createMock(originalClassName: IOutput::class);

		$this->migration = new MigrateLegacyTemplatesToDecisionTemplate(
			settingsService: $this->settingsService,
			container: $this->container,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * The step name is descriptive.
	 *
	 * @return void
	 */
	public function testGetNameReturnsDescription(): void {
		self::assertStringContainsString(
			needle: 'DecisionTemplate',
			haystack: $this->migration->getName()
		);

	}//end testGetNameReturnsDescription()

	/**
	 * When OpenRegister is unavailable the migration skips entirely.
	 *
	 * @return void
	 */
	public function testRunSkipsWhenOpenRegisterUnavailable(): void {
		$this->settingsService->expects($this->once())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(false);

		$this->container->expects($this->never())->method(constraint: 'get');
		$this->output->expects($this->atLeastOnce())->method(constraint: 'warning');

		$this->migration->run(output: $this->output);

	}//end testRunSkipsWhenOpenRegisterUnavailable()

	/**
	 * A live process-template object is migrated into a decision-template
	 * object carrying every field forward, unchanged, plus migratedFrom
	 * provenance and an empty checklist (migration.md step 3).
	 *
	 * @return void
	 */
	public function testRunMigratesProcessTemplateFieldsVerbatim(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$stateMachine = [
			'states' => [['name' => 'draft'], ['name' => 'decided']],
			'transitions' => [['name' => 'decide', 'from' => 'draft', 'to' => 'decided']],
		];
		$votingRule = ['voteThreshold' => 'simple-majority', 'abstentionHandling' => 'exclude', 'tieBreakRule' => 'rejected'];

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [
				[
					'id' => 'pt-1',
					'name' => 'Association ALV',
					'description' => 'Standard process',
					'context' => 'association',
					'builtIn' => true,
					'initialState' => 'draft',
					'stateMachine' => $stateMachine,
					'votingRule' => $votingRule,
					'quorumRequired' => true,
					'quorumRule' => '50%+1',
					'allowDecideWithoutVote' => false,
				],
			],
			vveDecisionTemplates: [],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		$saved = $objectService->saved[0];
		self::assertSame(expected: $stateMachine, actual: $saved['stateMachine']);
		self::assertSame(expected: $votingRule, actual: $saved['votingRule']);
		self::assertSame(expected: [], actual: $saved['checklist']);
		self::assertSame(
			expected: ['sourceSchema' => 'process-template', 'sourceUuid' => 'pt-1'],
			actual: $saved['migratedFrom']
		);
		self::assertArrayNotHasKey(key: 'decisionType', array: $saved);

	}//end testRunMigratesProcessTemplateFieldsVerbatim()

	/**
	 * A live vve-decision-template object is migrated with the field mapping
	 * from migration.md step 4: decisionCategory -> templateCategory,
	 * defaultVoteThreshold -> votingRule.voteThreshold,
	 * defaultQuorumFraction -> quorumRule, context fixed to association,
	 * decisionType fixed to resolution.
	 *
	 * @return void
	 */
	public function testRunMapsVveDecisionTemplateFields(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [],
			vveDecisionTemplates: [
				[
					'id' => 'vve-1',
					'name' => 'Machtiging bestuur onderhoud boven drempel',
					'decisionCategory' => 'authorisation-above-threshold',
					'proposedText' => 'De vergadering machtigt het bestuur...',
					'builtIn' => true,
					'defaultVoteThreshold' => 'qualified-majority-two-thirds',
					'defaultQuorumFraction' => '2/3',
					'regulationSource' => 'MR 2017 art. 56 lid 5',
				],
			],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 1, haystack: $objectService->saved);
		$saved = $objectService->saved[0];
		self::assertSame(expected: 'association', actual: $saved['context']);
		self::assertSame(expected: 'resolution', actual: $saved['decisionType']);
		self::assertSame(expected: 'authorisation-above-threshold', actual: $saved['templateCategory']);
		self::assertSame(
			expected: 'qualified-majority-two-thirds',
			actual: $saved['votingRule']['voteThreshold']
		);
		self::assertSame(expected: '2/3', actual: $saved['quorumRule']);
		self::assertSame(
			expected: ['sourceSchema' => 'vve-decision-template', 'sourceUuid' => 'vve-1'],
			actual: $saved['migratedFrom']
		);

	}//end testRunMapsVveDecisionTemplateFields()

	/**
	 * An object already carrying a matching migratedFrom marker is skipped —
	 * a re-run of the migration creates no duplicates (idempotency).
	 *
	 * @return void
	 */
	public function testRunSkipsAlreadyMigratedObject(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [
				[
					'id' => 'dt-1',
					'name' => 'Association ALV',
					'migratedFrom' => ['sourceSchema' => 'process-template', 'sourceUuid' => 'pt-1'],
				],
			],
			processTemplates: [
				['id' => 'pt-1', 'name' => 'Association ALV', 'context' => 'association'],
			],
			vveDecisionTemplates: [],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertCount(expectedCount: 0, haystack: $objectService->saved);

	}//end testRunSkipsAlreadyMigratedObject()

	/**
	 * The migration never deletes or edits a source process-template or
	 * vve-decision-template object — it is purely additive.
	 *
	 * @return void
	 */
	public function testRunNeverDeletesSourceObjects(): void {
		$this->settingsService->expects($this->any())
			->method(constraint: 'isOpenRegisterAvailable')
			->willReturn(true);

		$objectService = $this->makeRecordingObjectService(
			decisionTemplates: [],
			processTemplates: [
				['id' => 'pt-1', 'name' => 'Association ALV', 'context' => 'association'],
			],
			vveDecisionTemplates: [
				['id' => 'vve-1', 'name' => 'Decharge bestuur', 'decisionCategory' => 'discharge'],
			],
		);

		$this->container->expects($this->any())
			->method(constraint: 'get')
			->willReturn($objectService);

		$this->migration->run(output: $this->output);

		self::assertSame(expected: 0, actual: $objectService->deleteCalls);
		self::assertCount(expectedCount: 2, haystack: $objectService->saved);

	}//end testRunNeverDeletesSourceObjects()

	/**
	 * Build a stub ObjectService that records saves/deletes and returns the
	 * configured objects per schema from findAll(), keyed by whichever
	 * schema setSchema() was last called with.
	 *
	 * @param array<int,array<string,mixed>> $decisionTemplates Existing decision-template rows.
	 * @param array<int,array<string,mixed>> $processTemplates Live process-template rows.
	 * @param array<int,array<string,mixed>> $vveDecisionTemplates Live vve-decision-template rows.
	 *
	 * @return object
	 */
	private function makeRecordingObjectService(
		array $decisionTemplates,
		array $processTemplates,
		array $vveDecisionTemplates,
	): object {
		return new class ($decisionTemplates, $processTemplates, $vveDecisionTemplates) {
			/**
			 * Saved objects in call order.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $saved = [];

			/**
			 * Number of deleteObject calls.
			 *
			 * @var integer
			 */
			public int $deleteCalls = 0;

			/**
			 * Currently selected schema (set via setSchema()).
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $decisionTemplates Existing decision-template rows.
			 * @param array<int,array<string,mixed>> $processTemplates Live process-template rows.
			 * @param array<int,array<string,mixed>> $vveDecisionTemplates Live vve-decision-template rows.
			 */
			public function __construct(
				private readonly array $decisionTemplates,
				private readonly array $processTemplates,
				private readonly array $vveDecisionTemplates,
			) {
			}//end __construct()

			/**
			 * Stub setRegister.
			 *
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Stub setSchema — records which schema is active for the next findAll().
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the configured rows for the currently selected schema.
			 *
			 * @param array<string,mixed> $config Query config.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config = []): array {
				return match ($this->currentSchema) {
					'decision-template' => $this->decisionTemplates,
					'process-template' => $this->processTemplates,
					'vve-decision-template' => $this->vveDecisionTemplates,
					default => [],
				};
			}//end findAll()

			/**
			 * Capture a save.
			 *
			 * @param array<string,mixed> $object Object to save.
			 * @param string|null $register Register slug.
			 * @param string|null $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null): array {
				$this->saved[] = $object;
				return $object;
			}//end saveObject()

			/**
			 * Capture a delete (should never be called by this migration).
			 *
			 * @param string $uuid Object UUID.
			 * @param string|null $register Register slug.
			 * @param string|null $schema Schema slug.
			 *
			 * @return bool
			 */
			public function deleteObject(string $uuid, ?string $register = null, ?string $schema = null): bool {
				$this->deleteCalls++;
				return true;
			}//end deleteObject()
		};

	}//end makeRecordingObjectService()
}//end class
