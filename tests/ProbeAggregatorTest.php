<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Constants.php';
require_once __DIR__ . '/../src/KvStore.php';
require_once __DIR__ . '/../src/LabelRegistry.php';
require_once __DIR__ . '/../src/DeviceManager.php';
require_once __DIR__ . '/../src/KeystrokeManager.php';
require_once __DIR__ . '/../src/ProbeException.php';
require_once __DIR__ . '/../src/ProbeParser.php';
require_once __DIR__ . '/../src/ProbeEvaluator.php';
require_once __DIR__ . '/../src/ProbeAggregator.php';
require_once __DIR__ . '/InMemoryKvStore.php';

use PHPUnit\Framework\TestCase;

class ProbeAggregatorTest extends TestCase
{
    // ADMIN_HASH is sha256 of this default admin password (Constants.php)
    private const PASSWORD = 'admin';

    private InMemoryKvStore $store;
    private ProbeAggregator $aggregator;

    protected function setUp(): void
    {
        $this->store = new InMemoryKvStore();
        $this->setupDevice('demo');
        $this->aggregator = new ProbeAggregator($this->store);
    }

    /**
     * Create a device with the demo keyboard registry (←=0, →=1, ↑=2, ↓=3).
     */
    private function setupDevice(string $device): void
    {
        $devices = new DeviceManager($device, $this->store);
        $devices->recordMode('main', null);
        $registry = new LabelRegistry($device, $this->store);
        foreach (['←', '→', '↑', '↓'] as $label) {
            $registry->getOrCreateId($label);
        }
    }

    /**
     * Register a user on the demo device and record a keystroke history.
     *
     * @param list<int> $history
     */
    private function addUser(string $uid, array $history, string $device = 'demo'): void
    {
        $devices = new DeviceManager($device, $this->store);
        $devices->storeUserKeys($uid, [0, 1, 2, 3]);
        $keystrokes = new KeystrokeManager($device, $this->store);
        foreach ($history as $keyId) {
            $result = $keystrokes->record($uid, $keyId);
            $this->assertSame(200, $result['status']);
        }
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function probe(string $init, string $program, string $device = 'demo'): array
    {
        return $this->aggregator->handle($device, self::PASSWORD, null, $init, $program);
    }

    // ---- Spec §9 vectors through the full aggregation ----

    public function testAggregateCountH1(): void
    {
        $this->addUser('alice', [1, 2, 2, 0]); // H1

        $result = $this->probe('0', 'v + 1');

        $this->assertSame(200, $result['status']);
        $this->assertSame(['alice' => '4'], $result['body']);
    }

    public function testAggregateSpecVectorsH1(): void
    {
        $this->addUser('alice', [1, 2, 2, 0]); // H1 = [→, ↑, ↑, ←]

        $this->assertSame(['alice' => '2'], $this->probe('0', 'v + (k == key "↑")')['body']);
        $this->assertSame(['alice' => '1'], $this->probe('0', 'v ^ k')['body']);
        $this->assertSame(['alice' => '0'], $this->probe('-1', 'k')['body']);
        $this->assertSame(['alice' => '524801'], $this->probe('0', 'v + (1 << (k * 9))')['body']);
    }

    public function testAggregateCursorProbeH1AndH2(): void
    {
        $cursor =
            'if(k == key "←", if(v >= 100, v - 100, v),' .
            'if(k == key "→", if(v < 9900, v + 100, v),' .
            'if(k == key "↑", if(v % 100 < 99, v + 1, v),' .
            '                 if(v % 100 > 0, v - 1, v))))';

        $this->addUser('alice', [1, 2, 2, 0]);              // H1 → 5052
        $this->addUser('bob', array_fill(0, 200, 2));       // H2 → 5099 (clamped)

        $result = $this->probe('5050', $cursor);

        $this->assertSame(200, $result['status']);
        $this->assertSame(['alice' => '5052', 'bob' => '5099'], $result['body']);
    }

    public function testAggregateH3UndoScenario(): void
    {
        // quiz device, H3 = [→, ↑, ←] = [1, 2, 0] after an undo
        $this->setupDevice('quiz');
        $devices = new DeviceManager('quiz', $this->store);
        $devices->recordMode('limited', 3);
        $this->addUser('carol', [1, 2, 0], 'quiz');

        $this->assertSame(['carol' => '3'], $this->probe('0', 'v + 1', 'quiz')['body']);
        $this->assertSame(['carol' => '3'], $this->probe('0', 'v ^ k', 'quiz')['body']);
    }

    // ---- §5 presence: zero-keystroke users are enumerated ----

    public function testPresenceIncludesZeroKeystrokeUsers(): void
    {
        $this->addUser('alice', [1, 2, 2, 0]);
        $this->addUser('bob', []); // loaded the keyboard, never pressed

        $result = $this->probe('1', 'v');

        $this->assertSame(200, $result['status']);
        // Headcount = number of entries in the response map, both yield 1
        $this->assertSame(['alice' => '1', 'bob' => '1'], $result['body']);
    }

    public function testUsersSortedByUid(): void
    {
        $this->addUser('b-user', [1]);
        $this->addUser('a-user', [2]);
        $this->addUser('c-user', []);

        $result = $this->probe('0', 'v + 1');

        $this->assertSame(
            ['a-user' => '1', 'b-user' => '1', 'c-user' => '0'],
            $result['body']
        );
    }

    public function testOnlyUsersWithLiveKeysEntryAreEnumerated(): void
    {
        $this->addUser('alice', [1]);
        // Orphaned stroke entries without a :keys entry are not users
        $this->store->set('kb:demo:u:ghost:n', 1, KB_TTL);
        $this->store->set('kb:demo:u:ghost:0', 2, KB_TTL);

        $result = $this->probe('0', 'v + 1');

        $this->assertSame(['alice' => '1'], $result['body']);
    }

    public function testDevicesAreIsolated(): void
    {
        $this->setupDevice('other');
        $this->addUser('alice', [1], 'demo');
        $this->addUser('zed', [2, 2], 'other');

        $this->assertSame(['alice' => '1'], $this->probe('0', 'v + 1', 'demo')['body']);
        $this->assertSame(['zed' => '2'], $this->probe('0', 'v + 1', 'other')['body']);
    }

    public function testValuesAreDecimalStrings(): void
    {
        $this->addUser('alice', [1]);
        $big = '123456789012345678901234567890';

        $result = $this->probe($big, 'v * v + k');

        $expected = gmp_strval(gmp_add(gmp_mul(gmp_init($big), gmp_init($big)), gmp_init(1)), 10);
        $this->assertSame(['alice' => $expected], $result['body']);
        $this->assertIsString($result['body']['alice']);
    }

    // ---- §6–§8 errors and auth ----

    public function testWrongPasswordIs401(): void
    {
        $this->addUser('alice', [1]);

        $result = $this->aggregator->handle('demo', 'wrong', null, '0', 'v + 1');

        $this->assertSame(401, $result['status']);
        $this->assertFalse($result['body']['ok']);
    }

    public function testMissingPasswordIs401(): void
    {
        $result = $this->aggregator->handle('demo', null, null, '0', 'v + 1');
        $this->assertSame(401, $result['status']);
    }

    public function testMalformedDeviceNameIs400(): void
    {
        $this->assertSame(400, $this->aggregator->handle('bad name!', self::PASSWORD, null, '0', 'v')['status']);
        $this->assertSame(400, $this->aggregator->handle('', self::PASSWORD, null, '0', 'v')['status']);
        $this->assertSame(400, $this->aggregator->handle(null, self::PASSWORD, null, '0', 'v')['status']);
    }

    public function testMissingInitOrProgramIs400(): void
    {
        $this->assertSame(400, $this->aggregator->handle('demo', self::PASSWORD, null, null, 'v')['status']);
        $this->assertSame(400, $this->aggregator->handle('demo', self::PASSWORD, null, '0', null)['status']);
    }

    public function testOversizedProgramIs413(): void
    {
        $this->addUser('alice', [1]);

        $result = $this->probe('0', str_repeat('v', MAX_PROGRAM_SIZE + 1));

        $this->assertSame(413, $result['status']);
    }

    public function testUnknownLabelIs422(): void
    {
        $this->addUser('alice', [1]);

        $result = $this->probe('0', 'v + key "nope"');

        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('Unknown label', $result['body']['error']);
    }

    public function testParseErrorIs422(): void
    {
        $this->addUser('alice', [1]);
        $this->assertSame(422, $this->probe('0', 'v +')['status']);
    }

    public function testSemanticErrorFailsWholeRequest422(): void
    {
        $this->addUser('alice', [1, 0]); // k=0 at the last step

        $result = $this->probe('1', 'v / k');

        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('Division by zero', $result['body']['error']);
    }

    public function testStateTooLargeIs422(): void
    {
        $this->addUser('alice', [1]);

        $result = $this->probe('1' . str_repeat('0', 700), 'v * v');

        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('too large', $result['body']['error']);
    }

    // ---- §6 reset action ----

    public function testResetWipesDeviceNamespace(): void
    {
        $this->addUser('alice', [1, 2]);
        $this->store->set('alias:pub', '{"kb":"demo"}');
        $this->setupDevice('other');
        $this->addUser('zed', [1], 'other');

        $result = $this->aggregator->handle('demo', self::PASSWORD, 'reset', null, null);

        $this->assertSame(200, $result['status']);
        $this->assertSame(['ok' => true], $result['body']);

        // Everything under kb:demo: is gone — registry, mode, user data
        $this->assertSame([], $this->store->entriesWithPrefix('kb:demo:'));
        // Aliases and other devices are untouched
        $this->assertTrue($this->store->exists('alias:pub'));
        $this->assertNotSame([], $this->store->entriesWithPrefix('kb:other:'));
    }

    public function testResetLetsLabelsReregisterFromZero(): void
    {
        $this->addUser('alice', [1]);
        $this->assertSame(0, (new LabelRegistry('demo', $this->store))->getId('←'));

        $this->aggregator->handle('demo', self::PASSWORD, 'reset', null, null);

        $registry = new LabelRegistry('demo', $this->store);
        $this->assertSame(0, $registry->getOrCreateId('←'));
        $this->assertSame(1, $registry->getOrCreateId('→'));
    }

    public function testResetRequiresPassword(): void
    {
        $this->addUser('alice', [1]);

        $result = $this->aggregator->handle('demo', 'wrong', 'reset', null, null);

        $this->assertSame(401, $result['status']);
        $this->assertNotSame([], $this->store->entriesWithPrefix('kb:demo:'));
    }

    public function testResetOnUnknownDeviceIsOk(): void
    {
        $result = $this->aggregator->handle('ghost', self::PASSWORD, 'reset', null, null);

        $this->assertSame(200, $result['status']);
        $this->assertSame(['ok' => true], $result['body']);
    }
}
