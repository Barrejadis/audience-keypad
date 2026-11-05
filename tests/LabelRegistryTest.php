<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/KvStore.php';
require_once __DIR__ . '/../src/LabelRegistry.php';
require_once __DIR__ . '/InMemoryKvStore.php';

use PHPUnit\Framework\TestCase;

class LabelRegistryTest extends TestCase
{
    public function testGetOrCreateIdFirstLabel(): void
    {
        $registry = new LabelRegistry('test', new InMemoryKvStore());
        $id = $registry->getOrCreateId('A');

        $this->assertEquals(0, $id);
    }

    public function testGetOrCreateIdSameLabel(): void
    {
        $registry = new LabelRegistry('test', new InMemoryKvStore());
        $id1 = $registry->getOrCreateId('A');
        $id2 = $registry->getOrCreateId('A');

        $this->assertEquals($id1, $id2);
    }

    public function testGetOrCreateIdSequential(): void
    {
        $registry = new LabelRegistry('test', new InMemoryKvStore());
        $idA = $registry->getOrCreateId('A');
        $idB = $registry->getOrCreateId('B');
        $idC = $registry->getOrCreateId('C');

        $this->assertEquals(0, $idA);
        $this->assertEquals(1, $idB);
        $this->assertEquals(2, $idC);
    }

    public function testGetOrCreateIdDifferentDevicesIndependent(): void
    {
        $store = new InMemoryKvStore();
        $registry1 = new LabelRegistry('dev1', $store);
        $registry2 = new LabelRegistry('dev2', $store);

        $id1 = $registry1->getOrCreateId('A');
        $id2 = $registry2->getOrCreateId('A');

        // Both should be 0 because they're different devices
        $this->assertEquals(0, $id1);
        $this->assertEquals(0, $id2);
    }

    public function testGetIdReturnsNullForUnregistered(): void
    {
        $registry = new LabelRegistry('test', new InMemoryKvStore());
        $id = $registry->getId('A');

        $this->assertNull($id);
    }

    public function testGetIdAfterCreate(): void
    {
        $registry = new LabelRegistry('test', new InMemoryKvStore());
        $registry->getOrCreateId('A');
        $id = $registry->getId('A');

        $this->assertEquals(0, $id);
    }

    public function testFirstSeenOrder(): void
    {
        $registry = new LabelRegistry('test', new InMemoryKvStore());

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

    public function testGetAllReturnsRegisteredLabels(): void
    {
        $registry = new LabelRegistry('test', new InMemoryKvStore());
        $registry->getOrCreateId('A');
        $registry->getOrCreateId('B');

        $this->assertEquals(['A' => 0, 'B' => 1], $registry->getAll());
    }

    public function testGetAllExcludesCounter(): void
    {
        $registry = new LabelRegistry('test', new InMemoryKvStore());
        $registry->getOrCreateId('A');

        $all = $registry->getAll();
        $this->assertArrayNotHasKey('next', $all);
    }
}
