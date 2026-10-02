<?php

namespace SirZeruks\LibrenmsRouterosBgp\Tests;

use PHPUnit\Framework\TestCase;
use SirZeruks\LibrenmsRouterosBgp\Updater;

class UpdaterTest extends TestCase
{
    public function testPicksNewestStableRelease(): void
    {
        $meta = ['packages' => [Updater::PACKAGE => [
            ['version' => 'v0.2.0'], ['version' => 'v0.10.1'], ['version' => 'v0.9.9'],
            ['version' => 'v1.0.0-beta1'], ['version' => 'dev-main'], ['version' => '0.3.0'],
        ]]];
        $this->assertSame('v0.10.1', Updater::parseLatest($meta));
    }

    public function testNoReleases(): void
    {
        $this->assertNull(Updater::parseLatest([]));
        $this->assertNull(Updater::parseLatest(['packages' => [Updater::PACKAGE => [['version' => 'dev-main']]]]));
    }

    public function testNewerComparesNumerically(): void
    {
        $this->assertTrue(Updater::newer('v0.10.0', '0.9.0'));
        $this->assertTrue(Updater::newer('0.2.1', 'v0.2.0'));
        $this->assertFalse(Updater::newer('v0.2.0', '0.2.0'));
        $this->assertFalse(Updater::newer(null, '0.2.0'));
        $this->assertFalse(Updater::newer('v0.3.0', 'dev-main'));   // dev installs never offer an update
    }

    public function testOnlyPlainReleaseNumbersReachTheShell(): void
    {
        foreach (['0.2.0', 'v1.2.10'] as $ok) {
            $this->assertTrue(Updater::isRelease($ok), $ok);
        }
        foreach (['dev-main', '1.0.0-beta1', '1.0', '1.0.0; rm -rf /', '$(id)', '1.0.0 && x'] as $bad) {
            $this->assertFalse(Updater::isRelease($bad), $bad);
        }
    }

    public function testDevInstallDetection(): void
    {
        $this->assertTrue(Updater::isDevInstall('dev-main'));
        $this->assertTrue(Updater::isDevInstall('unknown'));
        $this->assertFalse(Updater::isDevInstall('v0.2.0'));
    }
}
