Feature: Composer authentication for various git providers

  Scenario: GitHub OAuth token is set in COMPOSER_AUTH
    Given an empty directory
    When I run `GITHUB_TOKEN=ghp_test123456789 wp package path`
    Then STDOUT should not be empty
    And the return code should be 0

  Scenario: GitLab OAuth token is set in COMPOSER_AUTH
    Given an empty directory
    When I run `GITLAB_OAUTH_TOKEN=glpat_test123456789 wp package path`
    Then STDOUT should not be empty
    And the return code should be 0

  Scenario: GitLab personal access token is set in COMPOSER_AUTH
    Given an empty directory
    When I run `GITLAB_TOKEN=glpat_test123456789 wp package path`
    Then STDOUT should not be empty
    And the return code should be 0

  Scenario: Bitbucket OAuth consumer is set in COMPOSER_AUTH
    Given an empty directory
    When I run `BITBUCKET_CONSUMER_KEY=test_key BITBUCKET_CONSUMER_SECRET=test_secret wp package path`
    Then STDOUT should not be empty
    And the return code should be 0

  Scenario: HTTP Basic Auth is set in COMPOSER_AUTH
    Given an empty directory
    When I run `HTTP_BASIC_AUTH='{"repo.example.com":{"username":"user","password":"pass"}}' wp package path`
    Then STDOUT should not be empty
    And the return code should be 0

  Scenario: Multiple auth providers can be used together
    Given an empty directory
    When I run `GITHUB_TOKEN=ghp_test123 GITLAB_TOKEN=glpat_test456 wp package path`
    Then STDOUT should not be empty
    And the return code should be 0

  Scenario: Invalid HTTP_BASIC_AUTH JSON is ignored
    Given an empty directory
    When I run `HTTP_BASIC_AUTH='not-valid-json' wp package path`
    Then STDOUT should not be empty
    And the return code should be 0
