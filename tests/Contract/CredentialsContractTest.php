<?php

declare(strict_types=1);

namespace Litebackup\Tests\Contract;

use Litebackup\Credential;
use Litebackup\LitebackupError;
use Litebackup\Tests\Support\ContractFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A LITEBACKUP_API_KEY value is an API key, a join token, none, or refused without being repeated. */
final class CredentialsContractTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        return ContractFixtures::provide('credentials');
    }

    #[DataProvider('fixtures')]
    public function testCredential(string $name): void
    {
        $fixture = ContractFixtures::read('credentials', $name);
        $apiKey = is_string($fixture['api_key'] ?? null) ? $fixture['api_key'] : null;
        if (isset($fixture['expect'])) {
            /** @var array{code: string} $expected */
            $expected = $fixture['expect'];
            try {
                Credential::credentialType($apiKey);
                self::fail('the credential was accepted');
            } catch (LitebackupError $error) {
                self::assertSame($expected['code'], $error->errorCode);
                $secret = explode('@', trim((string) $apiKey), 2)[0];
                if ($secret !== '') {
                    self::assertStringNotContainsString($secret, $error->getMessage());
                }
            }
            return;
        }
        self::assertSame($fixture['type'] ?? null, Credential::credentialType($apiKey));
    }
}
