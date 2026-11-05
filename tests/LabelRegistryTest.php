<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/LabelRegistry.php';

use PHPUnit\Framework\TestCase;

class LabelRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear APCu cache before each test
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    public function testGetOrCreateIdFirstLabel(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $registry = new LabelRegistry('test');
        $id = $registry->getOrCreateId('A');
        
        $this->assertEquals(0, $id);
    }

    public function testGetOrCreateIdSameLabel(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $registry = new LabelRegistry('test');
        $id1 = $registry->getOrCreateId('A');
        $id2 = $registry->getOrCreateId('A');
        
        $this->assertEquals($id1, $id2);
    }

    public function testGetOrCreateIdSequential(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $registry = new LabelRegistry('test');
        $idA = $registry->getOrCreateId('A');
        $idB = $registry->getOrCreateId('B');
        $idC = $registry->getOrCreateId('C');
        
        $this->assertEquals(0, $idA);
        $this->assertEquals(1, $idB);
        $this->assertEquals(2, $idC);
    }

    public function testGetOrCreateIdDifferentDevicesIndependent(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $registry1 = new LabelRegistry('dev1');
        $registry2 = new LabelRegistry('dev2');
        
        $id1 = $registry1->getOrCreateId('A');
        $id2 = $registry2->getOrCreateId('A');
        
        // Both should be 0 because they're different devices
        $this->assertEquals(0, $id1);
        $this->assertEquals(0, $id2);
    }

    public function testGetIdReturnsNullForUnregistered(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $registry = new LabelRegistry('test');
        $id = $registry->getId('A');
        
        $this->assertNull($id);
    }

    public function testGetIdAfterCreate(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $registry = new LabelRegistry('test');
        $registry->getOrCreateId('A');
        $id = $registry->getId('A');
        
        $this->assertEquals(0, $id);
    }

    public function testFirstSeenOrder(): void
    {
        if (!function_exists('apcu_store')) {
            $this->markTestSkipped('APCu extension not available');
        }

        $registry = new LabelRegistry('test');
        
        // Register in a specific order
        $idA = $registry->getOrCreateId('←');
        $idB = $registry->getOrCreateId('→');
        $idC = $registry->getOrCreateId('↑');
        $idD = $registry->getOrCreateId('↓');
        
        // From spec: demo keyboard assigns ←=0, →=1, ↑=2, ↓=3
        $this->assertEquals(0, $idA);
        $this->assertEquals(1, $idB);
        $this->assertEquals(2, $idC);
        $this->assertEquals(3, $idD);
    }
}
