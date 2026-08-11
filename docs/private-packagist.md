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
export PRIVATE_PACKAGIST_USERNAME=padraig_threadable
composer config --global --auth http-basic.repo.packagist.com "$PRIVATE_PACKAGIST_USERNAME" "$PRIVATE_PACKAGIST_TOKEN"
composer require threadable/qality-plus
```

For example, a user token supplied by Private Packagist uses a username and
token in this form:

```bash
composer config --global --auth http-basic.repo.packagist.com \
    padraig_threadable packagist_uut_...
```

For shared CI, prefer a scoped organization update token over a personal user
token. A personal `packagist_uut_...` token has the access of its owner.

The token is written to Composer’s global `auth.json`. Never commit that file
or place the token in `composer.json`, `composer.lock`, or a `.env` file that
could be checked into source control.

## CI/CD installation

Store the token as a secured CI/CD secret. In GitHub Actions, create a
repository or environment secrets named `PRIVATE_PACKAGIST_USERNAME` and
`PRIVATE_PACKAGIST_TOKEN`, then configure Composer before installing
dependencies:

```yaml
- name: Configure Private Packagist authentication
  env:
    PRIVATE_PACKAGIST_USERNAME: ${{ secrets.PRIVATE_PACKAGIST_USERNAME }}
    PRIVATE_PACKAGIST_TOKEN: ${{ secrets.PRIVATE_PACKAGIST_TOKEN }}
  run: composer config --global --auth http-basic.repo.packagist.com "$PRIVATE_PACKAGIST_USERNAME" "$PRIVATE_PACKAGIST_TOKEN"

- name: Install dependencies
  run: composer install --prefer-dist --no-interaction --no-progress
```

The package repository’s workflow reads these secrets when resolving its
private Composer dependencies. If the username or token is incorrect,
Composer returns HTTP 401 with an authentication failure.

## Repository configuration

The package’s own `composer.json` may contain the Private Packagist repository
and disable the public Packagist repository for development and CI. This does
not configure consuming Laravel applications; each application must declare
its own root-level repository configuration.
