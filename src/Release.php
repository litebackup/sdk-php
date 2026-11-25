<?php

declare(strict_types=1);

namespace Litebackup;

use JsonException;

/**
 * Verifying Litebackup's signed package metadata, as
 * https://litebackup.com/pkg/metadata serves it. The release is always read
 * from its signed document, never from the unsigned listing. Needs PHP's
 * sodium extension (ext-sodium), which most builds include.
 */
final class Release
{
    /**
     * Litebackup's Ed25519 release keys by key ID, base64url without
     * padding, as the litebackup command checks them.
     */
    public const RELEASE_KEYS = [
        '01a1ead6-4b94-79ca-81f4-3a67c112bc0b' => 't3C_3l71PimyhkPZOzJ_Xi4dOuHJ1CmSdDZ8lTm0vNI',
    ];
    public const COMMAND_PACKAGE = 'cli';
    public const STABLE_CHANNEL = 'stable';
    private const SIGNATURE_CONTEXT = "clor-apps-v1\0";
    private const SIGNATURE_SEPARATOR = "\0";

    /**
     * Reads package metadata and returns the selected release from its
     * signed document: the release with version, or else the newest on
     * channel. Throws ReleaseSignatureError when the signature does not
     * verify or the signed version is not the listed one, ReleaseNotListed
     * for a missing package or release, and ReleaseVerificationUnavailable
     * without ext-sodium.
     *
     * @param string|array<array-key, mixed> $metadata the metadata's JSON, or it decoded
     * @param array<string, string> $keys
     */
    public static function findRelease(
        string|array $metadata,
        string $package = self::COMMAND_PACKAGE,
        ?string $version = null,
        string $channel = self::STABLE_CHANNEL,
        array $keys = self::RELEASE_KEYS,
    ): PackageRelease {
        $listing = is_array($metadata) ? $metadata : self::decodeObject($metadata, 'the package metadata');
        $listed = null;
        foreach (self::entries($listing['packages'] ?? null) as $candidate) {
            if (is_array($candidate) && ($candidate['slug'] ?? null) === $package) {
                $listed = $candidate;
                break;
            }
        }
        if ($listed === null) {
            throw new ReleaseNotListed('no package ' . $package);
        }
        $chosen = null;
        foreach (self::entries($listed['releases'] ?? null) as $release) {
            if (!is_array($release)) {
                continue;
            }
            $matches = $version === null
                ? in_array($channel, self::entries($release['channels'] ?? null), true)
                : ($release['version'] ?? null) === $version;
            if ($matches) {
                $chosen = $release;
                break;
            }
        }
        if ($chosen === null) {
            throw new ReleaseNotListed('package ' . $package . ' has no release ' . ($version ?? 'on ' . $channel));
        }
        $document = self::verify($chosen['signature'] ?? null, $keys);
        if (($document['version'] ?? null) !== ($chosen['version'] ?? null) || !is_string($document['version'])) {
            throw new ReleaseSignatureError();
        }
        $files = [];
        foreach (self::entries($document['artifacts'] ?? null) as $artifact) {
            if (!is_array($artifact)) {
                throw new ReleaseSignatureError();
            }
            $files[] = new ReleaseFile(
                self::field($artifact, 'name'),
                self::field($artifact, 'filename'),
                self::field($artifact, 'url'),
                self::field($artifact, 'sha256'),
                is_int($artifact['size'] ?? null) ? $artifact['size'] : throw new ReleaseSignatureError(),
                is_string($artifact['os'] ?? null) ? $artifact['os'] : '',
                is_string($artifact['architecture'] ?? null) ? $artifact['architecture'] : '',
            );
        }
        $channels = array_values(array_filter(self::entries($chosen['channels'] ?? null), 'is_string'));
        return new PackageRelease($document['version'], $channels, $files);
    }

    /**
     * The signed document, decoded, once its signature matches one of keys.
     *
     * @param array<string, string> $keys
     * @return array<array-key, mixed>
     */
    public static function verify(mixed $signed, array $keys): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new ReleaseVerificationUnavailable(
                "verifying Litebackup's release signatures needs PHP's sodium extension (ext-sodium); install or enable it",
            );
        }
        if (!is_array($signed) || !is_string($signed['key_id'] ?? null) || !isset($keys[$signed['key_id']])) {
            throw new ReleaseSignatureError();
        }
        $keyID = $signed['key_id'];
        $publicKey = self::base64url($keys[$keyID]);
        $document = self::base64url($signed['document'] ?? null);
        $signature = self::base64url($signed['signature'] ?? null);
        $message = self::SIGNATURE_CONTEXT . $keyID . self::SIGNATURE_SEPARATOR . $document;
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new ReleaseSignatureError();
        }
        if (!sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new ReleaseSignatureError();
        }
        return self::decodeObject($document, 'the signed document', true);
    }

    private static function base64url(mixed $text): string
    {
        if (!is_string($text)) {
            throw new ReleaseSignatureError();
        }
        $decoded = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        if ($decoded === false) {
            throw new ReleaseSignatureError();
        }
        return $decoded;
    }

    /** @return array<array-key, mixed> */
    private static function decodeObject(string $text, string $what, bool $signed = false): array
    {
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            if ($signed) {
                throw new ReleaseSignatureError();
            }
            throw new ReleaseNotListed($what . ' is not JSON: ' . $error->getMessage());
        }
        if (!is_array($decoded)) {
            if ($signed) {
                throw new ReleaseSignatureError();
            }
            throw new ReleaseNotListed($what . ' is not a JSON object');
        }
        return $decoded;
    }

    /** @return list<mixed> */
    private static function entries(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @param array<array-key, mixed> $artifact */
    private static function field(array $artifact, string $key): string
    {
        $value = $artifact[$key] ?? null;
        return is_string($value) ? $value : throw new ReleaseSignatureError();
    }
}
