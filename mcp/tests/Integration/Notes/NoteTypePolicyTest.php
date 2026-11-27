<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Notes;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * Native Notes operations against a real Nextcloud with legacy extension policy values.
 */
#[Group('notes')]
final class NoteTypePolicyTest extends ExclusionTestCase {
	private McpClient $alice;

	/** Alice's client. The policy key is removed after every test, including when the test failed. */
	protected function setUp(): void {
		parent::setUp();
		$this->alice = $this->mcp();
	}

	/** Leaves the policy key absent so a later test does not observe this slice's value. */
	protected function tearDown(): void {
		$this->clearPolicy();
		parent::tearDown();
	}

	/** @param list<string> $extensions Canonical allowlist. */
	private function allow(array $extensions): void {
		$config = Server::get(IAppConfig::class);
		$config->setValueString(FileWritePolicy::APP, FileWritePolicy::KEY, json_encode([
			'version' => 1, 'mode' => 'allowlist', 'extensions' => $extensions, 'allowExtensionless' => false,
		], JSON_THROW_ON_ERROR), false);
		$config->clearCache(false);
	}

	/** Removes the policy key when this test created one. */
	private function clearPolicy(): void {
		$config = Server::get(IAppConfig::class);
		$config->clearCache(false);
		if ($config->hasKey(FileWritePolicy::APP, FileWritePolicy::KEY, null)) {
			$config->deleteKey(FileWritePolicy::APP, FileWritePolicy::KEY);
		}
		$config->clearCache(false);
	}

	/**
	 * @param string $name File name inside this run's notes category.
	 * @param string $content Stored bytes.
	 * @return File The planted note. It is not created through MCP.
	 */
	private function plant(string $name, string $content): File {
		$folder = $this->ensureFolder(self::ALICE, 'Notes/' . $this->run);
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $this->run));
		$file = $folder->newFile($name, $content);
		$this->assertInstanceOf(File::class, $file);
		return $file;
	}

	/** @return string Current bytes of a planted note. */
	private function stored(string $name): string {
		$node = $this->node(self::ALICE, 'Notes/' . $this->run . '/' . $name);
		$this->assertInstanceOf(File::class, $node);
		return (string)$node->getContent();
	}

	/** A plan does not create a category; confirmation writes a note even under legacy txt-only settings. */
	public function testCreateIgnoresLegacyAllowlist(): void {
		$this->allow(['txt']);
		$category = $this->run . '-nova';
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $category));
		$arguments = ['title' => 'Ata', 'content' => 'x', 'category' => $category];
		$plan = $this->alice->tool('notes_create', $arguments);
		$this->assertTrue($plan->ok(), $plan->describe());
		$this->assertFalse($this->exists(self::ALICE, 'Notes/' . $category));
		$written = $this->alice->write('notes_create', $arguments);
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertSame('x', $this->node(self::ALICE, 'Notes/' . $category . '/Ata.md')->getContent());
	}

	/** Create with md writes the note. */
	public function testCreateAllowsMd(): void {
		$this->allow(['md']);
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $this->run));
		$written = $this->alice->write('notes_create', [
			'title' => 'Permitida',
			'content' => 'ok',
			'category' => $this->run,
		]);
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertSame('ok', $this->stored('Permitida.md'));
	}

	/** No policy key leaves create as it was. */
	public function testCreateWithThePolicyOffIsUnchanged(): void {
		$this->clearPolicy();
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $this->run));
		$written = $this->alice->write('notes_create', [
			'title' => 'Livre',
			'content' => 'ok',
			'category' => $this->run,
		]);
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertSame('ok', $this->stored('Livre.md'));
	}

	/** Edit and rename a valid note together without mutating anything during planning. */
	public function testWholeEditIgnoresLegacyAllowlist(): void {
		$this->allow(['txt']);
		$note = $this->plant('Ok.md', 'antes');
		$id = $note->getId();
		$arguments = ['id' => (int)$id, 'content' => 'depois', 'title' => 'Renamed'];
		$plan = $this->alice->tool('notes_edit', $arguments);
		$this->assertTrue($plan->ok(), $plan->describe());
		$this->assertSame('antes', $this->stored('Ok.md'));
		$this->assertFalse($this->exists(self::ALICE, 'Notes/' . $this->run . '/Renamed.md'));
		$written = $this->alice->write('notes_edit', $arguments);
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertFalse($this->exists(self::ALICE, 'Notes/' . $this->run . '/Ok.md'));
		$this->assertSame('depois', $this->stored('Renamed.md'));
		$this->assertSame($id, $this->node(self::ALICE, 'Notes/' . $this->run . '/Renamed.md')->getId());
	}

	/** Changing stored legacy configuration cannot revoke a valid append plan. */
	public function testAppendConfirmationIgnoresLegacyPolicyChange(): void {
		$this->allow(['md']);
		$note = $this->plant('Chamados.md', "# Chamados\n");
		$planReply = $this->alice->tool('notes_edit', ['id' => (int)$note->getId(), 'append' => '- 102']);
		$this->assertTrue($planReply->ok(), $planReply->describe());
		$plan = $planReply->structured();
		$this->allow(['txt']);
		$written = $this->alice->write('notes_edit', [
			'id' => (int)$note->getId(),
			'append' => '- 102',
			'etag' => $plan['etag'],
			'plan_state' => $plan['plan_state'],
		]);
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertSame("# Chamados\n- 102", $this->stored('Chamados.md'));
	}

	/** Move planning leaves the destination absent; confirmation preserves note identity and bytes. */
	public function testMoveIgnoresLegacyAllowlist(): void {
		$this->allow(['txt']);
		$note = $this->plant('Ok.md', 'fica');
		$category = $this->run . '-destino';
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $category));
		$arguments = ['id' => (int)$note->getId(), 'category' => $category];
		$plan = $this->alice->tool('notes_move', $arguments);
		$this->assertTrue($plan->ok(), $plan->describe());
		$this->assertSame('fica', $this->stored('Ok.md'));
		$this->assertFalse($this->exists(self::ALICE, 'Notes/' . $category));
		$id = $note->getId();
		$written = $this->alice->write('notes_move', $arguments);
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertFalse($this->exists(self::ALICE, 'Notes/' . $this->run . '/Ok.md'));
		$moved = $this->node(self::ALICE, 'Notes/' . $category . '/Ok.md');
		$this->assertSame($id, $moved->getId());
		$this->assertSame('fica', $moved->getContent());
	}

	/** Delete of an md note still follows the trash rule when md is not allowed. */
	public function testDeleteStillRemovesAnMdNoteWhenMdIsDenied(): void {
		$this->allow(['txt']);
		$note = $this->plant('Ok.md', 'lixo');
		$written = $this->alice->write('notes_delete', ['id' => (int)$note->getId()]);
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertTrue($written->json()['deleted'] ?? false);
		$this->assertFalse($this->exists(self::ALICE, 'Notes/' . $this->run . '/Ok.md'));
	}

	/** Malformed legacy configuration is ignored and preserved for rollback. */
	public function testUnreadableLegacyPolicyDoesNotBlockCreate(): void {
		$config = Server::get(IAppConfig::class);
		$config->setValueString(FileWritePolicy::APP, FileWritePolicy::KEY, 'not-json', false);
		$config->clearCache(false);
		$category = $this->run . '-quebrada';
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $category));
		$reply = $this->alice->write('notes_create', ['title' => 'Quebrada', 'content' => 'x', 'category' => $category]);
		$this->assertTrue($reply->ok(), $reply->describe());
		$this->assertSame('x', $this->node(self::ALICE, 'Notes/' . $category . '/Quebrada.md')->getContent());
		$config->clearCache(false);
		$this->assertSame('not-json', $config->getValueString(FileWritePolicy::APP, FileWritePolicy::KEY));
	}
}
