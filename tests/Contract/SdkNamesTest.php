<?php

declare(strict_types=1);

namespace Litebackup\Tests\Contract;

use Litebackup\Client;
use Litebackup\Tests\Support\ContractFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Checks the PHP SDK against testdata/contract/names/sdk-names.json, the
 * names every SDK shares. PHP spells operations as TypeScript does, in
 * camelCase, and exports every shared type under Litebackup\.
 */
final class SdkNamesTest extends TestCase
{
    /** @return array{operations: list<array{operation: string}>, types: list<string>} */
    private static function sdkNames(): array
    {
        $text = (string) file_get_contents(ContractFixtures::ROOT . '/names/sdk-names.json');
        /** @var array{operations: list<array{operation: string}>, types: list<string>} */
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSharedOperationMethods(): void
    {
        $client = new Client(socketPath: '/nonexistent/client.sock');
        try {
            foreach (self::sdkNames()['operations'] as $entry) {
                [$group, $method] = explode('.', $entry['operation'], 2);
                self::assertTrue(property_exists($client, $group), $entry['operation']);
                $groupObject = $client->{$group};
                self::assertIsObject($groupObject);
                self::assertTrue(method_exists($groupObject, ContractFixtures::camelCase($method)), $entry['operation']);
            }
        } finally {
            $client->close();
        }
    }

    public function testSharedTypeExports(): void
    {
        foreach (self::sdkNames()['types'] as $typeName) {
            $exported = 'Litebackup\\' . $typeName;
            self::assertTrue(class_exists($exported) || interface_exists($exported), $typeName);
        }
    }
}
