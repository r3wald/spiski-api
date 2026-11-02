<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Status\BuildInfo;
use PHPUnit\Framework\TestCase;

final class BuildInfoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/spiski-build-info-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/'.BuildInfo::FILE);
        @rmdir($this->dir);
    }

    public function testMissingFileMeansUnknown(): void
    {
        $info = new BuildInfo($this->dir);

        self::assertNull($info->revision());
        self::assertNull($info->deployedAt());
    }

    public function testReadsRevisionAndNormalisesTheTimestamp(): void
    {
        $this->write('{"revision": "89b9040-dirty", "deployedAt": "2026-11-02T20:15:00+01:00"}');
        $info = new BuildInfo($this->dir);

        self::assertSame('89b9040-dirty', $info->revision());
        self::assertSame('2026-11-02T19:15:00.000Z', $info->deployedAt());
    }

    public function testRejectsUnexpectedValues(): void
    {
        $this->write('{"revision": "<script>alert(1)</script>", "deployedAt": "irgendwann"}');
        $info = new BuildInfo($this->dir);

        self::assertNull($info->revision());
        self::assertNull($info->deployedAt());
    }

    public function testEmptyRevisionFromUpdateShWithoutGitIsUnknown(): void
    {
        $this->write('{"revision": "", "deployedAt": "2026-11-02T19:15:00Z"}');
        $info = new BuildInfo($this->dir);

        self::assertNull($info->revision());
        self::assertSame('2026-11-02T19:15:00.000Z', $info->deployedAt());
    }

    public function testBrokenJsonMeansUnknown(): void
    {
        $this->write('{kaputt');

        self::assertNull((new BuildInfo($this->dir))->revision());
    }

    private function write(string $content): void
    {
        file_put_contents($this->dir.'/'.BuildInfo::FILE, $content);
    }
}
