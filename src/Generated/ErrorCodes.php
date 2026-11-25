<?php

// Code generated from client-openapi.yaml. DO NOT EDIT.

declare(strict_types=1);

namespace Litebackup\Generated;

/** The socket API spec's error codes; ErrorCode takes them. */
final class ErrorCodes
{
    public const BUSY = 'busy';
    public const CANCELLED = 'cancelled';
    public const CHANGED = 'changed';
    public const CLIENT_UNSUPPORTED = 'client_unsupported';
    public const CONFLICT = 'conflict';
    public const CURSOR_EXPIRED = 'cursor_expired';
    public const DRAIN_REQUIRED = 'drain_required';
    public const FILE_EXISTS = 'file_exists';
    public const FORBIDDEN = 'forbidden';
    public const INTEGRITY = 'integrity';
    public const INTERNAL = 'internal';
    public const INVALID_ARGUMENT = 'invalid_argument';
    public const LAUNCH_FAILED = 'launch_failed';
    public const LIMIT_EXCEEDED = 'limit_exceeded';
    public const LOCK_LOST = 'lock_lost';
    public const LOCK_UNAVAILABLE = 'lock_unavailable';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';
    public const NOT_CONFIGURED = 'not_configured';
    public const NOT_DURABLE = 'not_durable';
    public const NOT_FOUND = 'not_found';
    public const PLAN_LIMIT = 'plan_limit';
    public const RULE_DATABASE = 'rule_database';
    public const RULE_EXISTS = 'rule_exists';
    public const SDK_UNSUPPORTED = 'sdk_unsupported';
    public const STILL_OPEN = 'still_open';
    public const TIMEOUT = 'timeout';
    public const UNAUTHENTICATED = 'unauthenticated';
    public const UNAVAILABLE = 'unavailable';
    public const WRONG_SERVER = 'wrong_server';
}
