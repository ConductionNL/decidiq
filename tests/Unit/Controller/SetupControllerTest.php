<?php

namespace Unit\Controller;

use OCA\Decidiq\Controller\SetupController;
use OCA\Decidiq\Service\SeedProfileService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-042 / ADR-111 setup contract.
 *
 * The assertions here are about what the wizard can OBSERVE. A step the status
 * document never mentions resolves to `done: false` forever, and an optional
 * step that can never be marked done keeps the wizard open over every page —
 * so "the step is reported" and "a decision closes it" are the contract, not
 * incidental detail.
 *
 * The seed-profiles change split one question in two, because
 * `CnSetupWizard::runAction()` posts no body: the `choice` step records WHICH
 * example set, and the `run-action` step that follows imports it.
 */
class SetupControllerTest extends TestCase {
	private IAppConfig $appConfig;
	private LoggerInterface $logger;
	private SeedProfileService $seedProfiles;
	private IRequest $request;
	private SetupController $controller;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->seedProfiles = $this->createMock(SeedProfileService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new SetupController(
			$this->request,
			$this->appConfig,
			$this->logger,
			$this->seedProfiles
		);
	}

	public function testStatusReportsBothExampleSetSteps(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->seedProfiles->method('listProfiles')->willReturn([]);

		$data = $this->controller->status()->getData();

		// Absence is the defect this guards: a step the wizard is never told
		// about cannot be offered and cannot be completed.
		$this->assertArrayHasKey('example-set', $data['steps']);
		$this->assertArrayHasKey('load-example-set', $data['steps']);
		$this->assertFalse($data['steps']['example-set']['done']);
		$this->assertFalse($data['steps']['load-example-set']['done']);
		// This app declares no REQUIRED step, so setup must never gate the app.
		$this->assertTrue($data['completed']);
		$this->assertSame(1, $data['version']);
	}

	public function testStatusOffersTheSetsTheAppShips(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->seedProfiles->method('listProfiles')->willReturn([
			['id' => 'municipality', 'label' => 'Municipality', 'description' => '', 'objectCount' => 199],
		]);

		$data = $this->controller->status()->getData();

		// The sets are read from disk, so a set that ships is always offered.
		$this->assertSame('municipality', $data['profiles'][0]['id']);
	}

	public function testChoosingNoneClosesTheLoadStepWithoutRunningIt(): void {
		// 🔴 THE POINT OF THIS TEST. "None" is an ANSWER, not the absence of
		// one. If picking it left the load step outstanding, the wizard would
		// reopen over every page for an operator who has already said no.
		$this->appConfig->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string
				=> ($key === 'example_profile' ? 'none' : ''));
		$this->seedProfiles->method('listProfiles')->willReturn([]);

		$data = $this->controller->status()->getData();

		$this->assertTrue($data['steps']['example-set']['done']);
		$this->assertTrue($data['steps']['load-example-set']['done']);
	}

	public function testTheChoiceIsPersisted(): void {
		$this->request->method('getParam')->willReturn('municipality');
		$this->seedProfiles->method('isKnown')->with('municipality')->willReturn(true);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('decidiq', 'example_profile', 'municipality');

		$data = $this->controller->saveConfig()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('municipality', $data['config']['example_profile']);
	}

	public function testAnUnknownSetIsRejectedRatherThanStored(): void {
		// Storing it would leave the load step pointing at a set that does not
		// exist, so the failure would surface one step later with no clue why.
		$this->request->method('getParam')->willReturn('atlantis');
		$this->seedProfiles->method('isKnown')->with('atlantis')->willReturn(false);

		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controller->saveConfig();

		$this->assertSame(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testLoadingWithoutAChoiceRefusesRatherThanGuessing(): void {
		// 🔴 NO SILENT DEFAULT. Guessing a set here would plant a municipality
		// into an operator's register because they clicked Run one step early,
		// which is the exact failure this change exists to remove.
		$this->appConfig->method('getValueString')->willReturn('');
		$this->seedProfiles->expects($this->never())->method('install');

		$response = $this->controller->runAction('load-example-set');

		$this->assertSame(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testSkippingClosesBOTHStepsOrTheWizardNeverCloses(): void {
		// 🔴 THE POINT OF THIS TEST. Declining must be persisted, or the wizard
		// re-offers on every visit and "no thanks" is impossible to express.
		//
		// AND IT MUST ANSWER BOTH STEPS. Splitting the old single `demo-data`
		// step into a `choice` plus a `run-action` gave the wizard two
		// outstanding steps, and this action first closed only the second.
		// CnAppRoot opens the wizard while ANY optional step is outstanding, so
		// skipping returned 200 and left the wizard covering every page — the
		// e2e suite then failed on the progress list intercepting clicks
		// Playwright had already resolved.
		$written = [];
		$this->appConfig->method('setValueString')
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;

				return true;
			});

		$response = $this->controller->runAction('skip-example-set');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('skipped', $written['demo_data_decided'] ?? null);
		$this->assertSame('none', $written['example_profile'] ?? null, 'skipping IS choosing none');
	}

	public function testUnknownActionIs404(): void {
		$response = $this->controller->runAction('not-an-action');

		$this->assertSame(404, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testLoadReportsHowMuchLanded(): void {
		$this->appConfig->method('getValueString')->willReturn('municipality');
		$this->seedProfiles->method('install')->with('municipality')
			->willReturn(['objects' => 199, 'profile' => 'municipality']);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('decidiq', 'demo_data_decided', 'installed');

		$data = $this->controller->runAction('load-example-set')->getData();

		$this->assertTrue($data['success']);
		// A success message that names no count cannot be told apart from an
		// import that wrote nothing — the defect this programme already shipped.
		$this->assertStringContainsString('199', $data['message']);
	}

	public function testAFailedLoadIsReportedAndLeavesTheStepUNDECIDED(): void {
		$this->appConfig->method('getValueString')->willReturn('municipality');
		$this->seedProfiles->method('install')
			->willThrowException(new RuntimeException('OpenRegister is not installed.'));

		// 🔴 THE POINT OF THIS TEST. Recording the decision here would close the
		// step for an operator who asked for example data and received none: the
		// wizard would never offer it again, and nothing would have been
		// imported.
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->runAction('load-example-set');

		$this->assertSame(500, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('OpenRegister is not installed.', $response->getData()['message']);
	}
}
