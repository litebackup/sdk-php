<?php

declare(strict_types=1);

namespace Litebackup;

/**
 * Telling an API key from a join token, as the daemon does. A credential is
 * an API key, or a join token the daemon exchanges once for a key of the
 * machine, then @ and the server's address.
 */
final class Credential
{
    /** Holds the credential a daemon uses when it starts. */
    public const API_KEY_ENVIRONMENT = 'LITEBACKUP_API_KEY';
    /** Join and invite tokens start with longer prefixes of an API key's. */
    public const API_KEY_PREFIX = 'litebackup_';
    public const JOIN_TOKEN_PREFIX = 'litebackup_join_';
    public const INVITE_PREFIX = 'litebackup_invite_';
    public const API_KEY = 'api_key';
    public const JOIN_TOKEN = 'join_token';

    /**
     * The server address a credential names, as the daemon checks it: an
     * optional http:// or https:// in any case, a host name or a bracketed
     * IPv6 address, an optional port, and an optional path without a query
     * or fragment, of ASCII URL characters and percent escapes.
     */
    private const SERVER_ADDRESS = '~\A(?:[Hh][Tt][Tt][Pp][Ss]?://)?(?:[A-Za-z0-9._-]+|\[[0-9A-Fa-f:.]+\])'
        . '(?::[0-9]{1,5})?(?:/(?:[A-Za-z0-9._\~!$&\'()*+,;=:/-]|%[0-9A-Fa-f]{2})*)?\z~';

    /**
     * Tells an API key from a join token by prefix: api_key or join_token,
     * or null for an empty credential, which leaves the daemon its saved
     * key. Throws invalid_argument, without repeating the credential, for an
     * invite token, a credential with neither prefix, or one without @ and
     * the server's address.
     */
    public static function credentialType(?string $keyAndServer): ?string
    {
        $stripped = trim((string) $keyAndServer, " \t\n\r\0\x0B\x0C");
        if ($stripped === '') {
            return null;
        }
        $separator = strrpos($stripped, '@');
        $secret = $separator === false ? '' : substr($stripped, 0, $separator);
        $server = $separator === false ? '' : substr($stripped, $separator + 1);
        if ($secret === '' || preg_match(self::SERVER_ADDRESS, $server) !== 1) {
            throw LitebackupError::invalidArgument(
                "expected <API_KEY>@<HOST>: an API key or a join token from Machines → Add machine, "
                . "then @ and the server's address: a host, or an http:// or https:// URL of one",
            );
        }
        if (str_starts_with($secret, self::INVITE_PREFIX)) {
            throw LitebackupError::invalidArgument(
                'this is an invite token, which signs a person in to the console; '
                . 'use an API key or a join token from Machines → Add machine',
            );
        }
        if (str_starts_with($secret, self::JOIN_TOKEN_PREFIX)) {
            return self::JOIN_TOKEN;
        }
        if (str_starts_with($secret, self::API_KEY_PREFIX)) {
            return self::API_KEY;
        }
        throw LitebackupError::invalidArgument(
            'expected an API key or a join token, which start with ' . self::API_KEY_PREFIX
            . '; get one from Machines → Add machine',
        );
    }
}
