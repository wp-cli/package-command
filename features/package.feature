Feature: Manage WP-CLI packages

  Scenario: Package CRUD
    Given an empty directory

    When I run `wp package browse`
    Then STDOUT should contain:
      """
      runcommand/hook
      """

    When I run `wp package install runcommand/hook`
    Then STDERR should be empty

    When I run `wp help hook`
    Then STDERR should be empty
    And STDOUT should contain:
      """
      List callbacks registered to a given action or filter.
      """

    When I try `wp --skip-packages --debug help hook`
    Then STDERR should contain:
      """
      Debug (bootstrap): Skipped loading packages.
      """
    And STDERR should contain:
      """
      Warning: No WordPress install
      """

    When I run `wp package list`
    Then STDOUT should contain:
      """
      runcommand/hook
      """

    When I run `wp package uninstall runcommand/hook`
    Then STDERR should be empty

    When I run `wp package list`
    Then STDOUT should not contain:
      """
      runcommand/hook
      """

  Scenario: Run package commands early, before any bad code can break them
    Given an empty directory
    And a bad-command.php file:
      """
      <?php
      WP_CLI::error( "Doing it wrong." );
      """

    When I try `wp --require=bad-command.php option`
    Then STDERR should contain:
      """
      Error: Doing it wrong.
      """

    When I run `wp --require=bad-command.php package list`
    Then STDERR should be empty

  Scenario: Revert composer.json when Composer cannot resolve an install or uninstall
    Given an empty directory

    When I run `wp package list --skip-update-check`
    And I run `wp package path`
    Then save STDOUT as {PACKAGE_PATH}

    When I run `cp {PACKAGE_PATH}/composer.json before.json`
    And I try `wp package install runcommand/hook:999999.0.0`
    Then the return code should not be 0
    And STDERR should contain:
      """
      Reverted composer.json.
      """

    When I run `cmp before.json {PACKAGE_PATH}/composer.json`
    Then the return code should be 0

    Given a kept/composer.json file:
      """
      {"name":"local/kept","version":"1.0.0"}
      """
    When I run `wp package install ./kept`
    And I run `wp package install runcommand/hook`
    Then STDOUT should contain:
      """
      Success: Package installed.
      """

    When I run `wp eval "file_put_contents( '{PACKAGE_PATH}/composer.json', str_replace( '1.0.0', '999999.0.0', file_get_contents( '{PACKAGE_PATH}/composer.json' ) ) );" --skip-wordpress`
    And I run `cp {PACKAGE_PATH}/composer.json before.json`
    And I try `wp package uninstall runcommand/hook`
    Then the return code should not be 0
    And STDERR should contain:
      """
      Reverted composer.json.
      """

    When I run `cmp before.json {PACKAGE_PATH}/composer.json`
    Then the return code should be 0

  @github-api
  Scenario: Try to run with a bad WP_CLI_PACKAGES_DIR/composer.json
    Given an empty directory
    And a packages-bad-json/composer.json file:
      """
      {
        "name": "wp-cli/wp-cli",
      }
      """

    When I try `WP_CLI_PACKAGES_DIR={RUN_DIR}/packages-bad-json wp package list`
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Failed to get composer instance
      """
    And STDERR should contain:
      """
      Parse error
      """
    And STDOUT should be empty

    When I try `WP_CLI_PACKAGES_DIR={RUN_DIR}/packages-bad-json wp package install runcommand/hook`
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Failed to parse
      """
    And STDERR should contain:
      """
      Parse error
      """
    And STDOUT should contain:
      """
      Installing
      """

    When I try `WP_CLI_PACKAGES_DIR={RUN_DIR}/packages-bad-json wp package update`
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Failed to get composer instance
      """
    And STDERR should contain:
      """
      Parse error
      """
    And STDOUT should be empty

    Given a packages-no-such-package/composer.json file:
      """
      {
        "name": "wp-cli/wp-cli",
        "repositories": {
          "no-such-gituser/no-such-package": {
             "type": "vcs",
             "url": "https://github.com/no-such-gituser/no-such-package.git"
          }
        },
        "require": {
          "no-such-gituser/no-such-package": "dev-master"
        }
      }
      """
    And save the {RUN_DIR}/packages-no-such-package/composer.json file as {NO_SUCH_PACKAGE_COMPOSER_JSON}

    When I try `WP_CLI_PACKAGES_DIR={RUN_DIR}/packages-no-such-package wp package install runcommand/hook`
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Package installation failed (Composer return code 1).
      """
    And STDOUT should match /Repository not found|Could not read from remote repository/
    And STDERR should contain:
      """
      Reverted composer.json.
      """
    And STDOUT should contain:
      """
      Installing
      """
    And the packages-no-such-package/composer.json file should be:
      """
      {NO_SUCH_PACKAGE_COMPOSER_JSON}
      """

    When I try `WP_CLI_PACKAGES_DIR={RUN_DIR}/packages-no-such-package wp package update`
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Failed to update packages (Composer return code 1).
      """
    And STDOUT should match /Repository not found|Could not read from remote repository/
    And STDERR should not contain:
      """
      Reverted composer.json.
      """
    And STDOUT should not be empty
    And the packages-no-such-package/composer.json file should be:
      """
      {NO_SUCH_PACKAGE_COMPOSER_JSON}
      """

  @github-api
  Scenario: Uninstall a package with --no-interaction prevents Git credential prompts
    Given an empty directory

    # Install a real package first
    When I run `wp package install danielbachhuber/wp-cli-reset-post-date-command`
    Then STDOUT should contain:
      """
      Success: Package installed.
      """

    # Uninstall with --no-interaction should complete without hanging
    When I run `wp package uninstall danielbachhuber/wp-cli-reset-post-date-command --no-interaction`
    Then STDERR should be empty
    And STDOUT should contain:
      """
      Success: Uninstalled package.
      """

  Scenario: List packages with --skip-update-check flag
    Given an empty directory

    When I run `wp package install runcommand/hook`
    Then STDERR should be empty

    When I run `wp package list --skip-update-check --fields=name,update,update_version`
    Then STDOUT should contain:
      """
      runcommand/hook
      """
    And STDOUT should contain:
      """
      none
      """
    And STDOUT should not contain:
      """
      available
      """

    When I run `wp package uninstall runcommand/hook`
    Then STDERR should be empty

  Scenario: Get information about a single package
    Given an empty directory

    When I try `wp package get runcommand/hook`
    Then STDERR should contain:
      """
      Error: Package 'runcommand/hook' is not installed.
      """
    And the return code should be 1

    When I run `wp package install runcommand/hook`
    Then STDERR should be empty

    When I run `wp package get runcommand/hook`
    Then STDOUT should contain:
      """
      runcommand/hook
      """
    And STDOUT should contain:
      """
      version
      """

    When I run `wp package get runcommand/hook --fields=name,version`
    Then STDOUT should contain:
      """
      runcommand/hook
      """
    And STDOUT should contain:
      """
      version
      """

    When I run `wp package get runcommand/hook --fields=version --format=json`
    Then STDOUT should contain:
      """
      "version"
      """

    When I run `wp package get runcommand/hook --format=json`
    Then STDOUT should contain:
      """
      "name":"runcommand\/hook"
      """
    And STDOUT should contain:
      """
      "version"
      """

    When I run `wp package get runcommand/hook --skip-update-check --fields=name,update,update_version`
    Then STDOUT should contain:
      """
      runcommand/hook
      """
    And STDOUT should contain:
      """
      none
      """
    And STDOUT should not contain:
      """
      available
      """

    When I run `wp package uninstall runcommand/hook`
    Then STDERR should be empty

  Scenario: Download Composer once into the WP-CLI cache
    When I run `wp package path`
    Then save STDOUT as {PACKAGE_PATH}

    Given an empty directory
    And an empty cache
    And a local-package/composer.json file:
      """
      {"name":"local/cache-test","version":"1.0.0"}
      """

    When I run `wp package install ./local-package`
    Then STDERR should be empty
    And STDOUT should match /Downloading Composer 2\.[0-9.]+ to .*composer\/composer-2\.[0-9.]+\.phar/
    And STDOUT should contain:
      """
      {SUITE_CACHE_DIR}/composer/composer-
      """

    When I run `ls {SUITE_CACHE_DIR}/composer/composer-*.phar`
    Then save STDOUT as {COMPOSER_PHAR}
    And the {COMPOSER_PHAR} file should exist

    When I run `wp package update`
    Then STDERR should be empty
    And STDOUT should not contain:
      """
      Downloading Composer
      """

  Scenario: Install using an explicitly configured Composer Phar
    When I run `wp package path`
    Then save STDOUT as {PACKAGE_PATH}

    Given an empty directory
    And an empty cache
    And a local-package/composer.json file:
      """
      {"name":"local/binary-test","version":"1.0.0"}
      """

    When I run `wp package install ./local-package`
    And I run `ls {SUITE_CACHE_DIR}/composer/composer-*.phar`
    Then save STDOUT as {COMPOSER_PHAR}

    When I run `wp package uninstall local/binary-test`
    And I run `WP_CLI_COMPOSER_BINARY={COMPOSER_PHAR} wp package install ./local-package`
    Then STDERR should be empty
    And STDOUT should contain:
      """
      Success: Package installed.
      """
    And STDOUT should not contain:
      """
      Downloading Composer
      """

    When I run `WP_CLI_COMPOSER_BINARY={RUN_DIR}/missing wp package list --skip-update-check`
    Then STDERR should be empty
    And STDOUT should contain:
      """
      local/binary-test
      """
