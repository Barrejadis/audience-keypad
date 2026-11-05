<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/AliasResolver.php';

use PHPUnit\Framework\TestCase;

class AliasResolverTest extends TestCase
{
    protected function setUp(): void
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    public function testResolveUnknownAlias(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $result = AliasResolver::resolve('unknown');
        $this->assertNull($result);
    }

    public function testResolveExistingAlias(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        // Create an alias
        $definition = json_encode(['kb' => 'my-device', 'keys' => 'A,B,C', 'limit' => '5']);
        apcu_store('alias:my-alias', $definition);
        
        $result = AliasResolver::resolve('my-alias');
        
        $this->assertNotNull($result);
        $this->assertEquals('my-device', $result['kb']);
        $this->assertEquals('A,B,C', $result['keys']);
        $this->assertEquals('5', $result['limit']);
    }

    public function testResolveAliasWithoutLimit(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        // Create an alias without limit (main mode)
        $definition = json_encode(['kb' => 'my-device', 'keys' => 'A,B']);
        apcu_store('alias:main-alias', $definition);
        
        $result = AliasResolver::resolve('main-alias');
        
        $this->assertNotNull($result);
        $this->assertEquals('my-device', $result['kb']);
        $this->assertEquals('A,B', $result['keys']);
        $this->assertNull($result['limit']);
    }

    public function testExistsKnownAlias(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $definition = json_encode(['kb' => 'my-device', 'keys' => 'A']);
        apcu_store('alias:known', $definition);
        
        $this->assertTrue(AliasResolver::exists('known'));
    }

    public function testExistsUnknownAlias(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $this->assertFalse(AliasResolver::exists('unknown'));
    }

    public function testInvalidAliasName(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $this->assertFalse(AliasResolver::exists('invalid name!'));
        $result = AliasResolver::resolve('invalid name!');
        $this->assertNull($result);
    }

    public function testValidAliasNamePattern(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        // Valid names
        $validNames = ['test', 'Test-123', 'a_b', 'device_1', 'ALIAS'];
        
        foreach ($validNames as $name) {
            $this->assertTrue(preg_match(KB_NAME_RE, $name), "Name '$name' should be valid");
        }
    }

    public function testInvalidAliasNamePattern(): void
    {
        // Invalid names
        $invalidNames = ['', 'with space', 'with@symbol', 'with/slash', str_repeat('a', 33)];
        
        foreach ($invalidNames as $name) {
            $this->assertFalse(preg_match(KB_NAME_RE, $name), "Name '$name' should be invalid");
        }
    }

    public function testAliasDefinitionWithAllFields(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $definition = json_encode([
            'kb' => 'device-1',
            'keys' => 'A,B,C,D',
            'limit' => '10'
        ]);
        apcu_store('alias:full', $definition);
        
        $result = AliasResolver::resolve('full');
        
        $this->assertEquals('device-1', $result['kb']);
        $this->assertEquals('A,B,C,D', $result['keys']);
        $this->assertEquals('10', $result['limit']);
    }
}
