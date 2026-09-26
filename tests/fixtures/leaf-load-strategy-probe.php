<?php

/**
 * Run decidiq's leaf listeners beside a chosen version of OpenRegister's
 * LeafDescriptor, in a process of its own, and report what registered.
 *
 * WHY A SEPARATE PROCESS. The question this answers is "does the leaf survive an
 * OpenRegister that predates openregister#3956", and answering it means having
 * the OLD LeafDescriptor loaded. One process can hold one version of a class, and
 * the PHPUnit process already holds the current stub, so the old one can only be
 * declared somewhere else. `LeafLoadStrategyCapabilityTest` runs this file for
 * each mode and asserts the output.
 *
 * Nothing here is a test double for its own sake: each mode is a real
 * LeafDescriptor shape that exists in the fleet today.
 *
 *   new     — post-#3956: the LOADS_* constants and the `loadStrategy` parameter.
 *   partial — a backport that took the constants and not the parameter. `defined()`
 *             answers yes and the constructor still throws, which is why the
 *             listener's guard asks both questions.
 *   old     — pre-#3956: neither. What a production instance on an older
 *             OpenRegister actually has.
 *
 * Usage: php tests/fixtures/leaf-load-strategy-probe.php old|partial|new
 * Output: one JSON object on stdout. Exit 0 when both leaves registered.
 *
 * @category Test
 * @package  OCA\Decidiq\Tests
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

$mode = $argv[1] ?? '';
if (in_array($mode, ['old', 'partial', 'new'], true) === false) {
	fwrite(STDERR, "usage: php leaf-load-strategy-probe.php old|partial|new\n");
	exit(2);
}

$root = dirname(__DIR__, 2);
$autoloader = require $root . '/vendor/autoload.php';

// The OCP path dance from tests/bootstrap-unit.php: the symlink resolves on a
// deployed instance and is broken in the bare CI container, which ships OCP.bak.
$ocp = $root . '/vendor/nextcloud/ocp/OCP';
if (is_dir($ocp) === false) {
	$ocp = $root . '/vendor/nextcloud/ocp/OCP.bak';
}

$autoloader->addPsr4('OCP\\', $ocp . '/');
$autoloader->addPsr4('NCU\\', $root . '/vendor/nextcloud/ocp/NCU/');

// The OpenRegister half, declared BEFORE anything can autoload a stub. This file
// deliberately does NOT register the `OCA\OpenRegister\` stub root that
// tests/bootstrap-unit.php adds, so these declarations are the only ones.

$common = <<<'PHP'
	public const KIND_RENDER_SURFACE = 'render-surface';
	public const KIND_DATA_PROVIDER = 'data-provider';
	public const KIND_AGENT_RUNNER = 'agent-runner';
	public const VALID_SURFACES = ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'];
	public const RENDER_MODE_COMPONENT = 'component';
	public const RENDER_MODE_MOUNT = 'mount';
PHP;

$loadConstants = <<<'PHP'
	public const LOADS_VIA_SHARED_ENTRY = 'shared-entry';
	public const LOADS_VIA_OWN_SCRIPT = 'own-script';
	public const LOADS_ALREADY_PRESENT = 'already-present';
PHP;

$loadParameter = "\t\tprivate ?string \$loadStrategy = null,\n";
$loadGetter = "\tpublic function getLoadStrategy(): ?string { return \$this->loadStrategy; }\n";

$constants = ($mode === 'old') ? '' : $loadConstants;
$parameter = ($mode === 'new') ? $loadParameter : '';
$getter = ($mode === 'new') ? $loadGetter : '';

eval(
	"namespace OCA\\OpenRegister\\Service\\Integration;\n"
	. "final class LeafDescriptor {\n"
	. $common . "\n"
	. $constants . "\n"
	. "\tpublic function __construct(\n"
	. "\t\tprivate string \$id,\n"
	. "\t\tprivate string \$label,\n"
	. "\t\tprivate string \$icon,\n"
	. "\t\tprivate array \$kinds,\n"
	. "\t\tprivate ?string \$requiredApp = null,\n"
	. "\t\tprivate ?string \$group = null,\n"
	. "\t\tprivate array \$surfaces = [],\n"
	. "\t\tprivate ?string \$referenceType = null,\n"
	. "\t\tprivate ?string \$requiresPermission = null,\n"
	. "\t\tprivate string \$renderMode = self::RENDER_MODE_COMPONENT,\n"
	. $parameter
	. "\t) {\n\t}\n"
	. "\tpublic function getId(): string { return \$this->id; }\n"
	. $getter
	. "}\n"
);

eval("namespace OCA\\OpenRegister\\Service\\Integration;\ninterface IntegrationProvider {}\n");

eval(
	"namespace OCA\\OpenRegister\\Event;\n"
	. "use OCA\\OpenRegister\\Service\\Integration\\IntegrationProvider;\n"
	. "use OCA\\OpenRegister\\Service\\Integration\\LeafDescriptor;\n"
	. "use OCP\\EventDispatcher\\Event;\n"
	. "class RegisterLeafProvidersEvent extends Event {\n"
	. "\tprivate array \$leaves = [];\n"
	. "\tpublic function registerLeaf(LeafDescriptor \$descriptor, ?IntegrationProvider \$provider = null): void {\n"
	. "\t\t\$this->leaves[] = ['descriptor' => \$descriptor, 'provider' => \$provider];\n"
	. "\t}\n"
	. "\tpublic function getLeaves(): array { return \$this->leaves; }\n"
	. "}\n"
);

/**
 * An l10n that returns its own source string.
 */
final class ProbeL10N implements \OCP\IL10N {

	/**
	 * Translate.
	 *
	 * @param string $text       The source string.
	 * @param mixed  $parameters Unused.
	 *
	 * @return string The source string.
	 */
	public function t(string $text, $parameters = []): string {
		return $text;
	}

	/**
	 * Translate a plural.
	 *
	 * @param string $text_singular The singular form.
	 * @param string $text_plural   The plural form.
	 * @param int    $count         The count.
	 * @param array  $parameters    Unused.
	 *
	 * @return string The singular form.
	 */
	public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
		return $text_singular;
	}

	/**
	 * Localise a value.
	 *
	 * @param string $type    The value type.
	 * @param mixed  $data    The value.
	 * @param array  $options Unused.
	 *
	 * @return string The value as a string.
	 */
	public function l(string $type, $data, array $options = []) {
		return (string) $data;
	}

	/**
	 * The language.
	 *
	 * @return string Always `en`.
	 */
	public function getLanguage(): string {
		return 'en';
	}

	/**
	 * The language code.
	 *
	 * @return string Always `en`.
	 */
	public function getLanguageCode(): string {
		return 'en';
	}

	/**
	 * The locale code.
	 *
	 * @return string Always `en`.
	 */
	public function getLocaleCode(): string {
		return 'en';
	}
}

/**
 * A logger that keeps what the listener swallowed.
 */
final class ProbeLogger extends \Psr\Log\AbstractLogger {

	/**
	 * Everything logged during this run.
	 *
	 * @var array<int, string>
	 */
	public array $messages = [];

	/**
	 * Record a message.
	 *
	 * @param mixed              $level   The log level.
	 * @param \Stringable|string $message The message.
	 * @param array              $context Unused.
	 *
	 * @return void
	 */
	public function log($level, \Stringable|string $message, array $context = []): void {
		$this->messages[] = strtoupper((string) $level) . ': ' . $message;
	}
}

$listeners = [
	'decidiq-approval-chain' => \OCA\Decidiq\Listener\RegisterApprovalChainLeafListener::class,
	'decidesk-decisions' => \OCA\Decidiq\Listener\RegisterDecisionsLeafListener::class,
];

$report = [
	'mode' => $mode,
	'constantDefined' => defined(\OCA\OpenRegister\Service\Integration\LeafDescriptor::class . '::LOADS_VIA_OWN_SCRIPT'),
	'leaves' => [],
];

$registered = 0;
foreach ($listeners as $expectedId => $class) {
	$logger = new ProbeLogger();
	$event = new \OCA\OpenRegister\Event\RegisterLeafProvidersEvent();
	(new $class(new ProbeL10N(), $logger))->handle($event);

	$contributed = $event->getLeaves();
	$registered += count($contributed);

	$descriptor = ($contributed[0]['descriptor'] ?? null);
	$report['leaves'][$expectedId] = [
		'count' => count($contributed),
		'id' => ($descriptor !== null) ? $descriptor->getId() : null,
		'loadStrategy' => ($descriptor !== null && method_exists($descriptor, 'getLoadStrategy'))
			? $descriptor->getLoadStrategy()
			: null,
		'swallowed' => $logger->messages,
	];
}

$report['registered'] = $registered;

echo json_encode($report, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)), "\n";

exit($registered === count($listeners) ? 0 : 1);
