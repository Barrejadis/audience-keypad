<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/KvStore.php';
require_once __DIR__ . '/../src/LabelRegistry.php';
require_once __DIR__ . '/../src/KeyboardDefinition.php';
require_once __DIR__ . '/../src/DeviceManager.php';
require_once __DIR__ . '/../src/KeystrokeManager.php';
require_once __DIR__ . '/../src/AliasResolver.php';
require_once __DIR__ . '/../src/CleanStartManager.php';
require_once __DIR__ . '/InMemoryKvStore.php';

use PHPUnit\Framework\TestCase;

class CleanStartManagerTest extends TestCase
{
    // ADMIN_HASH is sha256 of this default admin password (Constants.php)
    private const PASSWORD = 'admin';

    private InMemoryKvStore $store;
    private CleanStartManager $manager;

    protected function setUp(): void
    {
        $this->store = new InMemoryKvStore();
        $this->manager = new CleanStartManager($this->store);
    }

    /**
     * Create a device with a claimed definition and one registered label.
     */
    private function setupDevice(string $device): void
    {
        $devices = new DeviceManager($device, $this->store);
        $devices->claimDefinition(KeyboardDefinition::fromParameters($device, 'A,B,C,D', null));
        $registry = new LabelRegistry($device, $this->store);
        $registry->getOrCreateId('A');
    }

    /**
     * Register a user on the device and record one keystroke.
     */
    private function addUser(string $uid, string $device = 'demo'): void
    {
        $devices = new DeviceManager($device, $this->store);
        $devices->storeUserKeys($uid, [0]);
        $keystrokes = new KeystrokeManager($device, $this->store);
        $result = $keystrokes->record($uid, 0);
        $this->assertSame(200, $result['status']);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function cleanStart(mixed $password = self::PASSWORD, mixed $confirm = true): array
    {
        return $this->manager->handle($password, 'clean-start', $confirm);
    }

    private function keypadKeyCount(): int
    {
        return count($this->store->entriesWithPrefix('kb:'))
            + count($this->store->entriesWithPrefix('alias:'));
    }

    // ---- Auth ----

    public function testWrongPasswordIs401AndDeletesNothing(): void
    {
        $this->setupDevice('demo');
        $before = $this->keypadKeyCount();

        $result = $this->cleanStart('wrong');

        $this->assertSame(401, $result['status']);
        $this->assertFalse($result['body']['ok']);
        $this->assertSame($before, $this->keypadKeyCount());
    }

    public function testMissingPasswordIs401(): void
    {
        $result = $this->cleanStart(null);

        $this->assertSame(401, $result['status']);
    }

    // ---- Action and confirmation ----

    public function testMissingActionIs400(): void
    {
        $result = $this->manager->handle(self::PASSWORD, null, true);

        $this->assertSame(400, $result['status']);
    }

    public function testUnknownActionIs400AndDeletesNothing(): void
    {
        $this->setupDevice('demo');
        $before = $this->keypadKeyCount();

        $result = $this->manager->handle(self::PASSWORD, 'nuke', true);

        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('Unknown action', $result['body']['error']);
        $this->assertSame($before, $this->keypadKeyCount());
    }

    public function testMissingConfirmIs400AndDeletesNothing(): void
    {
        $this->setupDevice('demo');
        $before = $this->keypadKeyCount();

        foreach ([null, false, 'yes', 1] as $confirm) {
            $result = $this->cleanStart(self::PASSWORD, $confirm);
            $this->assertSame(400, $result['status']);
            $this->assertStringContainsString('confirm', $result['body']['error']);
        }
        $this->assertSame($before, $this->keypadKeyCount());
    }

    // ---- The wipe itself ----

    public function testCleanStartWipesEveryKeypadKeyAndReportsCounts(): void
    {
        $this->setupDevice('demo');
        $this->setupDevice('quiz');
        $this->addUser('alice', 'demo');
        $this->addUser('bob', 'quiz');
        $resolver = new AliasResolver($this->store);
        $resolver->upsert('pub', 'demo', 'A,B,C,D', null);
        $resolver->upsert('quiz-link', 'quiz', 'A,B,C,D', null);
        // Foreign APCu entries that must survive the wipe
        $this->store->set('other-app:session', 'x');
        $this->store->set('kb2:foreign', 'x');
        $this->store->set('aliases:foreign', 'x');

        $before = $this->keypadKeyCount();
        $this->assertGreaterThan(0, $before);

        $result = $this->cleanStart();

        $this->assertSame(200, $result['status']);
        $this->assertSame([
            'ok' => true,
            'keys_removed' => $before,
            'devices' => 2,
            'sessions' => 2,
            'aliases' => 2,
            'remaining' => 0,
        ], $result['body']);

        // The whole keypad namespace is gone, foreign keys are untouched
        $this->assertSame([], $this->store->entriesWithPrefix('kb:'));
        $this->assertSame([], $this->store->entriesWithPrefix('alias:'));
        $this->assertTrue($this->store->exists('other-app:session'));
        $this->assertTrue($this->store->exists('kb2:foreign'));
        $this->assertTrue($this->store->exists('aliases:foreign'));
    }

    public function testCleanStartOnEmptyStoreIsOk(): void
    {
        $result = $this->cleanStart();

        $this->assertSame(200, $result['status']);
        $this->assertSame(
            ['ok' => true, 'keys_removed' => 0, 'devices' => 0, 'sessions' => 0, 'aliases' => 0, 'remaining' => 0],
            $result['body']
        );
    }

    public function testCleanStartIsIdempotent(): void
    {
        $this->setupDevice('demo');
        $this->addUser('alice');

        $first = $this->cleanStart();
        $second = $this->cleanStart();

        $this->assertSame(200, $first['status']);
        $this->assertGreaterThan(0, $first['body']['keys_removed']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(0, $second['body']['keys_removed']);
        $this->assertSame(0, $second['body']['remaining']);
    }

    public function testDevicesBecomeFreshAfterCleanStart(): void
    {
        $this->setupDevice('demo');
        $this->addUser('alice');
        $this->assertSame(0, (new LabelRegistry('demo', $this->store))->getId('A'));

        $this->cleanStart();

        // Labels re-register from id 0, exactly like after a device reset
        $registry = new LabelRegistry('demo', $this->store);
        $this->assertSame(0, $registry->getOrCreateId('A'));
        $this->assertNull((new DeviceManager('demo', $this->store))->getMode());
    }
}
