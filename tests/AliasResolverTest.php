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
}
