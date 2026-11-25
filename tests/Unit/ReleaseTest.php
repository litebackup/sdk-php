<?php

declare(strict_types=1);

namespace Litebackup\Tests\Unit;

use Litebackup\Release;
use Litebackup\ReleaseNotListed;
use Litebackup\ReleaseSignatureError;
use Litebackup\Tests\Support\ContractFixtures;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/** Release metadata is read from its signed document, and only when the signature matches. */
final class ReleaseTest extends TestCase
{
    private static function metadata(string $name): string
    {
        return (string) file_get_contents(ContractFixtures::ROOT . '/binary/' . $name);
    }

    #[RequiresPhpExtension('sodium')]
    public function testReleaseMetadata(): void
    {
        $release = Release::findRelease(self::metadata('package-metadata.json'), version: '0.3.30');
        self::assertSame('0.3.30', $release->version);
        self::assertSame('litebackup-linux-amd64', $release->file('litebackup-linux-amd64')->filename);
        $this->expectException(ReleaseNotListed::class);
        $release->file('missing');
    }

    #[RequiresPhpExtension('sodium')]
    public function testTamperedMetadataIsRefused(): void
    {
        $this->expectException(ReleaseSignatureError::class);
        Release::findRelease(self::metadata('package-metadata-tampered.json'), version: '0.3.30');
    }

    #[RequiresPhpExtension('sodium')]
    public function testMissingReleaseIsNotListed(): void
    {
        $this->expectException(ReleaseNotListed::class);
        Release::findRelease(self::metadata('package-metadata.json'), version: '9.9.9');
    }

    /** Without ext-sodium, verification fails with its own error, never as a tampered release. */
    public function testVerificationWithoutSodiumFailsCleanly(): void
    {
        $script = sys_get_temp_dir() . '/litebackup-release-' . bin2hex(random_bytes(3)) . '.php';
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $metadata = var_export(ContractFixtures::ROOT . '/binary/package-metadata.json', true);
        file_put_contents($script, <<<PHP
            <?php
            require {$autoload};
            try {
                Litebackup\\Release::findRelease(file_get_contents({$metadata}), version: '0.3.30');
                echo 'verified';
            } catch (Litebackup\\ReleaseVerificationUnavailable \$error) {
                echo 'unavailable: ', \$error->getMessage();
            }
            PHP);
        // -n loads no php.ini, so the shared sodium extension stays unloaded.
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' 2>&1');
        unlink($script);
        if (str_contains($output, 'verified')) {
            self::markTestSkipped('this PHP has sodium built in, so it cannot run without it');
        }
        self::assertStringStartsWith('unavailable: ', $output);
        self::assertStringContainsString('ext-sodium', $output);
    }
}
