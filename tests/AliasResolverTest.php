<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/KvStore.php';
require_once __DIR__ . '/../src/AliasResolver.php';
require_once __DIR__ . '/InMemoryKvStore.php';

use PHPUnit\Framework\TestCase;

class AliasResolverTest extends TestCase
{
    public function testResolveUnknownAlias(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());
        $result = $resolver->resolve('unknown');
        $this->assertNull($result);
    }

    public function testResolveExistingAlias(): void
    {
        $store = new InMemoryKvStore();
        $definition = json_encode(['kb' => 'my-device', 'keys' => 'A,B,C', 'limit' => '5']);
        $store->set('alias:my-alias', $definition);

        $resolver = new AliasResolver($store);
        $result = $resolver->resolve('my-alias');

        $this->assertNotNull($result);
        $this->assertEquals('my-device', $result['kb']);
        $this->assertEquals('A,B,C', $result['keys']);
        $this->assertEquals('5', $result['limit']);
    }

    public function testResolveAliasWithoutLimit(): void
    {
        $store = new InMemoryKvStore();
        // Create an alias without limit (main mode)
        $definition = json_encode(['kb' => 'my-device', 'keys' => 'A,B']);
        $store->set('alias:main-alias', $definition);

        $resolver = new AliasResolver($store);
        $result = $resolver->resolve('main-alias');

        $this->assertNotNull($result);
        $this->assertEquals('my-device', $result['kb']);
        $this->assertEquals('A,B', $result['keys']);
        $this->assertNull($result['limit']);
    }

    public function testExistsKnownAlias(): void
    {
        $store = new InMemoryKvStore();
        $definition = json_encode(['kb' => 'my-device', 'keys' => 'A']);
        $store->set('alias:known', $definition);

        $resolver = new AliasResolver($store);
        $this->assertTrue($resolver->exists('known'));
    }

    public function testExistsUnknownAlias(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());
        $this->assertFalse($resolver->exists('unknown'));
    }

    public function testInvalidAliasName(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());
        $this->assertFalse($resolver->exists('invalid name!'));
        $result = $resolver->resolve('invalid name!');
        $this->assertNull($result);
    }

    public function testValidAliasNamePattern(): void
    {
        // Valid names
        $validNames = ['test', 'Test-123', 'a_b', 'device_1', 'ALIAS'];

        foreach ($validNames as $name) {
            $this->assertSame(1, preg_match(KB_NAME_RE, $name), "Name '$name' should be valid");
        }
    }

    public function testInvalidAliasNamePattern(): void
    {
        // Invalid names
        $invalidNames = ['', 'with space', 'with@symbol', 'with/slash', str_repeat('a', 33)];

        foreach ($invalidNames as $name) {
            $this->assertSame(0, preg_match(KB_NAME_RE, $name), "Name '$name' should be invalid");
        }
    }

    public function testAliasDefinitionWithAllFields(): void
    {
        $store = new InMemoryKvStore();
        $definition = json_encode([
            'kb' => 'device-1',
            'keys' => 'A,B,C,D',
            'limit' => '10'
        ]);
        $store->set('alias:full', $definition);

        $resolver = new AliasResolver($store);
        $result = $resolver->resolve('full');

        $this->assertEquals('device-1', $result['kb']);
        $this->assertEquals('A,B,C,D', $result['keys']);
        $this->assertEquals('10', $result['limit']);
    }

    public function testUpsertStoresDefinition(): void
    {
        $store = new InMemoryKvStore();
        $resolver = new AliasResolver($store);

        $resolver->upsert('act', 'act1', 'A,B,C,D', null);

        $this->assertTrue($resolver->exists('act'));
        $result = $resolver->resolve('act');
        $this->assertSame('act1', $result['kb']);
        $this->assertSame('A,B,C,D', $result['keys']);
        $this->assertNull($result['limit']);
        // Stored as JSON under alias:{name}
        $this->assertSame('act1', json_decode($store->get('alias:act'), true)['kb']);
    }

    public function testUpsertWithLimit(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $resolver->upsert('quiz', 'quiz1', 'A,B', '5');

        $result = $resolver->resolve('quiz');
        $this->assertSame('5', $result['limit']);
    }

    public function testUpsertOverwritesBinding(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());
        $resolver->upsert('act', 'act1', 'A,B,C,D', null);

        $resolver->upsert('act', 'act2', 'E,F', null);

        $result = $resolver->resolve('act');
        $this->assertSame('act2', $result['kb']);
        $this->assertSame('E,F', $result['keys']);
    }

    public function testUpsertIdenticalDefinitionIsNoOp(): void
    {
        $store = new InMemoryKvStore();
        $resolver = new AliasResolver($store);
        $resolver->upsert('act', 'act1', 'A,B,C,D', '7');
        $before = $store->get('alias:act');

        $resolver->upsert('act', 'act1', 'A,B,C,D', '7');

        $this->assertSame($before, $store->get('alias:act'));
    }

    public function testUpsertTargetDeviceNeedNotExist(): void
    {
        $store = new InMemoryKvStore();
        $resolver = new AliasResolver($store);

        // No kb:ghost:* entries exist; the binding is still valid.
        $resolver->upsert('act', 'ghost', 'A,B', null);

        $this->assertTrue($resolver->exists('act'));
        $this->assertSame([], $store->entriesWithPrefix('kb:ghost:'));
    }

    public function testAliasMayShareItsNameWithADevice(): void
    {
        $store = new InMemoryKvStore();
        $resolver = new AliasResolver($store);

        $resolver->upsert('dev', 'dev', 'A,B', null);

        $this->assertTrue($resolver->exists('dev'));
        $this->assertSame('dev', $resolver->resolve('dev')['kb']);
    }

    public function testUpsertRejectsInvalidAliasName(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $this->expectException(InvalidArgumentException::class);
        $resolver->upsert('bad name!', 'act1', 'A,B', null);
    }

    public function testUpsertRejectsInvalidDeviceName(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $this->expectException(InvalidArgumentException::class);
        $resolver->upsert('act', 'bad kb', 'A,B', null);
    }

    public function testUpsertRejectsInvalidKeyboardSize(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $this->expectException(InvalidArgumentException::class);
        $resolver->upsert('act', 'act1', 'A,B,C', null); // 3 cells is not a keyboard size
    }

    public function testUpsertRejectsDuplicateActiveLabels(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $this->expectException(InvalidArgumentException::class);
        $resolver->upsert('act', 'act1', 'A,A,B,C', null);
    }

    public function testUpsertRejectsInvalidLimit(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        foreach (['0', '301', 'abc', '-1'] as $bad) {
            try {
                $resolver->upsert('act', 'act1', 'A,B', $bad);
                $this->fail("Limit '$bad' should be rejected");
            } catch (InvalidArgumentException $e) {
                // expected
            }
        }

        // Boundary values are accepted
        $resolver->upsert('act', 'act1', 'A,B', '1');
        $resolver->upsert('act', 'act1', 'A,B', (string)MAX_STROKES);
        $this->assertTrue($resolver->exists('act'));
    }

    public function testDeleteRemovesBinding(): void
    {
        $store = new InMemoryKvStore();
        $resolver = new AliasResolver($store);
        $resolver->upsert('act', 'act1', 'A,B,C,D', null);

        $resolver->delete('act');

        $this->assertFalse($resolver->exists('act'));
        $this->assertNull($resolver->resolve('act'));
        $this->assertFalse($store->exists('alias:act'));
    }

    public function testDeleteUnknownAliasIsNoOp(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $resolver->delete('never-existed'); // must not throw

        $this->assertFalse($resolver->exists('never-existed'));
    }

    public function testDeleteRejectsMalformedName(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $this->expectException(InvalidArgumentException::class);
        $resolver->delete('bad name!');
    }

    public function testHandleRejectsWrongPassword(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $result = $resolver->handle('wrong', 'alias', 'act', 'act1', 'A,B,C,D', null);

        $this->assertSame(401, $result['status']);
        $this->assertFalse($result['body']['ok']);
        $this->assertFalse($resolver->exists('act'));
    }

    public function testHandleRejectsNonStringPassword(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $result = $resolver->handle(null, 'alias', 'act', 'act1', 'A,B', null);

        $this->assertSame(401, $result['status']);
    }

    public function testHandleUpsert(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $result = $resolver->handle('admin', 'alias', 'act', 'act1', 'A,B,C,D', null);

        $this->assertSame(200, $result['status']);
        $this->assertSame(['ok' => true], $result['body']);
        $this->assertSame('act1', $resolver->resolve('act')['kb']);
    }

    public function testHandleUpsertWithIntegerLimit(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $result = $resolver->handle('admin', 'alias', 'quiz', 'quiz1', 'A,B', 5);

        $this->assertSame(200, $result['status']);
        $this->assertSame('5', $resolver->resolve('quiz')['limit']);
    }

    public function testHandleUpsertStructuralFailure(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $result = $resolver->handle('admin', 'alias', 'act', 'act1', 'A,B,C', '0');

        $this->assertSame(400, $result['status']);
        $this->assertFalse($result['body']['ok']);
        $this->assertFalse($resolver->exists('act'));
    }

    public function testHandleUpsertMissingFields(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $result = $resolver->handle('admin', 'alias', 'act', 'act1', null, null);

        $this->assertSame(400, $result['status']);
        $this->assertFalse($resolver->exists('act'));
    }

    public function testHandleDelete(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());
        $resolver->upsert('act', 'act1', 'A,B,C,D', null);

        $result = $resolver->handle('admin', 'alias-del', 'act', null, null, null);

        $this->assertSame(200, $result['status']);
        $this->assertSame(['ok' => true], $result['body']);
        $this->assertFalse($resolver->exists('act'));
    }

    public function testHandleDeleteUnknownAliasIsNoOp(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $result = $resolver->handle('admin', 'alias-del', 'ghost', null, null, null);

        $this->assertSame(200, $result['status']);
        $this->assertSame(['ok' => true], $result['body']);
    }

    public function testHandleDeleteMalformedName(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $result = $resolver->handle('admin', 'alias-del', 'bad name!', null, null, null);

        $this->assertSame(400, $result['status']);
    }

    public function testHandleUnknownAction(): void
    {
        $resolver = new AliasResolver(new InMemoryKvStore());

        $result = $resolver->handle('admin', 'nope', 'act', 'act1', 'A,B', null);

        $this->assertSame(400, $result['status']);
        $this->assertFalse($resolver->exists('act'));
    }
}
