<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ProbeException.php';

use PHPUnit\Framework\TestCase;

class ProbeExceptionTest extends TestCase
{
    public function testCarriesStatusAndMessage(): void
    {
        $e = new ProbeException(422, 'Division by zero');

        $this->assertSame(422, $e->status);
        $this->assertSame('Division by zero', $e->getMessage());
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testStatus413ForOversizedProgram(): void
    {
        $e = new ProbeException(413, 'Program too large');

        $this->assertSame(413, $e->status);
    }
}
