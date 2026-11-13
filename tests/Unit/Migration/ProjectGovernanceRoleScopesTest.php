<?php

/**
 * Unit tests for ProjectGovernanceRoleScopes.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Migration;

use OCA\Decidiq\Migration\ProjectGovernanceRoleScopes;
use OCA\Decidiq\Service\GovernanceRoleScopeProjector;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The repair step that backfills governance RBAC scopes.
 *
 * @covers \OCA\Decidiq\Migration\ProjectGovernanceRoleScopes
 */
final class ProjectGovernanceRoleScopesTest extends TestCase {

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItDoes(): void {
		$step = new ProjectGovernanceRoleScopes(
			projector: $this->createMock(GovernanceRoleScopeProjector::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertSame('Project Decidiq governance-body roles into OpenRegister RBAC scopes', $step->getName());

	}//end testTheStepNamesWhatItDoes()

	/**
	 * The reconciled body count is reported.
	 *
	 * @return void
	 */
	public function testTheReconciledCountIsReported(): void {
		$projector = $this->createMock(GovernanceRoleScopeProjector::class);
		$projector->expects(self::once())->method('reconcileAll')->willReturn(4);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('Reconciled governance RBAC scopes for 4 body/bodies.');
		$output->expects(self::never())->method('warning');

		(new ProjectGovernanceRoleScopes(projector: $projector, logger: $logger))->run($output);

	}//end testTheReconciledCountIsReported()

	/**
	 * A failing projector is logged and warned about, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingProjectorFailsSoft(): void {
		$projector = $this->createMock(GovernanceRoleScopeProjector::class);
		$projector->method('reconcileAll')->willThrowException(new RuntimeException('register not ready'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Decidiq: governance role scope backfill skipped',
			['exception' => 'register not ready']
		);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');
		$output->expects(self::once())->method('warning')->with('Governance RBAC scope backfill skipped: register not ready');

		(new ProjectGovernanceRoleScopes(projector: $projector, logger: $logger))->run($output);

	}//end testAFailingProjectorFailsSoft()
}//end class
