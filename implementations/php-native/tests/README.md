# Native PHP test strategy

The native PHP implementation uses a dependency-free test runner initially so maintainers can execute checks without Composer on shared hosting or local environments.

Run:

```sh
php implementations/php-native/bin/test.php
```

Lint all PHP source:

```sh
find implementations/php-native -name '*.php' -print0 | xargs -0 -n1 php -l
```

Required suites:

- **Unit** — domain invariants, value objects, policy decisions, validation, and pure application logic.
- **Integration** — PDO repositories, transaction behavior, migrations, filesystem boundaries, mail adapters, and installer state.
- **Security** — authentication, authorization, CSRF, session fixation, rate limits, upload handling, path traversal, secret/log redaction, and safeguarding access controls.
- **End-to-end** — browser-based installation, administrator creation, email verification, first-login password replacement, MFA enrollment, recovery codes, installer lock, and representative customer/talent/admin journeys.

Tests must run without production credentials or production data. Test fixtures must be synthetic. Do not mark a suite as passing unless its command was actually executed and its result recorded.
