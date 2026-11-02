<?php
/**
 * Golden test for the DecidiqToolProvider catalogue and wiring.
 *
 * Records the exact tool catalogue (key order included), the unknown-tool
 * answer, and which framework service ends up in which property of the tool
 * collaborators the constructor builds. The expected values live in
 * tests/fixtures/mcp-tool-provider-golden.json; run with
 * RECORD_MCP_TOOL_PROVIDER_GOLDEN=1 to write them, then run again without it.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mcp-tools/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidiq\Tests\Unit\Mcp;

use OCA\Decidiq\Mcp\DecidiqToolProvider;
use OCA\Decidiq\Service\MeetingService;
use OCA\Decidiq\Service\ParticipantResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionObject;

/**
 * Golden test for DecidiqToolProvider.
 *
 * @covers \OCA\Decidiq\Mcp\DecidiqToolProvider
 * @uses   \OCA\Decidiq\Mcp\McpActionItemTools
 * @uses   \OCA\Decidiq\Mcp\McpMeetingTools
 * @uses   \OCA\Decidiq\Mcp\McpMeetingGate
 * @uses   \OCA\Decidiq\Mcp\McpMeetingScopeResolver
 * @uses   \OCA\Decidiq\Mcp\McpArgumentValidator
 * @uses   \OCA\Decidiq\Mcp\McpSourceFormatter
 */
class DecidiqToolProviderGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/mcp-tool-provider-golden.json';

	/**
	 * Mock name per object id, for the wiring record.
	 *
	 * @var array<int, string>
	 */
	private array $names = [];

	/**
	 * The catalogue, the unknown-tool answer and the wiring match the recording.
	 *
	 * @return void
	 */
	public function testCatalogueAndWiringMatchTheRecording(): void {
		$mocks = [
			'meetingService'      => $this->createMock(MeetingService::class),
			'userSession'         => $this->createMock(IUserSession::class),
			'groupManager'        => $this->createMock(IGroupManager::class),
			'container'           => $this->createMock(ContainerInterface::class),
			'logger'              => $this->createMock(LoggerInterface::class),
			'participantResolver' => $this->createMock(ParticipantResolver::class),
			'objectService'       => $this->createMock(ObjectServiceInterface::class),
		];
		foreach ($mocks as $name => $mock) {
			$this->names[spl_object_id($mock)] = $name;
		}

		$provider = new DecidiqToolProvider(...$mocks);

		$actual = [
			'appId'   => $provider->getAppId(),
			'tools'   => $provider->getTools(),
			'unknown' => $provider->invokeTool('decidesk.nope', ['meetingUuid' => 'x']),
			'wiring'  => $this->describe($provider, 0),
		];

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('RECORD_MCP_TOOL_PROVIDER_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testCatalogueAndWiringMatchTheRecording()

	/**
	 * Describe an object's properties: a mock by its name, a built object by its class and properties.
	 *
	 * @param object $object The object to describe
	 * @param int    $depth  How deep the walk is
	 *
	 * @return array<string, mixed> Property name to description.
	 */
	private function describe(object $object, int $depth): array {
		$out = ['class' => get_class($object)];
		foreach ((new ReflectionObject($object))->getProperties() as $property) {
			$value = $property->getValue($object);
			$out[$property->getName()] = match (true) {
				is_object($value) && isset($this->names[spl_object_id($value)]) => $this->names[spl_object_id($value)],
				is_object($value) && $depth < 3 => $this->describe($value, $depth + 1),
				is_object($value) => get_class($value),
				default => $value,
			};
		}

		return $out;
	}//end describe()
}//end class
