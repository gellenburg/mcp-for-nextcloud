<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Confirmation;

use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Mcp\Tools\ToolPresentation;

/** Write classification, optional previews and server-side preparation. */
final class WriteGate {
	/** Argument every writing tool accepts, and the only thing that lets it run. */
	public const CONFIRM = 'confirm';
	/** The one operation that does not change anything. */
	private const READ_OPERATION = 'read';
	public const SHARED = 'confirm_shared';

	public static function directConfirmProperty(): array {
		return ['type' => 'boolean', 'description' => 'Optional dry run: false returns a preview. Omit to execute under configured grants and native permissions.'];
	}

	public static function isDirectSchema(array $definition): bool {
		return (((array)($definition['inputSchema']['properties'] ?? []))[self::CONFIRM] ?? null) === self::directConfirmProperty();
	}

	/** Preserve caller preconditions and add only declared, server-generated state receipts. */
	public static function directArguments(array $definition, array $arguments, array $plan): array {
		$properties = (array)($definition['inputSchema']['properties'] ?? []);
		$arguments[self::CONFIRM] = true;
		foreach (['etag', 'id', PlanState::ARGUMENT, 'file_state'] as $field) {
			if (isset($properties[$field], $plan[$field]) && !array_key_exists($field, $arguments)) {
				$arguments[$field] = $plan[$field];
			}
		}
		if (isset($properties[self::SHARED]) && !array_key_exists(self::SHARED, $arguments)) {
			$arguments[self::SHARED] = true;
		}
		return $arguments;
	}

	private function __construct() {
	}

	/**
	 * @param array{operation?:string} $definition tool definition
	 * @return bool whether this tool may change data
	 */
	public static function isWrite(array $definition): bool {
		return ($definition['operation'] ?? self::READ_OPERATION) !== self::READ_OPERATION;
	}

	/**
	 * The `confirm` property every writing tool is published with.
	 *
	 * @return array{type: string, description: string}
	 */
	public static function confirmProperty(): array {
		return ['type' => 'boolean', 'description' => CommonMessages::confirmParameter()];
	}

	/**
	 * Publishes a definition: a write always carries `confirm`, and never demands it.
	 *
	 * @param array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string} $definition
	 * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string} the definition as the client sees it
	 */
	public static function publish(array $definition, bool $direct = false): array {
		if (!self::isWrite($definition)) {
			return $definition;
		}
		$schema = $definition['inputSchema'];
		$properties = (array)($schema['properties'] ?? []);
		// The property is set, never merged: one wording for every module is what tells the model the rule once.
		$properties[self::CONFIRM] = $direct ? self::directConfirmProperty() : self::confirmProperty();
		if ($direct && isset($properties[self::SHARED])) {
			unset($properties[self::SHARED]['default']);
			$properties[self::SHARED]['description'] = 'Optional shared-resource opt-out: false excludes shared effects; omission uses existing grants and native permissions.';
		}
		if ($direct && isset($properties[PlanState::ARGUMENT])) {
			$properties[PlanState::ARGUMENT]['description'] = 'Optional opaque precondition from an explicit preview. Omit for server-side preparation, or pass unchanged to bind execution to that state. A mismatch prevents the write.';
		}
		$schema['properties'] = $properties === [] ? new \stdClass() : $properties;
		if (isset($schema['required'])) {
			$required = array_values(array_diff((array)$schema['required'], [self::CONFIRM]));
			if ($required === []) {
				unset($schema['required']);
			} else {
				$schema['required'] = $required;
			}
		}
		$definition['inputSchema'] = $schema;
		return $definition;
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @return bool whether the caller confirmed the write
	 */
	public static function confirmed(array $arguments): bool {
		return ($arguments[self::CONFIRM] ?? false) === true;
	}

	/**
	 * The plan a call without `confirm: true` answers with.
	 *
	 * The envelope is written here, so a module's own plan cannot answer without saying that nothing
	 * was changed; `+` keeps the module's keys, `requiresConfirmation` first so it is never the one lost.
	 *
	 * @param ToolModule $module module that serves the tool
	 * @param array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string} $definition
	 * @param array<string, mixed> $arguments validated arguments
	 * @param string $userId authenticated user
	 * @return array<string, mixed> the structured content of a non-error result
	 */
	public static function plan(ToolModule $module, array $definition, array $arguments, string $userId): array {
		$plan = $module instanceof PreviewsWrites
			? $module->preview($definition['name'], $arguments, $userId)
			: self::generic($definition, $arguments);
		return self::envelope($definition, $plan);
	}

	/**
	 * The envelope of every plan, the one a call without `confirm` gets and the one a confirmed call whose state
	 * changed gets ({@see PlanChanged}): `requiresConfirmation` first and always true, whatever the module put there.
	 *
	 * @param array{name:string} $definition tool definition
	 * @param array<string, mixed> $plan the module's plan
	 * @return array<string, mixed> the structured content of a non-error result
	 */
	public static function envelope(array $definition, array $plan): array {
		return [
			'requiresConfirmation' => true,
			'tool' => $definition['name'],
			'title' => ToolPresentation::title($definition['name']),
		] + $plan;
	}

	/**
	 * The plan of a module that does not describe its own writes: the tool, its arguments and the
	 * instruction to show them to the user. Enough for the model to ask; the confirmed call then runs.
	 *
	 * @param array{name:string} $definition
	 * @param array<string, mixed> $arguments
	 * @return array<string, mixed>
	 */
	private static function generic(array $definition, array $arguments): array {
		unset($arguments[self::CONFIRM]);
		return [
			'action' => $definition['name'],
			'arguments' => $arguments,
			'message' => CommonMessages::planNothingChanged(),
		];
	}
}
