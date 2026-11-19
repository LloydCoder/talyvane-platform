# Dependency management and reproducible builds

## Policy

- Commit `composer.json` and `composer.lock` together for every application release.
- Resolve the dependency graph against the minimum supported PHP runtime (currently PHP 8.3.0), not the PHP version installed on an individual developer or CI runner.
- Production and CI installation use `composer install`; dependency upgrades use an intentional `composer update` on a reviewed branch.
- Run Composer validation, security advisory auditing and actual runtime platform checks for every supported PHP version.
- Do not silently regenerate dependencies during a production deployment.
- Review direct and transitive dependency changes, package licences, abandoned packages, advisories and release notes before merging an update.

## Runtime compatibility

The Composer configuration pins `config.platform.php` to `8.3.0`. This makes dependency resolution target the declared minimum runtime. CI still runs `composer check-platform-reqs` on PHP 8.3, 8.4 and 8.5 so the simulated platform does not conceal missing extensions or runtime incompatibilities.

The repository currently requires PHP 8.3+, Laravel 13, and GD. The CI matrix is the compatibility contract for the versions tested by this project; it is not a guarantee for untested PHP releases or hosting-provider configurations.

## Lockfile bootstrap

Until the first lockfile is merged, CI temporarily falls back to `composer update` and emits a warning. The `Bootstrap Composer lockfile` workflow is restricted to pushes or manual dispatches on `main`. It resolves and audits dependencies on PHP 8.4 while targeting PHP 8.3.0, creates or refreshes a bot-owned branch, updates CI to require `composer install`, and dispatches the CI matrix against that branch. A maintainer then opens and reviews the pull request using the repository's normal review path.

The bootstrap workflow does not run for pull requests and does not merge its own pull request. A maintainer must review the generated dependency diff and wait for all PHP matrix jobs to pass before merging. After the lockfile is present on `main`, the bootstrap workflow exits without writing another branch.

## Routine dependency updates

1. Create a dedicated dependency-update branch.
2. Run `composer update` with the supported Composer 2 release and PHP 8.3-compatible platform configuration.
3. Inspect the complete `composer.lock` diff, including transitive packages.
4. Run `composer validate`, `composer audit`, `composer check-platform-reqs`, PHP lint, route/Blade validation and the full test suite.
5. Verify the PHP 8.3, 8.4 and 8.5 CI matrix.
6. Review package licence obligations and update the software bill of materials.
7. Merge only after review and green CI.

## Release requirements

A tagged release must be built from a clean checkout using the committed lockfile and `composer install --no-dev --prefer-dist --optimize-autoloader`. The release pipeline must record the commit SHA, PHP and Composer versions, dependency audit result, build artifact checksum and release contents. Production installations must never run `composer update`.
