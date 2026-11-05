<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/KeyboardDefinition.php';
require_once __DIR__ . '/../src/DeviceManager.php';

use PHPUnit\Framework\TestCase;

class DeviceManagerTest extends TestCase
{
    protected function setUp(): void
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    public function testDeviceDoesNotExistInitially(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('new-device');
        $this->assertFalse($manager->exists());
    }

    public function testDeviceExistsAfterRecording(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('test-device');
        $manager->recordMode('main', null);
        $this->assertTrue($manager->exists());
    }

    public function testGetModeMain(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('test-device');
        $manager->recordMode('main', null);
        $this->assertEquals('main', $manager->getMode());
    }

    public function testGetModeLimited(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('test-device');
        $manager->recordMode('limited', 5);
        $this->assertEquals('limited', $manager->getMode());
        $this->assertEquals(5, $manager->getLimit());
    }

    public function testGetLimitMainMode(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('test-device');
        $manager->recordMode('main', null);
        $this->assertNull($manager->getLimit());
    }

    public function testValidateDefinitionFirstAccess(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('new-device');
        $def = KeyboardDefinition::fromParameters('new-device', 'A,B', null);
        
        $this->assertTrue($manager->validateDefinition($def));
    }

    public function testValidateDefinitionModeConflict(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('test-device');
        $manager->recordMode('main', null);
        
        $def = KeyboardDefinition::fromParameters('test-device', 'A,B', '5'); // limited
        $this->assertFalse($manager->validateDefinition($def));
    }

    public function testValidateDefinitionLimitConflict(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('test-device');
        $manager->recordMode('limited', 5);
        
        $def = KeyboardDefinition::fromParameters('test-device', 'A,B', '10'); // different limit
        $this->assertFalse($manager->validateDefinition($def));
    }

    public function testValidateDefinitionNoConflict(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('test-device');
        $manager->recordMode('limited', 5);
        
        $def = KeyboardDefinition::fromParameters('test-device', 'A,B,C,D', '5'); // same mode and limit
        $this->assertTrue($manager->validateDefinition($def));
    }

    public function testStoreAndGetUserKeys(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('test-device');
        $uid = 'test-user-' . uniqid();
        $keyIds = [0, 1, 2, 3];
        
        $manager->storeUserKeys($uid, $keyIds);
        $retrieved = $manager->getUserKeys($uid);
        
        $this->assertEquals($keyIds, $retrieved);
    }

    public function testGetUserKeysNotFound(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('test-device');
        $uid = 'unknown-user-' . uniqid();
        
        $keys = $manager->getUserKeys($uid);
        $this->assertEquals([], $keys);
    }

    public function testRecordAndGetAliasEntry(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('test-device');
        $uid = 'test-user-' . uniqid();
        $alias = 'my-alias';
        
        $manager->recordAliasEntry($uid, $alias);
        $retrieved = $manager->getAliasEntry($uid);
        
        $this->assertEquals($alias, $retrieved);
    }

    public function testGetAliasEntryNotFound(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager = new DeviceManager('test-device');
        $uid = 'unknown-user-' . uniqid();
        
        $alias = $manager->getAliasEntry($uid);
        $this->assertNull($alias);
    }

    public function testDeviceIsolation(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $manager1 = new DeviceManager('device-1');
        $manager2 = new DeviceManager('device-2');
        
        $manager1->recordMode('main', null);
        $manager2->recordMode('limited', 5);
        
        $this->assertEquals('main', $manager1->getMode());
        $this->assertEquals('limited', $manager2->getMode());
    }
}
