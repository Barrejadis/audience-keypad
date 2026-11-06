<?php
declare(strict_types=1);

require_once __DIR__ . '/InMemoryKvStore.php';

use PHPUnit\Framework\TestCase;

class InMemoryKvStoreTest extends TestCase
{
    public function testGetOrComputeGeneratesAndStoresWhenAbsent(): void
    {
        $store = new InMemoryKvStore();

        $value = $store->getOrCompute('k', fn() => 42);

        $this->assertEquals(42, $value);
        $this->assertTrue($store->exists('k'));
        $this->assertEquals(42, $store->get('k'));
    }

    public function testGetOrComputeDoesNotReinvokeGeneratorOnceCached(): void
    {
        $store = new InMemoryKvStore();
        $calls = 0;
        $generator = function () use (&$calls) {
            $calls++;
            return 'generated';
        };

        $first = $store->getOrCompute('k', $generator);
        $second = $store->getOrCompute('k', $generator);
        $third = $store->getOrCompute('k', $generator);

        $this->assertEquals(1, $calls);
        $this->assertEquals('generated', $first);
        $this->assertEquals('generated', $second);
        $this->assertEquals('generated', $third);
    }

    public function testGetOrComputeKeepsPreExistingValue(): void
    {
        $store = new InMemoryKvStore();
        $store->set('k', 'existing');

        $value = $store->getOrCompute('k', fn() => 'generated');

        $this->assertEquals('existing', $value);
        $this->assertEquals('existing', $store->get('k'));
    }
}
