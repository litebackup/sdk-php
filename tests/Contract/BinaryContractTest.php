<?php

declare(strict_types=1);

namespace Litebackup\Tests\Contract;

use Litebackup\Binary;
use Litebackup\Client;
use Litebackup\Foreground;
use Litebackup\LitebackupError;
use Litebackup\Tests\Support\ContractFixtures;
use Litebackup\Tests\Support\DaemonTestCase;

/**
 * An explicit binary that names no file is binary_not_found, one behind a
 * directory this user may not enter is returned, and one that cannot run
 * fails as launch_failed, naming its first line. The package bundles no
 * binary, so lookup goes from the override to PATH and ~/.local/bin.
 */
final class BinaryContractTest extends DaemonTestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return ContractFixtures::read('binary', 'override-missing');
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return ['PATH' => (string) getenv('PATH'), 'HOME' => $this->workingDirectory, 'XDG_RUNTIME_DIR' => $this->workingDirectory . '/run'];
    }

    public function testMissingOverride(): void
    {
        $fixture = self::fixture();
        /** @var list<string> $overrides */
        $overrides = $fixture['overrides'];
        foreach ($overrides as $override) {
            $missing = $this->workingDirectory . '/' . ContractFixtures::text($fixture, 'missing_file');
            $environment = $this->environment();
            $option = null;
            if ($override === 'option') {
                $option = $missing;
            } else {
                $environment['LITEBACKUP_PATH'] = $missing;
            }
            try {
                Binary::litebackupBinary($environment, $option);
                self::fail($override . ' was found');
            } catch (LitebackupError $error) {
                self::assertSame($fixture['code'], $error->errorCode, $override);
                self::assertStringContainsString(ContractFixtures::text($fixture, 'message_contains'), $error->getMessage());
                self::assertStringContainsString($missing, $error->getMessage());
            }
        }
    }

    public function testOverrideThatExists(): void
    {
        $fixture = self::fixture();
        if (posix_geteuid() !== 0) {
            $denied = $this->workingDirectory . '/' . ContractFixtures::text($fixture, 'denied_file');
            mkdir(dirname($denied));
            file_put_contents($denied, "#!/bin/sh\n");
            chmod($denied, 0o755);
            chmod(dirname($denied), 0);
            try {
                self::assertSame($denied, Binary::litebackupBinary($this->environment(), $denied));
            } finally {
                chmod(dirname($denied), 0o755);
            }
        }
        $script = $this->workingDirectory . '/litebackup';
        file_put_contents($script, ContractFixtures::text($fixture, 'interpreter_script'));
        chmod($script, 0o755);
        $client = new Client(session: 'jobs', binaryPath: $script, environment: $this->environment());
        try {
            $client->backups->once($this->workingDirectory . '/a.db');
            self::fail('the backup ran');
        } catch (LitebackupError $error) {
            self::assertSame($fixture['interpreter_code'], $error->errorCode);
            self::assertStringContainsString(ContractFixtures::text($fixture, 'interpreter_message_contains'), $error->getMessage());
        } finally {
            $client->close();
        }
    }

    public function testOverrideUnderAFile(): void
    {
        $fixture = self::fixture();
        $underFile = $this->workingDirectory . '/' . ContractFixtures::text($fixture, 'not_directory_file');
        file_put_contents(dirname($underFile), 'a file, not a directory');
        $client = new Client(session: 'jobs', binaryPath: $underFile, environment: $this->environment());
        try {
            $client->backups->once($this->workingDirectory . '/a.db');
            self::fail('the backup ran');
        } catch (LitebackupError $error) {
            self::assertSame($fixture['code'], $error->errorCode);
        } finally {
            $client->close();
        }
        self::assertSame($fixture['code'], Foreground::startFailure($underFile, 'not a directory')->errorCode);
    }

    public function testInstalledCommandIsFoundOnPathThenInLocalBin(): void
    {
        $onPath = $this->workingDirectory . '/bin/litebackup';
        $local = $this->workingDirectory . '/.local/bin/litebackup';
        foreach ([$onPath, $local] as $path) {
            mkdir(dirname($path), 0o755, true);
            file_put_contents($path, "#!/bin/sh\n");
            chmod($path, 0o755);
        }
        self::assertSame($onPath, Binary::litebackupBinary(['PATH' => dirname($onPath), 'HOME' => $this->workingDirectory]));
        self::assertSame($local, Binary::litebackupBinary(['PATH' => '/nonexistent', 'HOME' => $this->workingDirectory]));
        unlink($local);
        try {
            Binary::litebackupBinary(['PATH' => '/nonexistent', 'HOME' => $this->workingDirectory]);
            self::fail('a binary was found');
        } catch (LitebackupError $error) {
            self::assertSame('binary_not_found', $error->errorCode);
            self::assertStringContainsString(Binary::installCommand(), $error->getMessage());
        }
    }
}
