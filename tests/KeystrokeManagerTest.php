<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/KvStore.php';
require_once __DIR__ . '/../src/KeyboardDefinition.php';
require_once __DIR__ . '/../src/DeviceManager.php';
require_once __DIR__ . '/../src/KeystrokeManager.php';
require_once __DIR__ . '/../src/AliasResolver.php';
require_once __DIR__ . '/InMemoryKvStore.php';

use PHPUnit\Framework\TestCase;

class KeystrokeManagerTest extends TestCase
{
    private const UID = 'test-session-id';

    private InMemoryKvStore $store;

    /**
     * Set up a device with a recorded mode and one user's active key set.
     * The demo/quiz keyboards of spec §6 use ←,→,↑,↓ => ids 0,1,2,3.
     *
     * @param list<int> $keyIds
     */
    private function setupDevice(string $device, string $mode, ?int $limit, array $keyIds, string $uid = self::UID): KeystrokeManager
    {
        $this->store = new InMemoryKvStore();
        $devices = new DeviceManager($device, $this->store);
        $devices->claimDefinition(KeyboardDefinition::fromParameters(
            $device,
            'A,B,C,D',
            $limit !== null ? (string)$limit : null
        ));
        $devices->storeUserKeys($uid, $keyIds);
        return new KeystrokeManager($device, $this->store);
    }

    public function testConstructorRejectsInvalidDeviceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new KeystrokeManager('bad name!', new InMemoryKvStore());
    }

    public function testRecordKeystrokeSuccessMainMode(): void
    {
        $manager = $this->setupDevice('demo', 'main', null, [0, 1, 2, 3]);

        $result = $manager->record(self::UID, 1);

        $this->assertSame(200, $result['status']);
        $this->assertSame(['ok' => true, 'remaining' => MAX_STROKES - 1], $result['body']);
        $this->assertSame(1, $manager->getCount(self::UID));
        $this->assertSame([1], $manager->getHistory(self::UID));
        // Stored at index n-1 = 0 under the device namespace
        $this->assertSame(1, (int)$this->store->get('kb:demo:u:' . self::UID . ':0'));
        $this->assertSame(1, (int)$this->store->get('kb:demo:u:' . self::UID . ':n'));
    }

    public function testRecordHistoryH1(): void
    {
        // Spec §6: H1 = [→, ↑, ↑, ←] = ids [1, 2, 2, 0] on the demo device
        $manager = $this->setupDevice('demo', 'main', null, [0, 1, 2, 3]);

        $result = null;
        foreach ([1, 2, 2, 0] as $keyId) {
            $result = $manager->record(self::UID, $keyId);
            $this->assertSame(200, $result['status']);
        }

        $this->assertSame([1, 2, 2, 0], $manager->getHistory(self::UID));
        $this->assertSame(MAX_STROKES - 4, $result['body']['remaining']);
    }

    public function testRecordHistoryH2(): void
    {
        // Spec §6: H2 = 200 × ↑ (id 2) on the demo device
        $manager = $this->setupDevice('demo', 'main', null, [0, 1, 2, 3]);

        $result = null;
        for ($i = 0; $i < 200; $i++) {
            $result = $manager->record(self::UID, 2);
            $this->assertSame(200, $result['status']);
        }

        $this->assertSame(200, $manager->getCount(self::UID));
        $this->assertSame(MAX_STROKES - 200, $result['body']['remaining']);
        $this->assertSame(array_fill(0, 200, 2), $manager->getHistory(self::UID));
    }

    public function testRecordUnregisteredKeyIdRejected(): void
    {
        $manager = $this->setupDevice('demo', 'main', null, [0, 1, 2, 3]);

        $result = $manager->record(self::UID, 9);

        $this->assertSame(400, $result['status']);
        $this->assertFalse($result['body']['ok']);
        // A rejected press consumes no budget and stores nothing
        $this->assertSame(0, $manager->getCount(self::UID));
        $this->assertSame([], $manager->getHistory(self::UID));
        $ok = $manager->record(self::UID, 1);
        $this->assertSame(MAX_STROKES - 1, $ok['body']['remaining']);
    }

    public function testRecordKeyIdOfInactiveKeyRejected(): void
    {
        // Ids of inactive cells are never registered; a user whose keyboard
        // has only two active keys cannot press id 2.
        $manager = $this->setupDevice('demo', 'main', null, [0, 1]);

        $this->assertSame(400, $manager->record(self::UID, 2)['status']);
    }

    public function testRecordWithoutUserKeysRejected(): void
    {
        // A session that never loaded a keyboard on this device (or a wrong
        // device name) has no stored key set.
        $manager = $this->setupDevice('demo', 'main', null, [0, 1, 2, 3]);

        $this->assertSame(400, $manager->record('other-session', 1)['status']);

        $ghost = new KeystrokeManager('ghost', $this->store);
        $this->assertSame(400, $ghost->record(self::UID, 1)['status']);
    }

    public function testRecordCapReachedMainDevice(): void
    {
        $manager = $this->setupDevice('demo', 'main', null, [0]);

        for ($i = 0; $i < MAX_STROKES; $i++) {
            $this->assertSame(200, $manager->record(self::UID, 0)['status']);
        }

        $result = $manager->record(self::UID, 0);

        $this->assertSame(429, $result['status']);
        $this->assertFalse($result['body']['ok']);
        // The stroke is not stored and the counter is rolled back
        $this->assertSame(MAX_STROKES, $manager->getCount(self::UID));
        $this->assertCount(MAX_STROKES, $manager->getHistory(self::UID));
        // Still capped on the next press
        $this->assertSame(429, $manager->record(self::UID, 0)['status']);
    }

    public function testHandleRequiresExactlyOneOfKeyOrUndo(): void
    {
        $manager = $this->setupDevice('quiz', 'limited', 3, [0, 1, 2, 3]);

        $both = $manager->handle(self::UID, '1', '1');
        $this->assertSame(400, $both['status']);
        $this->assertFalse($both['body']['ok']);

        $neither = $manager->handle(self::UID, null, null);
        $this->assertSame(400, $neither['status']);

        // Neither malformed request touched the counter
        $this->assertSame(0, $manager->getCount(self::UID));
    }

    public function testHandleRejectsNonIntegerKey(): void
    {
        $manager = $this->setupDevice('demo', 'main', null, [0, 1, 2, 3]);

        foreach (['abc', '-1', '1.5', '', ' 1', [1]] as $bad) {
            $result = $manager->handle(self::UID, $bad, null);
            $this->assertSame(400, $result['status'], 'key=' . json_encode($bad));
        }
        $this->assertSame(0, $manager->getCount(self::UID));
    }

    public function testHandleDispatchesKeyAndUndo(): void
    {
        $manager = $this->setupDevice('quiz', 'limited', 3, [0, 1, 2, 3]);

        $keyResult = $manager->handle(self::UID, '2', null);
        $this->assertSame(200, $keyResult['status']);
        $this->assertSame(['ok' => true, 'remaining' => 2], $keyResult['body']);

        $undoResult = $manager->handle(self::UID, null, '1');
        $this->assertSame(200, $undoResult['status']);
        $this->assertSame(['ok' => true, 'remaining' => 3], $undoResult['body']);
    }

    public function testLimitedModeQuizScenarioH3(): void
    {
        // Spec §6: quiz device (limit=3), presses →, ↑, ↑ succeed (remaining 0),
        // a fourth press ← → 429, undo → remaining 1, press ← succeeds.
        // H3 = [→, ↑, ←] = ids [1, 2, 0]
        $manager = $this->setupDevice('quiz', 'limited', 3, [0, 1, 2, 3]);

        $this->assertSame(2, $manager->record(self::UID, 1)['body']['remaining']); // →
        $this->assertSame(1, $manager->record(self::UID, 2)['body']['remaining']); // ↑
        $r = $manager->record(self::UID, 2);                                       // ↑
        $this->assertSame(200, $r['status']);
        $this->assertSame(0, $r['body']['remaining']);

        $fourth = $manager->record(self::UID, 0);                                  // ←
        $this->assertSame(429, $fourth['status']);
        $this->assertSame([1, 2, 2], $manager->getHistory(self::UID));

        $undo = $manager->undo(self::UID);
        $this->assertSame(200, $undo['status']);
        $this->assertSame(['ok' => true, 'remaining' => 1], $undo['body']);
        // The last stroke (index 2, the second ↑) is erased, counter decremented
        $this->assertFalse($this->store->exists('kb:quiz:u:' . self::UID . ':2'));
        $this->assertSame(2, (int)$this->store->get('kb:quiz:u:' . self::UID . ':n'));
        $this->assertSame([1, 2], $manager->getHistory(self::UID));

        $last = $manager->record(self::UID, 0);                                   // ←
        $this->assertSame(200, $last['status']);
        $this->assertSame(0, $last['body']['remaining']);

        $this->assertSame([1, 2, 0], $manager->getHistory(self::UID));
        $this->assertSame(3, $manager->getCount(self::UID));
    }

    public function testUndoOnMainDeviceRejected(): void
    {
        $manager = $this->setupDevice('demo', 'main', null, [0, 1, 2, 3]);
        $manager->record(self::UID, 1);

        $result = $manager->undo(self::UID);

        $this->assertSame(400, $result['status']);
        $this->assertFalse($result['body']['ok']);
        // The log is untouched
        $this->assertSame([1], $manager->getHistory(self::UID));
    }

    public function testUndoWithEmptyHistoryRejected(): void
    {
        $manager = $this->setupDevice('quiz', 'limited', 3, [0, 1, 2, 3]);

        $result = $manager->undo(self::UID);

        $this->assertSame(400, $result['status']);
        $this->assertSame(0, $manager->getCount(self::UID));
    }

    public function testUndoRestoresBudget(): void
    {
        $manager = $this->setupDevice('quiz', 'limited', 1, [0, 1]);

        $this->assertSame(0, $manager->record(self::UID, 0)['body']['remaining']);
        $this->assertSame(429, $manager->record(self::UID, 1)['status']);

        $this->assertSame(1, $manager->undo(self::UID)['body']['remaining']);
        $this->assertSame(200, $manager->record(self::UID, 1)['status']);
    }

    public function testCapIsPerUserPerDevice(): void
    {
        $this->store = new InMemoryKvStore();
        $devicesA = new DeviceManager('devA', $this->store);
        $devicesA->claimDefinition(KeyboardDefinition::fromParameters('devA', 'A', '1'));
        $devicesA->storeUserKeys(self::UID, [0]);
        $devicesB = new DeviceManager('devB', $this->store);
        $devicesB->claimDefinition(KeyboardDefinition::fromParameters('devB', 'A', '1'));
        $devicesB->storeUserKeys(self::UID, [0]);

        $managerA = new KeystrokeManager('devA', $this->store);
        $managerB = new KeystrokeManager('devB', $this->store);

        $this->assertSame(0, $managerA->record(self::UID, 0)['body']['remaining']);
        $this->assertSame(429, $managerA->record(self::UID, 0)['status']);
        // Exhausting the allowance on devA leaves devB's budget intact
        $resultB = $managerB->record(self::UID, 0);
        $this->assertSame(200, $resultB['status']);
        $this->assertSame(0, $resultB['body']['remaining']);
    }

    public function testGetCountAndHistoryDefaultToEmpty(): void
    {
        $manager = $this->setupDevice('demo', 'main', null, [0, 1]);

        $this->assertSame(0, $manager->getCount('unknown-session'));
        $this->assertSame([], $manager->getHistory('unknown-session'));
    }

    /**
     * Set up a device whose user entered through an alias currently bound
     * to $target (spec 05-alias §8 scenario: alias act → act1/act2).
     */
    private function setupAliasedDevice(string $device, string $alias, string $target, string $mode = 'main', ?int $limit = null, array $keyIds = [0, 1, 2, 3], string $uid = self::UID): KeystrokeManager
    {
        $this->store = new InMemoryKvStore();
        $devices = new DeviceManager($device, $this->store);
        $devices->claimDefinition(KeyboardDefinition::fromParameters(
            $device,
            'A,B,C,D',
            $limit !== null ? (string)$limit : null
        ));
        $devices->storeUserKeys($uid, $keyIds);
        $devices->recordAliasEntry($uid, $alias);
        $resolver = new AliasResolver($this->store);
        $resolver->upsert($alias, $target, 'A,B,C,D', $limit !== null ? (string)$limit : null);
        return new KeystrokeManager($device, $this->store);
    }

    public function testRerouteUnchangedAliasRecordsNormally(): void
    {
        $manager = $this->setupAliasedDevice('act1', 'act', 'act1');

        $result = $manager->handle(self::UID, '0', null);

        $this->assertSame(200, $result['status']);
        $this->assertSame([0], $manager->getHistory(self::UID));
    }

    public function testRerouteRetargetedAliasDropsKeystroke(): void
    {
        $manager = $this->setupAliasedDevice('act1', 'act', 'act1');
        $this->assertSame(200, $manager->record(self::UID, 0)['status']);

        // Admin repoints the alias to another device
        $resolver = new AliasResolver($this->store);
        $resolver->upsert('act', 'act2', 'E,F', null);

        $result = $manager->handle(self::UID, '1', null);

        $this->assertSame(409, $result['status']);
        $this->assertSame(['ok' => false, 'reroute' => true], $result['body']);
        // Nothing recorded or erased: counter and log untouched
        $this->assertSame(1, $manager->getCount(self::UID));
        $this->assertSame([0], $manager->getHistory(self::UID));
        $this->assertFalse($this->store->exists('kb:act1:u:' . self::UID . ':1'));
    }

    public function testRerouteDeletedAliasDropsKeystroke(): void
    {
        $manager = $this->setupAliasedDevice('act1', 'act', 'act1');

        $resolver = new AliasResolver($this->store);
        $resolver->delete('act');

        $result = $manager->record(self::UID, 0);

        $this->assertSame(409, $result['status']);
        $this->assertSame(['ok' => false, 'reroute' => true], $result['body']);
        $this->assertSame(0, $manager->getCount(self::UID));
        $this->assertSame([], $manager->getHistory(self::UID));
    }

    public function testRerouteAppliesToUndo(): void
    {
        $manager = $this->setupAliasedDevice('quiz', 'act', 'quiz', 'limited', 3);
        $this->assertSame(200, $manager->record(self::UID, 1)['status']);

        $resolver = new AliasResolver($this->store);
        $resolver->upsert('act', 'other', 'A,B', '3');

        $result = $manager->handle(self::UID, null, '1');

        $this->assertSame(409, $result['status']);
        $this->assertSame(['ok' => false, 'reroute' => true], $result['body']);
        // The undo was not applied
        $this->assertSame(1, $manager->getCount(self::UID));
        $this->assertSame([1], $manager->getHistory(self::UID));
    }

    public function testRewriteWithSameTargetDoesNotReroute(): void
    {
        // The modification criterion is the target device name only.
        $manager = $this->setupAliasedDevice('act1', 'act', 'act1');

        $resolver = new AliasResolver($this->store);
        $resolver->upsert('act', 'act1', 'E,F,G,H', null); // same target, new definition

        $result = $manager->record(self::UID, 2);

        $this->assertSame(200, $result['status']);
        $this->assertSame([2], $manager->getHistory(self::UID));
    }

    public function testRepointAwayAndBackRecordsNormally(): void
    {
        $manager = $this->setupAliasedDevice('act1', 'act', 'act1');

        $resolver = new AliasResolver($this->store);
        $resolver->upsert('act', 'act2', 'E,F', null);
        $resolver->upsert('act', 'act1', 'A,B,C,D', null); // back before the next keystroke

        $this->assertSame(200, $manager->record(self::UID, 0)['status']);
    }

    public function testDirectEntryIsNeverRerouted(): void
    {
        // No entry marker: whatever happens to aliases pointing at the
        // device, the user's presses record normally.
        $manager = $this->setupDevice('act1', 'main', null, [0, 1, 2, 3]);
        $resolver = new AliasResolver($this->store);
        $resolver->upsert('act', 'act1', 'A,B,C,D', null);
        $resolver->delete('act');

        $result = $manager->record(self::UID, 1);

        $this->assertSame(200, $result['status']);
        $this->assertSame([1], $manager->getHistory(self::UID));
    }

    public function testEntryMarkerIsPerDevice(): void
    {
        // A stale marker on act1 does not affect the same user on act2.
        $manager1 = $this->setupAliasedDevice('act1', 'act', 'act2'); // already retargeted
        $devices2 = new DeviceManager('act2', $this->store);
        $devices2->claimDefinition(KeyboardDefinition::fromParameters('act2', 'A,B', null));
        $devices2->storeUserKeys(self::UID, [0, 1]);
        $manager2 = new KeystrokeManager('act2', $this->store);

        $this->assertSame(409, $manager1->record(self::UID, 0)['status']);
        $this->assertSame(200, $manager2->record(self::UID, 0)['status']);
    }

    public function testRerouteConsumesNoBudget(): void
    {
        $manager = $this->setupAliasedDevice('quiz', 'act', 'quiz', 'limited', 2, [0, 1]);
        $this->assertSame(1, $manager->record(self::UID, 0)['body']['remaining']);

        $resolver = new AliasResolver($this->store);
        $resolver->upsert('act', 'other', 'A,B', '2');

        // The dropped keystroke consumes no budget
        $this->assertSame(409, $manager->record(self::UID, 1)['status']);

        // Repointed back: the next press still has the full remaining budget
        $resolver->upsert('act', 'quiz', 'A,B', '2');
        $result = $manager->record(self::UID, 1);
        $this->assertSame(200, $result['status']);
        $this->assertSame(0, $result['body']['remaining']);
    }

    public function testMalformedRequestStill400WithStaleMarker(): void
    {
        // The routing check runs before the command processing, but the
        // request-shape validation (exactly one of key/undo) comes first.
        $manager = $this->setupAliasedDevice('act1', 'act', 'act2');

        $this->assertSame(400, $manager->handle(self::UID, null, null)['status']);
        $this->assertSame(400, $manager->handle(self::UID, '1', '1')['status']);
        $this->assertSame(409, $manager->handle(self::UID, '1', null)['status']);
    }

    /**
     * Simulate aging: re-store every per-user entry of the user with a
     * nearly expired TTL, as if written almost KB_TTL ago.
     */
    private function ageUserData(string $device, string $uid): void
    {
        foreach ($this->store->entriesWithPrefix("kb:$device:u:$uid:") as $key => $value) {
            $this->store->set($key, $value, 1);
        }
    }

    public function testRefreshUserTtlRestoresCounterStrokesAndKeys(): void
    {
        $manager = $this->setupDevice('demo', 'main', null, [0, 1, 2, 3]);
        $manager->record(self::UID, 1);
        $manager->record(self::UID, 2);
        $manager->record(self::UID, 0);

        $this->ageUserData('demo', self::UID);

        $manager->refreshUserTtl(self::UID);

        $prefix = 'kb:demo:u:' . self::UID . ':';
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . 'n'));
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . '0'));
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . '1'));
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . '2'));
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . 'keys'));
        // The refresh re-stores values, it does not alter them
        $this->assertSame(3, $manager->getCount(self::UID));
        $this->assertSame([1, 2, 0], $manager->getHistory(self::UID));
    }

    public function testRefreshUserTtlWithoutUserDataCreatesNothing(): void
    {
        $manager = $this->setupDevice('demo', 'main', null, [0, 1, 2, 3]);

        $manager->refreshUserTtl('unknown-session');

        $this->assertSame([], $this->store->entriesWithPrefix('kb:demo:u:unknown-session:'));
        $this->assertSame(0, $manager->getCount('unknown-session'));
    }

    public function testRefreshUserTtlSkipsMissingStrokes(): void
    {
        // A stroke entry that expired on its own is not resurrected; the
        // others are still refreshed.
        $manager = $this->setupDevice('demo', 'main', null, [0, 1, 2, 3]);
        $manager->record(self::UID, 1);
        $manager->record(self::UID, 2);
        $manager->record(self::UID, 0);
        $this->store->delete('kb:demo:u:' . self::UID . ':1');
        $this->ageUserData('demo', self::UID);

        $manager->refreshUserTtl(self::UID);

        $prefix = 'kb:demo:u:' . self::UID . ':';
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . 'n'));
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . '0'));
        $this->assertFalse($this->store->exists($prefix . '1'));
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . '2'));
        // History still skips the missing entry
        $this->assertSame([1, 0], $manager->getHistory(self::UID));
    }

    public function testHandleRefreshesAllPerUserTtls(): void
    {
        // Endpoint (b) goes through handle(): every well-formed request
        // refreshes keys, counter and every stored stroke (spec 03 §2).
        $manager = $this->setupDevice('demo', 'main', null, [0, 1, 2, 3]);
        $manager->record(self::UID, 1);
        $manager->record(self::UID, 2);
        $this->ageUserData('demo', self::UID);

        $result = $manager->handle(self::UID, '0', null);

        $this->assertSame(200, $result['status']);
        $prefix = 'kb:demo:u:' . self::UID . ':';
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . 'keys'));
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . 'n'));
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . '0'));
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . '1'));
        // The just-written stroke carries a fresh TTL as well
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . '2'));
        $this->assertSame([1, 2, 0], $manager->getHistory(self::UID));
    }

    public function testUndoViaHandleRefreshesAllPerUserTtls(): void
    {
        $manager = $this->setupDevice('quiz', 'limited', 3, [0, 1, 2, 3]);
        $manager->record(self::UID, 1);
        $manager->record(self::UID, 2);
        $this->ageUserData('quiz', self::UID);

        $result = $manager->handle(self::UID, null, '1');

        $this->assertSame(200, $result['status']);
        $prefix = 'kb:quiz:u:' . self::UID . ':';
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . 'keys'));
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . 'n'));
        $this->assertSame(KB_TTL, $this->store->ttlOf($prefix . '0'));
        // The erased stroke stays deleted
        $this->assertFalse($this->store->exists($prefix . '1'));
    }

    public function testRoutingCheckRefreshesAliasMarker(): void
    {
        $manager = $this->setupAliasedDevice('act1', 'act', 'act1');
        $manager->record(self::UID, 0);
        $this->store->set('kb:act1:u:' . self::UID . ':via', 'act', 1); // aged marker

        $result = $manager->record(self::UID, 1);

        $this->assertSame(200, $result['status']);
        $this->assertSame(KB_TTL, $this->store->ttlOf('kb:act1:u:' . self::UID . ':via'));
        $this->assertSame('act', $this->store->get('kb:act1:u:' . self::UID . ':via'));
    }

    public function testRoutingCheckRefreshesAliasMarkerEvenOnReroute(): void
    {
        // The marker is refreshed at the routing check itself, so a 409
        // reroute does not leave it any closer to expiry.
        $manager = $this->setupAliasedDevice('act1', 'act', 'act1');
        $resolver = new AliasResolver($this->store);
        $resolver->upsert('act', 'act2', 'E,F', null);
        $this->store->set('kb:act1:u:' . self::UID . ':via', 'act', 1); // aged marker

        $result = $manager->record(self::UID, 0);

        $this->assertSame(409, $result['status']);
        $this->assertSame(KB_TTL, $this->store->ttlOf('kb:act1:u:' . self::UID . ':via'));
    }
}
