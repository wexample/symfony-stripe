<?php

namespace Wexample\SymfonyStripe\Helper;

use function hash_hmac;

use Wexample\SymfonyHelpers\Helper\EnvironmentHelper;

class StripeHelper
{
    public static function isStripeTestEnvironment(
        string $environment
    ): bool {
        return in_array(
            $environment,
            EnvironmentHelper::LIST_LOW_SECURITY
        );
    }
    public static function buildFakeSignature(
        string $payload,
        string $secret
    ): string {
        $timestamp = time();
        $signedPayload = "{$timestamp}.{$payload}";

        return implode(',', [
            't='.$timestamp,
            'v1='.hash_hmac(
                'sha256',
                $signedPayload,
                $secret
            ), ]);
    }
}
