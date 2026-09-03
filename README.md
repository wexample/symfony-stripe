# symfony_stripe

Version: 1.0.89

`wexample/symfony-stripe` is a Composer library for Symfony applications that integrate Stripe. It ships a single static class, `Wexample\SymfonyStripe\Helper\StripeHelper` in src/Helper/StripeHelper.php, with two functions: `isStripeTestEnvironment()`, which reports whether an environment name belongs to `EnvironmentHelper::LIST_LOW_SECURITY` (`dev`, `local`, `test`) and so should talk to Stripe in test mode, and `buildFakeSignature()`, which forges a `t=…,v1=…` signature header from a payload and a webhook secret. It depends only on `wexample/symfony-helpers` — no Stripe SDK — so a webhook controller can be exercised locally and in tests without a call to Stripe.

## Table of Contents

- [Architecture](#architecture)
- [Integration in the Suite](#integration-in-the-suite)
- [Dependencies](#dependencies)
- [Versioning & Compatibility Policy](#versioning--compatibility-policy)
- [License](#license)
- [About us](#about-us)
- [Migration Notes](#migration-notes)

## Architecture

### One file, no bundle

The package is a Composer library, not a Symfony bundle. composer.json declares `"type": "library"` and a single autoload rule:

```json
"psr-4": {
  "Wexample\\SymfonyStripe\\": "src/"
}
```

There is no bundle class, no `DependencyInjection/`, no `config/services.yaml`, no `Resources/`. Nothing is registered in the host application's `bundles.php` and nothing is wired into the container — installing the package only adds a namespace to the autoloader. The entire shipped surface is src/Helper/StripeHelper.php.

### `StripeHelper`

`Wexample\SymfonyStripe\Helper\StripeHelper` is a plain class with two public static methods, no constructor, no properties and no state. Callers reach it statically, which is why the absence of a container registration costs nothing.

Both methods take everything they need as arguments. Neither reads an environment variable, a parameter bag or a configuration file: the environment name and the webhook secret arrive as `string` parameters. The package therefore makes no decision about where the Stripe keys live — the host application does.

### The two call paths

`isStripeTestEnvironment()` is a lookup and nothing else:

```php
return in_array(
    $environment,
    EnvironmentHelper::LIST_LOW_SECURITY
);
```

`EnvironmentHelper::LIST_LOW_SECURITY` comes from `wexample/symfony-helpers` and holds `dev`, `local`, `test`. That `use Wexample\SymfonyHelpers\Helper\EnvironmentHelper;` is the only edge leaving the package, matching the sole `require` entry, `"wexample/symfony-helpers": ">=5.0.0"`.

`buildFakeSignature()` reproduces Stripe's `Stripe-Signature` header locally:

```php
$timestamp = time();
$signedPayload = "{$timestamp}.{$payload}";

return implode(',', [
    't='.$timestamp,
    'v1='.hash_hmac(
        'sha256',
        $signedPayload,
        $secret
    ), ]);
```

It calls `time()` and `hash_hmac()` — the file imports the latter explicitly with `use function hash_hmac;` — and returns a string. No HTTP client, no Stripe SDK, no dependency on the Stripe PHP library at all. A webhook controller can be posted to from a test case, and its signature verifier will accept the payload without any call reaching Stripe.

### What this shape implies when editing

Because Stripe's SDK is not a dependency, the signature scheme in `buildFakeSignature()` is knowledge duplicated from Stripe's specification. If Stripe moves past scheme `v1`, this file is what changes; nothing else in the package knows the format.

New code goes under `src/<Domain>/`, following PSR-4 — `Helper/` is a directory, not a special case. Introducing a non-static, injectable service would be a structural change, not an addition: it would require a bundle class and a DI extension that the package does not currently have. The current staticness and the single dependency are what let the helpers run in a unit test with no kernel booted.

## Integration in the Suite

This package is part of the Wexample Suite — a collection of high-quality, modular tools designed to work seamlessly together across multiple languages and environments.

### Related Packages

The suite includes packages for configuration management, file handling, prompts, and more. Each package can be used independently or as part of the integrated suite.

Visit the [Wexample Suite documentation](https://docs.wexample.com) for the complete package ecosystem.

## Dependencies

- wexample/symfony-helpers: >=6.0.0

## Versioning & Compatibility Policy

Wexample packages follow **Semantic Versioning** (SemVer):

- **MAJOR**: Breaking changes
- **MINOR**: New features, backward compatible
- **PATCH**: Bug fixes, backward compatible

We maintain backward compatibility within major versions and provide clear migration guides for breaking changes.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Free to use in both personal and commercial projects.

## About us

[Wexample](https://wexample.com) stands as a cornerstone of the digital ecosystem — a collective of seasoned engineers, researchers, and creators driven by a relentless pursuit of technological excellence. More than a media platform, it has grown into a vibrant community where innovation meets craftsmanship, and where every line of code reflects a commitment to clarity, durability, and shared intelligence.

This packages suite embodies this spirit. Trusted by professionals and enthusiasts alike, it delivers a consistent, high-quality foundation for modern development — open, elegant, and battle-tested. Its reputation is built on years of collaboration, refinement, and rigorous attention to detail, making it a natural choice for those who demand both robustness and beauty in their tools.

Wexample cultivates a culture of mastery. Each package, each contribution carries the mark of a community that values precision, ethics, and innovation — a community proud to shape the future of digital craftsmanship.

## Migration Notes

When upgrading between major versions, refer to the migration guides in the documentation.

Breaking changes are clearly documented with upgrade paths and examples.
