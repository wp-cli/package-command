Feature: Manage WP-CLI packages

  Scenario: Package CRUD
    Given an empty directory

    When I run `wp package browse`
    Then STDOUT should contain:
      """
      danielbachhuber/wp-cli-reset-post-date-command
      """

    When I run `wp package install danielbachhuber/wp-cli-reset-post-date-command`
    Then STDERR should be empty

    When I run `wp help reset-post-date`
    Then STDERR should be empty

    When I try `wp --skip-packages --debug help reset-post-date`
    Then STDERR should contain:
      """
      Debug (bootstrap): Skipped loading packages.
      """
    And STDERR should contain:
      """
      Warning: No WordPress install found.
      """

    When I run `wp package list`
    Then STDOUT should contain:
      """
      danielbachhuber/wp-cli-reset-post-date-command
      """

    When I run `wp package uninstall danielbachhuber/wp-cli-reset-post-date-command`
    Then STDERR should be empty

    When I run `wp package list`
    Then STDOUT should not contain:
      """
      danielbachhuber/wp-cli-reset-post-date-command
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

  Scenario: Run package commands without hitting Composer plugin errors in Phar files
    Given an empty directory
    And a new Phar with the same version

    When I run `{PHAR_PATH} package list`
    Then STDERR should not contain:
      """
      failed to open stream
      """
    And STDERR should not contain:
      """
      is not a file in phar
      """

  Scenario: Revert the WP-CLI packages composer.json when fail to install/uninstall a package due to memory limit
    Given an empty directory
    #When I try `{INVOKE_WP_CLI_WITH_PHP_ARGS--dmemory_limit=32M} package install danielbachhuber/wp-cli-reset-post-date-command`
    When I try `WP_CLI_PHP_ARGS='-dmemory_limit=32M' {SRC_DIR}/vendor/bin/wp package install danielbachhuber/wp-cli-reset-post-date-command`
    Then the return code should not be 0
    And STDERR should contain:
      """
      Reverted composer.json.
      """

    When I run `wp package install danielbachhuber/wp-cli-reset-post-date-command`
    Then STDOUT should contain:
      """
      Success: Package installed.
      """

    #When I try `{INVOKE_WP_CLI_WITH_PHP_ARGS--dmemory_limit=32M} package uninstall danielbachhuber/wp-cli-reset-post-date-command`
    When I try `WP_CLI_PHP_ARGS='-dmemory_limit=32M' {SRC_DIR}/vendor/bin/wp package uninstall danielbachhuber/wp-cli-reset-post-date-command`
    Then the return code should not be 0
    And STDERR should contain:
      """
      Reverted composer.json.
      """

    # Create a default composer.json first to compare.
    When I run `WP_CLI_PACKAGES_DIR={RUN_DIR}/mypackages wp package list`
	Then the {RUN_DIR}/mypackages/composer.json file should exist
    And save the {RUN_DIR}/mypackages/composer.json file as {MYPACKAGES_COMPOSER_JSON}

    #When I try `WP_CLI_PACKAGES_DIR={RUN_DIR}/mypackages {INVOKE_WP_CLI_WITH_PHP_ARGS--dmemory_limit=32M} package install danielbachhuber/wp-cli-reset-post-date-command`
    When I try `WP_CLI_PACKAGES_DIR={RUN_DIR}/mypackages WP_CLI_PHP_ARGS='-dmemory_limit=32M' {SRC_DIR}/vendor/bin/wp package install danielbachhuber/wp-cli-reset-post-date-command`
    Then the return code should not be 0
    And STDERR should contain:
      """
      Reverted composer.json.
      """
    And the mypackages/composer.json file should be:
      """
      {MYPACKAGES_COMPOSER_JSON}
      """

  Scenario: Try to run with a bad WP_CLI_PACKAGES_DIR/composer.json
    Given an empty directory
    And a packages-bad-json/composer.json file:
      """
      {
        "name": "wp-cli/wp-cli",
      }
      """
    And a packages-no-such-package/composer.json file:
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

    When I try `WP_CLI_PACKAGES_DIR={RUN_DIR}/packages-bad-json wp package install danielbachhuber/wp-cli-reset-post-date-command`
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
    And the packages-no-such-package/composer.json file should be:
      """
      {NO_SUCH_PACKAGE_COMPOSER_JSON}
      """

    When I try `WP_CLI_PACKAGES_DIR={RUN_DIR}/packages-no-such-package wp package install danielbachhuber/wp-cli-reset-post-date-command`
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Package installation failed.
      """
    And STDERR should contain:
      """
      Repository not found
      """
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
      Error: Failed to update packages.
      """
    And STDERR should contain:
      """
      Repository not found
      """
    And STDERR should not contain:
      """
      Reverted composer.json.
      """
    And STDOUT should not be empty
    And the packages-no-such-package/composer.json file should be:
      """
      {NO_SUCH_PACKAGE_COMPOSER_JSON}
      """
