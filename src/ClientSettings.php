<?php

declare(strict_types=1);

namespace Litebackup;

/**
 * What a client was created with, resolved once.
 *
 * @internal
 */
final readonly class ClientSettings
{
    /** @var array<string, string> */
    public array $environment;
    public ?string $apiKey;
    public ?string $session;
    public string $socketPath;
    public ?string $binaryPath;
    public bool $launch;
    public bool $explicitSocket;

    /**
     * @param array<array-key, mixed>|null $environment
     */
    public function __construct(
        mixed $apiKey,
        mixed $session,
        mixed $socketPath,
        mixed $binaryPath,
        mixed $launch,
        ?array $environment,
    ) {
        $this->environment = Launch::resolveEnvironment($environment);
        $apiKey = Wire::optionalText($apiKey, 'apiKey');
        $this->apiKey = $apiKey ?? ($this->environment[Credential::API_KEY_ENVIRONMENT] ?? null);
        Credential::credentialType($this->apiKey);
        if ($socketPath === '') {
            throw LitebackupError::invalidArgument('socketPath needs a path');
        }
        $session = Wire::optionalText($session, 'session');
        $this->explicitSocket = $socketPath !== null;
        $this->binaryPath = $binaryPath === null ? null : Wire::pathText($binaryPath);
        $this->launch = Wire::flag($launch, 'launch');
        if ($this->explicitSocket) {
            $this->session = $session;
            $this->socketPath = Wire::pathText($socketPath);
        } else {
            [$this->session, $this->socketPath] = Paths::sessionSocket($session, $this->environment);
        }
    }

    /** @return non-empty-list<string> */
    public function foregroundCommand(mixed $source, mixed $name, mixed $prefix, mixed $durability, mixed $timeout): array
    {
        if ($this->explicitSocket) {
            throw LitebackupError::invalidArgument('foreground backup needs a named session and cannot target socketPath');
        }
        $binary = Binary::litebackupBinary($this->environment, $this->binaryPath);
        return Foreground::backupCommand($binary, $source, $this->session, $name, $prefix, $durability, $timeout);
    }

    /** @return array<string, string> */
    public function foregroundEnvironment(): array
    {
        if ($this->apiKey === null) {
            return $this->environment;
        }
        return [Credential::API_KEY_ENVIRONMENT => $this->apiKey] + $this->environment;
    }
}
