<?php

/**
 * Test stub for OpenRegister LeafDescriptor.
 *
 * Stands in for OCA\OpenRegister\Service\Integration\LeafDescriptor when
 * OpenRegister is not installed (standalone CI). Mirrors the real value object's
 * constants, constructor signature and FULL public method surface, so a
 * descriptor that would be rejected by the real class is rejected here too. The
 * real class ships with OpenRegister
 * (lib/Service/Integration/LeafDescriptor.php, ADR-066).
 *
 * Checked against openregister `development` when written: same 8 constants, same
 * 10 parameters in the same order with the same defaults, same 11 public methods.
 * A stub that has drifted from the class it doubles reports on code that does not
 * exist — verify it against the real file before trusting a green run here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Minimal LeafDescriptor stub for standalone unit runs.
 */
final class LeafDescriptor {

	public const KIND_RENDER_SURFACE = 'render-surface';

	public const KIND_DATA_PROVIDER = 'data-provider';

	public const KIND_AGENT_RUNNER = 'agent-runner';

	public const VALID_KINDS = [
		self::KIND_RENDER_SURFACE,
		self::KIND_DATA_PROVIDER,
		self::KIND_AGENT_RUNNER,
	];

	public const VALID_SURFACES = [
		'user-dashboard',
		'app-dashboard',
		'detail-page',
		'single-entity',
	];

	public const RENDER_MODE_COMPONENT = 'component';

	public const RENDER_MODE_MOUNT = 'mount';

	public const VALID_RENDER_MODES = [
		self::RENDER_MODE_COMPONENT,
		self::RENDER_MODE_MOUNT,
	];

	/**
	 * Constructor — same parameter order and defaults as the real descriptor.
	 *
	 * @param string $id Stable kebab-case id, equal to the JS registration id.
	 * @param string $label Human-readable label (already translated by the app).
	 * @param string $icon Material Design Icons name (no `mdi-` prefix).
	 * @param array<int,string> $kinds Non-empty subset of VALID_KINDS.
	 * @param string|null $requiredApp NC app id that must be installed/enabled, or null.
	 * @param string|null $group Optional group used to cluster leaves in admin UI.
	 * @param array<int,string> $surfaces Render surfaces the leaf targets.
	 * @param string|null $referenceType Optional single-entity reference marker (AD-18).
	 * @param string|null $requiresPermission Optional permission string gating visibility.
	 * @param string $renderMode `component` (an SFC under the host's Vue runtime) or
	 *                           `mount` (a mount/unmount DOM hand-off crossing a Vue
	 *                           major). The real descriptor gained this parameter in
	 *                           openregister#2127 / ADR-066; a stub without it would let
	 *                           a listener that omits renderMode pass here and blank the
	 *                           surface in production.
	 */
	public function __construct(
		private string $id,
		private string $label,
		private string $icon,
		private array $kinds,
		private ?string $requiredApp = null,
		private ?string $group = null,
		private array $surfaces = [],
		private ?string $referenceType = null,
		private ?string $requiresPermission = null,
		private string $renderMode = self::RENDER_MODE_COMPONENT,
	) {
	}//end __construct()

	/**
	 * Stable kebab-case identifier, equal to the JS registration id.
	 *
	 * @return string The leaf id.
	 */
	public function getId(): string {
		return $this->id;
	}//end getId()

	/**
	 * Human-readable label.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string {
		return $this->label;
	}//end getLabel()

	/**
	 * Material Design Icons name.
	 *
	 * @return string The icon name.
	 */
	public function getIcon(): string {
		return $this->icon;
	}//end getIcon()

	/**
	 * The kinds this leaf declares.
	 *
	 * @return array<int,string> A non-empty subset of VALID_KINDS.
	 */
	public function getKinds(): array {
		return $this->kinds;
	}//end getKinds()

	/**
	 * Whether this leaf declares the given kind.
	 *
	 * @param string $kind One of the KIND_* constants.
	 *
	 * @return bool True when declared.
	 */
	public function hasKind(string $kind): bool {
		return in_array($kind, $this->kinds, true);
	}//end hasKind()

	/**
	 * NC app id that must be installed/enabled for this leaf to be usable.
	 *
	 * @return string|null The app id, or null when always available.
	 */
	public function getRequiredApp(): ?string {
		return $this->requiredApp;
	}//end getRequiredApp()

	/**
	 * Optional admin-UI grouping.
	 *
	 * @return string|null The group.
	 */
	public function getGroup(): ?string {
		return $this->group;
	}//end getGroup()

	/**
	 * Render surfaces the leaf targets.
	 *
	 * @return array<int,string> A subset of VALID_SURFACES.
	 */
	public function getSurfaces(): array {
		return $this->surfaces;
	}//end getSurfaces()

	/**
	 * Optional single-entity reference marker (ADR-019 AD-18).
	 *
	 * @return string|null The reference type.
	 */
	public function getReferenceType(): ?string {
		return $this->referenceType;
	}//end getReferenceType()

	/**
	 * Optional permission string gating visibility.
	 *
	 * @return string|null The permission.
	 */
	public function requiresPermission(): ?string {
		return $this->requiresPermission;
	}//end requiresPermission()

	/**
	 * The capability row for this leaf.
	 *
	 * Mirrors the real class EXACTLY, including the fact that `referenceType` and
	 * `requiresPermission` are NOT in it — see
	 * `LeafRegistry::describeForCapabilities()`, which is this array plus a
	 * `usable` flag. A stub that helpfully added them would make a test assert a
	 * capability payload that OpenRegister never emits.
	 *
	 * @return array<string,mixed> The descriptor as the capability exposes it.
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'label' => $this->label,
			'icon' => $this->icon,
			'requiredApp' => $this->requiredApp,
			'group' => $this->group,
			'surfaces' => $this->surfaces,
			'kinds' => $this->kinds,
			'renderMode' => $this->renderMode,
		];
	}//end toArray()

	/**
	 * How a render-surface leaf renders.
	 *
	 * @return string One of VALID_RENDER_MODES.
	 */
	public function getRenderMode(): string {
		return $this->renderMode;
	}//end getRenderMode()
}//end class
