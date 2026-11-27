<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Files;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Modules\Files\Reorganization\Batch;
use OCA\Mcp\Modules\Files\Reorganization\BatchStore;
use OCP\IAppConfig;
use OCP\Server;

/** Real OAuth batch/undo boundaries, native identity and missing-parent recovery under legacy configuration. */
final class TreeBatchPolicyHttpTest extends ExclusionTestCase {
	/** Seed API fixtures before enabling the policy used by the real MCP endpoint.
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->makeFile('home', 'a.txt', 'alpha');
		$this->makeFile('home', 'b.txt', 'beta');
		$config = Server::get(IAppConfig::class);
		$config->setValueString('mcp', FileWritePolicy::KEY, '{"version":1,"mode":"allowlist","extensions":["txt"],"allowExtensionless":false}', false);
		$config->clearCache(false);
	}

	/** Restore the absent policy key; the base only cleans its own disposable integration fixtures.
	 * @return void
	 */
	protected function tearDown(): void {
		$config = Server::get(IAppConfig::class);
		$config->deleteKey('mcp', FileWritePolicy::KEY);
		$config->clearCache(false);
		parent::tearDown();
	}

	/** Planning is side-effect free; confirmation creates the destination and moves the original ID. */
	public function testBatchAcceptsUnlistedExtensionWithoutPrematureChanges(): void {
		$client = $this->mcp(self::ALICE, 'bearer');
		$parent = $this->path('home', 'new');
		$from = $this->path('home', 'a.txt');
		$to = $parent . '/stage.php';
		$id = $this->node(self::ALICE, $from)->getId();
		$args = ['mkdirs' => [$parent], 'moves' => [['from' => $from, 'to' => $to]]];
		$plan = $client->tool('files_move_batch', $args);
		self::assertTrue($plan->ok(), $plan->describe());
		self::assertTrue($plan->structured()['ok'], $plan->raw);
		self::assertFalse($this->exists(self::ALICE, $parent));
		self::assertSame('alpha', $this->node(self::ALICE, $from)->getContent());
		$reply = $client->write('files_move_batch', $args);
		self::assertTrue($reply->ok(), $reply->describe());
		self::assertFalse($this->exists(self::ALICE, $from));
		self::assertSame($id, $this->node(self::ALICE, $to)->getId());
		self::assertSame('alpha', $this->node(self::ALICE, $to)->getContent());
	}

	/** Undo recreates the recorded missing parent and restores the same file ID and bytes. */
	public function testUndoRestoresUnlistedOriginalName(): void {
		$store = Server::get(BatchStore::class);
		$parent = $this->path('home', 'missing');
		$from = $this->path('home', 'a.txt');
		$to = $parent . '/original.php';
		$node = $this->node(self::ALICE, $from);
		$nodeId = $node->getId();
		$id = $store->insert(new Batch(null, self::ALICE, time(), [[
			'from' => $to, 'to' => $from, 'toId' => (int)$nodeId,
		]], [$parent], null));
		$before = $store->find($id, self::ALICE)->toRow();
		$client = $this->mcp(self::ALICE, 'bearer');
		$plan = $client->tool('files_undo_batch', ['batch_id' => $id]);
		self::assertTrue($plan->ok(), $plan->describe());
		self::assertTrue($plan->structured()['ok'], $plan->raw);
		self::assertSame($before, $store->find($id, self::ALICE)->toRow());
		self::assertFalse($this->exists(self::ALICE, $parent));
		self::assertSame('alpha', $this->node(self::ALICE, $from)->getContent());
		$reply = $client->write('files_undo_batch', ['batch_id' => $id]);
		self::assertTrue($reply->ok(), $reply->describe());
		self::assertSame(1, $reply->json()['undone']);
		self::assertFalse($this->exists(self::ALICE, $from));
		self::assertSame($nodeId, $this->node(self::ALICE, $to)->getId());
		self::assertSame('alpha', $this->node(self::ALICE, $to)->getContent());
	}

	/** An entirely allowed batch and inverse preserve the existing native identity and receipt.
	 * @return void
	 */
	public function testAllowedBatchAndUndoPreserveNativeIdentity(): void {
		$client = $this->mcp(self::ALICE, 'bearer');
		$from = $this->path('home', 'a.txt');
		$to = $this->path('home', 'moved.txt');
		$id = $this->node(self::ALICE, $from)->getId();
		$reply = $client->write('files_move_batch', ['moves' => [['from' => $from, 'to' => $to]]]);
		self::assertTrue($reply->ok(), $reply->describe());
		self::assertSame($id, $this->node(self::ALICE, $to)->getId());
		$undo = $client->write('files_undo_batch', ['batch_id' => $reply->json()['batch_id']]);
		self::assertTrue($undo->ok(), $undo->describe());
		self::assertSame(1, $undo->json()['undone']);
		self::assertSame($id, $this->node(self::ALICE, $from)->getId());
		self::assertSame('alpha', $this->node(self::ALICE, $from)->getContent());
	}
}
