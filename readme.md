# Framework

This is a framework of feature modules for WordPress that are shared across plugins.

Many of these were refactored from the deprecated [plugin framework v2](https://bitbucket.org/tangibleinc/tangible-plugin-framework).

#### Source code

Git repository: https://github.com/tangibleinc/framework

## Install

Add as a production dependency in `tangible.config.js`.

```js
export default {
  install: [
    {
      git: 'git@github.com:tangibleinc/framework',
      dest: 'vendor/tangible/framework',
      branch: 'main',
    },
  ]
}
```

Run `npm run install` or `npx roll install`.

Alternatively, add in `composer.json` and run `composer update`.

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:tangibleinc/framework"
    }
  ],
  "require": {
    "tangible/framework": "dev-main"
  },
  "minimum-stability": "dev"
}
```

## Use

After loading the framework, its newest version instance is ready on `plugins_loaded` action.

```php
use tangible\framework;

require_once __DIR__ . '/vendor/tangible/framework/index.php';

add_action('plugins_loaded', function() {

  // Newest version of Framework is ready

});
```

### Note on plugin activation

During plugin activation, such as after install or update, WordPress runs the `plugins_loaded` action *before* loading plugins and modules, short-circuiting the version comparison logic. This can cause an older version of a module to load with missing features.

It is recommended to check for the constant `WP_SANDBOX_SCRAPING`, and skip loading the rest of the plugin when it's defined.

```php
if (defined('WP_SANDBOX_SCRAPING')) return;

// ..Register with Framework and load the rest of plugin..
```

This guarantees the availability of the newest version of all modules. For more details, see:

- https://developer.wordpress.org/reference/functions/register_activation_hook/#more-information
- https://github.com/WordPress/wordpress-develop/blob/8a52d746e9bb85604f6a309a87d22296ce1c4280/src/wp-admin/includes/plugin.php#L2381C10-L2381C31


## Modules

- [Admin](admin)
- [AJAX](ajax)
- [API](api)
- [Date](date)
- [Design](design)
- [Format](format)
- [HJSON](hjson)
- [Log](log)
- [Object](object)
- [Plugin](plugin)
- [Select](select)

## Develop

Prerequisites: [Git](https://git-scm.com/), [Node](https://nodejs.org/en/) (version 18 and above)

Clone the repository and install dependencies.

```sh
git clone git@github.com:tangibleinc/framework.git
cd framework
npm install
```

### JS and CSS

Build for development - watch files for changes and rebuild

```sh
npm run dev
```

Build for production

```sh
npm run build
```

Format to code standard

```sh
npm run format
```

#### Build modules for development

Watch files for changes and rebuild.

```sh
npm run dev [module1 module2..]
```

Press CTRL + C to stop.

#### Build modules for production

Builds minified bundles with source maps.

```sh
npm run build [module1 module2..]
```

#### Format code

Format files to code standard with [Prettier](https://prettier.io) and [PHP Beautify](https://github.com/tangibleinc/php-beautify).

```sh
npm run format [module1 module2..]
```

### Local dev site

Start a local dev site using [`wp-now`](https://github.com/WordPress/playground-tools/blob/trunk/packages/wp-now/README.md).

```sh
npm run now
```

Press CTRL + C to stop.

#### Dev dependencies

Optionally, install dev dependencies such as third-party plugins before starting the site.

```sh
npm run install:dev
```

To keep them updated, run:

```sh
npm run update:dev
```

#### Customize environment

Create a file named `.wp-env.override.json` to customize the WordPress environment. This file is listed in `.gitignore` so it's local to your setup.

Mainly it's useful for mounting local folders into the virtual file system. For example, to link another plugin in the parent directory:

```json
{
  "mappings": {
    "wp-content/plugins/example-plugin": "../example-plugin"
  }
}
```

## Tests

This plugin comes with a suite of unit and integration tests.

The test environment is started by running:

```sh
npm run start
```

This uses [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) to set up a local dev and test site, optionally switching between multiple PHP versions. It requires **Docker** to be installed. There are instructions available for installing Docker on [Windows](https://docs.docker.com/desktop/install/windows-install/), [macOS](https://docs.docker.com/desktop/install/mac-install/), and [Linux](https://docs.docker.com/desktop/install/linux-install/).

Visit [http://localhost:8888](http://localhost:8888) to see the dev site, and [http://localhost:8889](http://localhost:8880) for the test site, whose database is cleared on every run.

Before running tests, install PHPUnit as a dev dependency using Composer inside the container.

```sh
npm run composer:install
```

Composer will add and remove folders in the `vendor` folder, based on `composer.json` and `composer.lock`. If you have any existing Git repositories, ensure they don't have any work in progress before running the above command.

Run the tests:

```sh
npm run test
```

For each PHP version:

```sh
npm run test:7.4
npm run test:8.2
```

The version-specific commands take a while to start, but afterwards you can run `npm run env:test` to re-run tests in the same environment.

To stop the Docker process:

```sh
npm run stop
```

To remove Docker containers, volumes, images associated with the test environment.

```sh
npm run env:destroy
```

#### Notes

To run more than one instance of `wp-env`, set different ports for the dev and test sites:

```sh
WP_ENV_PORT=3333 WP_ENV_TESTS_PORT=3334 npm run env:start
```

---

This repository includes NPM scripts to run the tests with PHP versions 8.2 and 7.4. We need to maintain compatibility with PHP 7.4, as WordPress itself only has “beta support” for PHP 8.x. See https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/ for more information.

---

If you’re on Windows, you might have to use [Windows Subsystem for Linux](https://learn.microsoft.com/en-us/windows/wsl/install) to run the tests (see [this comment](https://bitbucket.org/tangibleinc/tangible-fields-module/pull-requests/30#comment-389568162)).

### End-to-end tests

The folder `/tests/e2e` contains end-to-end-tests using [Playwright](https://playwright.dev/docs/intro) and [WordPress E2E Testing Utils](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-e2e-test-utils-playwright/).

#### Prepare

Before the first time you run it, install the browser engine.

```sh
npx playwright install chromium
```

#### Run

Run the tests. This will start the local WordPress environment with `wp-env` as needed. Then Playwright starts a browser engine to interact with the test site.

```sh
npm run test:e2e
```

#### Watch mode

There is a "Watch mode", where it will watch the test files for changes and re-run them. 
This provides a helpful feedback loop when writing tests, as a kind of test-driven development. Press CTRL + C to stop the process.

```sh
npm run test:e2e:watch
```

A common usage is to have terminal sessions open with `npm run dev` (build assets and watch to rebuild) and `npm run tdd` (run tests and watch to re-run).

#### UI mode

There's also "UI mode" that opens a browser interface to see the tests run.

```sh
npm run test:e2e:ui
```

#### Utilities

Here are the common utilities used to write the tests.

- `test` - https://playwright.dev/docs/api/class-test
- `expect` - https://playwright.dev/docs/api/class-genericassertions
- `admin` - https://github.com/WordPress/gutenberg/tree/trunk/packages/e2e-test-utils-playwright/src/admin
- `page` - https://playwright.dev/docs/api/class-page
- `request` - https://playwright.dev/docs/api/class-apirequestcontext

#### References

Examples of how to write end-to-end tests:

- WordPress E2E tests - https://github.com/WordPress/wordpress-develop/blob/trunk/tests/e2e
- Gutenberg E2E tests - https://github.com/WordPress/gutenberg/tree/trunk/test/e2e
