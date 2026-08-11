# Private Packagist setup

This package can be installed from an organization’s Private Packagist
repository. Composer repository and authentication settings belong in the
Laravel application that installs the package; Composer does not inherit
repositories from dependencies.

## Local installation

Add the Threadable Private Packagist repository to the Laravel application:

```bash
composer config repositories.private-packagist composer https://repo.packagist.com/threadable/
```

Configure a Private Packagist token locally, then install the package:

```bash
export PRIVATE_PACKAGIST_TOKEN=...
composer config --global --auth http-basic.repo.packagist.com token "$PRIVATE_PACKAGIST_TOKEN"
composer require threadable/qality-plus
```

The token is written to Composer’s global `auth.json`. Never commit that file
or place the token in `composer.json`, `composer.lock`, or a `.env` file that
could be checked into source control.

## CI/CD installation

Store the token as a secured CI/CD secret. In GitHub Actions, create a
repository or environment secret named `PRIVATE_PACKAGIST_TOKEN` and configure
Composer before installing dependencies:

```yaml
- name: Configure Private Packagist authentication
  env:
    PRIVATE_PACKAGIST_TOKEN: ${{ secrets.PRIVATE_PACKAGIST_TOKEN }}
  run: composer config --global --auth http-basic.repo.packagist.com token "$PRIVATE_PACKAGIST_TOKEN"

- name: Install dependencies
  run: composer install --prefer-dist --no-interaction --no-progress
```

The package repository’s workflow also reads this secret when resolving its
private Composer dependencies. If the secret is not configured, dependency
resolution will fail when the private repository requires authentication.

## Repository configuration

The package’s own `composer.json` may contain the Private Packagist repository
and disable the public Packagist repository for development and CI. This does
not configure consuming Laravel applications; each application must declare
its own root-level repository configuration.
