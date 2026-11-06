<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/KvStore.php';
require_once __DIR__ . '/../src/KeyboardDefinition.php';
require_once __DIR__ . '/../src/DeviceManager.php';
require_once __DIR__ . '/InMemoryKvStore.php';

use PHPUnit\Framework\TestCase;

class DeviceManagerTest extends TestCase
{
    public function testDeviceDoesNotExistInitially(): void
    {
        $manager = new DeviceManager('new-device', new InMemoryKvStore());
        $this->assertFalse($manager->exists());
    }

    public function testDeviceExistsAfterClaim(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', null));
        $this->assertTrue($manager->exists());
    }

    public function testGetModeMain(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', null));
        $this->assertEquals('main', $manager->getMode());
    }

    public function testGetModeLimited(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', '5'));
        $this->assertEquals('limited', $manager->getMode());
        $this->assertEquals(5, $manager->getLimit());
    }

    public function testGetLimitMainMode(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', null));
        $this->assertNull($manager->getLimit());
    }

    public function testClaimDefinitionColdClaimRecordsFullDefinitionWithoutTtl(): void
    {
        $store = new InMemoryKvStore();
        $manager = new DeviceManager('test-device', $store);
        $def = KeyboardDefinition::fromParameters('test-device', 'A, ,C,D', '5');

        $this->assertTrue($manager->claimDefinition($def));

        // kb:{dev}:keys = JSON ordered label list, no TTL (spec 03-storage §1)
        $this->assertSame('["A"," ","C","D"]', $store->get('kb:test-device:keys'));
        $this->assertNull($store->ttlOf('kb:test-device:keys'));
        $this->assertSame('limited', $store->get('kb:test-device:mode'));
        $this->assertNull($store->ttlOf('kb:test-device:mode'));
        $this->assertSame(5, $store->get('kb:test-device:limit'));
        $this->assertNull($store->ttlOf('kb:test-device:limit'));
    }

    public function testClaimDefinitionMainModeRecordsNoLimit(): void
    {
        $store = new InMemoryKvStore();
        $manager = new DeviceManager('test-device', $store);

        $this->assertTrue($manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', null)));

        $this->assertSame('["A","B"]', $store->get('kb:test-device:keys'));
        $this->assertSame('main', $store->get('kb:test-device:mode'));
        $this->assertFalse($store->exists('kb:test-device:limit'));
    }

    public function testClaimDefinitionExactReloadIsIdempotent(): void
    {
        $store = new InMemoryKvStore();
        $manager = new DeviceManager('test-device', $store);
        $def = KeyboardDefinition::fromParameters('test-device', 'A,B', '5');

        $this->assertTrue($manager->claimDefinition($def));
        $this->assertTrue($manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', '5')));

        $this->assertSame('["A","B"]', $store->get('kb:test-device:keys'));
        $this->assertSame('limited', $store->get('kb:test-device:mode'));
        $this->assertSame(5, $store->get('kb:test-device:limit'));
    }

    public function testClaimDefinitionReorderedLabelsConflict(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B,C,D', null));

        // Same labels, different order — still a conflict
        $this->assertFalse($manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'B,A,C,D', null)));
    }

    public function testClaimDefinitionResizedConflict(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', null));

        $this->assertFalse($manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B,C,D', null)));
    }

    public function testClaimDefinitionAddedAndRemovedLabelConflict(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B,C,D,E,F', null));

        // One label replaced
        $this->assertFalse($manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B,C,D,E,G', null)));
        // One label removed (resized down to 4 cells)
        $this->assertFalse($manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B,C,D', null)));
    }

    public function testClaimDefinitionPaddingConflict(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B,C,D', null));

        // Same active labels, but an inactive cell moved: ' ' at index 1
        $this->assertFalse($manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A, ,C,D', null)));
    }

    public function testClaimDefinitionModeConflict(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', null)); // main

        // Same key list, but limited — limit present on a main device
        $this->assertFalse($manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', '5')));
    }

    public function testClaimDefinitionLimitAbsentOnLimitedDeviceConflict(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', '5')); // limited

        $this->assertFalse($manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', null)));
    }

    public function testClaimDefinitionLimitConflict(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', '5'));

        $this->assertFalse($manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', '10')));
    }

    public function testClaimDefinitionSameModeAndLimitDifferentKeysConflict(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', '5'));

        // Same mode and limit, different ordered label list
        $this->assertFalse($manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B,C,D', '5')));
    }

    public function testConcurrentColdClaimsWithDifferentDefinitionsExactlyOneWins(): void
    {
        // The getOrCompute seam serializes the claim per key: two cold (a)
        // loads racing on the same device — exactly one succeeds, and the
        // label-list generator runs exactly once (the winner's).
        $store = new class extends InMemoryKvStore {
            /** @var array<string, int> */
            public array $generatorCalls = [];

            public function getOrCompute(string $key, callable $generator): mixed
            {
                return parent::getOrCompute($key, function () use ($key, $generator) {
                    $this->generatorCalls[$key] = ($this->generatorCalls[$key] ?? 0) + 1;
                    return $generator();
                });
            }
        };
        $manager = new DeviceManager('test-device', $store);

        $first = $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', null));
        $second = $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,C', null));

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, $store->generatorCalls['kb:test-device:keys']);
        $this->assertSame('["A","B"]', $store->get('kb:test-device:keys'));
        $this->assertSame('main', $store->get('kb:test-device:mode'));
        $this->assertFalse($store->exists('kb:test-device:limit'));
    }

    public function testConcurrentColdClaimsWithSameDefinitionBothSucceed(): void
    {
        $store = new class extends InMemoryKvStore {
            /** @var array<string, int> */
            public array $generatorCalls = [];

            public function getOrCompute(string $key, callable $generator): mixed
            {
                return parent::getOrCompute($key, function () use ($key, $generator) {
                    $this->generatorCalls[$key] = ($this->generatorCalls[$key] ?? 0) + 1;
                    return $generator();
                });
            }
        };
        $manager = new DeviceManager('test-device', $store);

        $first = $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', '5'));
        $second = $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', '5'));

        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertSame(1, $store->generatorCalls['kb:test-device:keys']);
        $this->assertSame(1, $store->generatorCalls['kb:test-device:mode']);
        $this->assertSame(1, $store->generatorCalls['kb:test-device:limit']);
        $this->assertSame(5, $store->get('kb:test-device:limit'));
    }

    public function testConcurrentColdClaimsSameKeysDifferentModeExactlyOneWins(): void
    {
        $store = new InMemoryKvStore();
        $manager = new DeviceManager('test-device', $store);

        // Same label list, racing mode claims: the atomic mode claim decides.
        $main = $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', null));
        $limited = $manager->claimDefinition(KeyboardDefinition::fromParameters('test-device', 'A,B', '5'));

        $this->assertTrue($main);
        $this->assertFalse($limited);
        $this->assertSame('main', $store->get('kb:test-device:mode'));
        // The loser never wrote a limit record past its divergent mode claim
        $this->assertFalse($store->exists('kb:test-device:limit'));
    }

    public function testStoreAndGetUserKeys(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $uid = 'test-user-' . uniqid();
        $keyIds = [0, 1, 2, 3];

        $manager->storeUserKeys($uid, $keyIds);
        $retrieved = $manager->getUserKeys($uid);

        $this->assertEquals($keyIds, $retrieved);
    }

    public function testGetUserKeysNotFound(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $uid = 'unknown-user-' . uniqid();

        $keys = $manager->getUserKeys($uid);
        $this->assertEquals([], $keys);
    }

    public function testRecordAndGetAliasEntry(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $uid = 'test-user-' . uniqid();
        $alias = 'my-alias';

        $manager->recordAliasEntry($uid, $alias);
        $retrieved = $manager->getAliasEntry($uid);

        $this->assertEquals($alias, $retrieved);
    }

    public function testGetAliasEntryNotFound(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $uid = 'unknown-user-' . uniqid();

        $alias = $manager->getAliasEntry($uid);
        $this->assertNull($alias);
    }

    public function testClearAliasEntry(): void
    {
        $store = new InMemoryKvStore();
        $manager = new DeviceManager('test-device', $store);
        $uid = 'test-user-' . uniqid();

        $manager->recordAliasEntry($uid, 'my-alias');
        $manager->clearAliasEntry($uid);

        $this->assertNull($manager->getAliasEntry($uid));
        $this->assertFalse($store->exists("kb:test-device:u:$uid:via"));
    }

    public function testClearAliasEntryWithoutMarkerIsNoOp(): void
    {
        $manager = new DeviceManager('test-device', new InMemoryKvStore());
        $uid = 'test-user-' . uniqid();

        $manager->clearAliasEntry($uid); // must not throw

        $this->assertNull($manager->getAliasEntry($uid));
    }

    public function testAliasEntryIsPerDevice(): void
    {
        $store = new InMemoryKvStore();
        $uid = 'test-user-' . uniqid();
        $manager1 = new DeviceManager('device-1', $store);
        $manager2 = new DeviceManager('device-2', $store);

        $manager1->recordAliasEntry($uid, 'my-alias');

        $this->assertSame('my-alias', $manager1->getAliasEntry($uid));
        $this->assertNull($manager2->getAliasEntry($uid));
    }

    public function testDeviceIsolation(): void
    {
        $store = new InMemoryKvStore();
        $manager1 = new DeviceManager('device-1', $store);
        $manager2 = new DeviceManager('device-2', $store);

        $manager1->claimDefinition(KeyboardDefinition::fromParameters('device-1', 'A,B', null));
        $manager2->claimDefinition(KeyboardDefinition::fromParameters('device-2', 'A,B', '5'));

        $this->assertEquals('main', $manager1->getMode());
        $this->assertEquals('limited', $manager2->getMode());
    }
}
