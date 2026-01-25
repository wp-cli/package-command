<?php

use WP_CLI\Tests\TestCase;

class PackageAuthTest extends TestCase {

	private $env_vars_to_restore = [];

	public function set_up() {
		parent::set_up();

		// Save current environment variables
		$this->env_vars_to_restore = [
			'COMPOSER_AUTH'             => getenv( 'COMPOSER_AUTH' ),
			'GITHUB_TOKEN'              => getenv( 'GITHUB_TOKEN' ),
			'GITLAB_OAUTH_TOKEN'        => getenv( 'GITLAB_OAUTH_TOKEN' ),
			'GITLAB_TOKEN'              => getenv( 'GITLAB_TOKEN' ),
			'BITBUCKET_CONSUMER_KEY'    => getenv( 'BITBUCKET_CONSUMER_KEY' ),
			'BITBUCKET_CONSUMER_SECRET' => getenv( 'BITBUCKET_CONSUMER_SECRET' ),
			'HTTP_BASIC_AUTH'           => getenv( 'HTTP_BASIC_AUTH' ),
		];

		// Clear all auth-related environment variables
		foreach ( array_keys( $this->env_vars_to_restore ) as $var ) {
			putenv( $var );
		}
	}

	public function tear_down() {
		// Restore environment variables
		foreach ( $this->env_vars_to_restore as $var => $value ) {
			if ( false !== $value ) {
				putenv( "$var=$value" );
			} else {
				putenv( $var );
			}
		}

		parent::tear_down();
	}

	/**
	 * Helper method to invoke the set_composer_auth_env_var method.
	 */
	private function invoke_set_composer_auth() {
		$package = new Package_Command();
		$method  = new \ReflectionMethod( 'Package_Command', 'set_composer_auth_env_var' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$method->invoke( $package );
	}

	/**
	 * Test that GITHUB_TOKEN is added to COMPOSER_AUTH.
	 */
	public function test_github_token_added_to_composer_auth() {
		putenv( 'GITHUB_TOKEN=ghp_test123456789' );

		$this->invoke_set_composer_auth();

		$composer_auth = getenv( 'COMPOSER_AUTH' );
		$this->assertNotFalse( $composer_auth );

		$auth_array = json_decode( $composer_auth, true );
		$this->assertIsArray( $auth_array );
		$this->assertArrayHasKey( 'github-oauth', $auth_array );
		$this->assertArrayHasKey( 'github.com', $auth_array['github-oauth'] );
		$this->assertSame( 'ghp_test123456789', $auth_array['github-oauth']['github.com'] );
	}

	/**
	 * Test that GITLAB_OAUTH_TOKEN is added to COMPOSER_AUTH.
	 */
	public function test_gitlab_oauth_token_added_to_composer_auth() {
		putenv( 'GITLAB_OAUTH_TOKEN=glpat_test123456789' );

		$this->invoke_set_composer_auth();

		$composer_auth = getenv( 'COMPOSER_AUTH' );
		$this->assertNotFalse( $composer_auth );

		$auth_array = json_decode( $composer_auth, true );
		$this->assertIsArray( $auth_array );
		$this->assertArrayHasKey( 'gitlab-oauth', $auth_array );
		$this->assertArrayHasKey( 'gitlab.com', $auth_array['gitlab-oauth'] );
		$this->assertSame( 'glpat_test123456789', $auth_array['gitlab-oauth']['gitlab.com'] );
	}

	/**
	 * Test that GITLAB_TOKEN is added to COMPOSER_AUTH.
	 */
	public function test_gitlab_token_added_to_composer_auth() {
		putenv( 'GITLAB_TOKEN=glpat_test123456789' );

		$this->invoke_set_composer_auth();

		$composer_auth = getenv( 'COMPOSER_AUTH' );
		$this->assertNotFalse( $composer_auth );

		$auth_array = json_decode( $composer_auth, true );
		$this->assertIsArray( $auth_array );
		$this->assertArrayHasKey( 'gitlab-token', $auth_array );
		$this->assertArrayHasKey( 'gitlab.com', $auth_array['gitlab-token'] );
		$this->assertSame( 'glpat_test123456789', $auth_array['gitlab-token']['gitlab.com'] );
	}

	/**
	 * Test that BITBUCKET_CONSUMER_KEY and BITBUCKET_CONSUMER_SECRET are added to COMPOSER_AUTH.
	 */
	public function test_bitbucket_oauth_added_to_composer_auth() {
		putenv( 'BITBUCKET_CONSUMER_KEY=test_key' );
		putenv( 'BITBUCKET_CONSUMER_SECRET=test_secret' );

		$this->invoke_set_composer_auth();

		$composer_auth = getenv( 'COMPOSER_AUTH' );
		$this->assertNotFalse( $composer_auth );

		$auth_array = json_decode( $composer_auth, true );
		$this->assertIsArray( $auth_array );
		$this->assertArrayHasKey( 'bitbucket-oauth', $auth_array );
		$this->assertArrayHasKey( 'bitbucket.org', $auth_array['bitbucket-oauth'] );
		$this->assertArrayHasKey( 'consumer-key', $auth_array['bitbucket-oauth']['bitbucket.org'] );
		$this->assertArrayHasKey( 'consumer-secret', $auth_array['bitbucket-oauth']['bitbucket.org'] );
		$this->assertSame( 'test_key', $auth_array['bitbucket-oauth']['bitbucket.org']['consumer-key'] );
		$this->assertSame( 'test_secret', $auth_array['bitbucket-oauth']['bitbucket.org']['consumer-secret'] );
	}

	/**
	 * Test that Bitbucket OAuth is not added if only one credential is provided.
	 */
	public function test_bitbucket_oauth_requires_both_credentials() {
		putenv( 'BITBUCKET_CONSUMER_KEY=test_key' );
		// BITBUCKET_CONSUMER_SECRET is not set

		$this->invoke_set_composer_auth();

		$composer_auth = getenv( 'COMPOSER_AUTH' );
		// No auth should be set because only one credential was provided
		$this->assertFalse( $composer_auth );
	}

	/**
	 * Test that HTTP_BASIC_AUTH is added to COMPOSER_AUTH.
	 */
	public function test_http_basic_auth_added_to_composer_auth() {
		$http_basic = json_encode(
			[
				'repo.example.com' => [
					'username' => 'user',
					'password' => 'pass',
				],
			]
		);
		putenv( "HTTP_BASIC_AUTH=$http_basic" );

		$this->invoke_set_composer_auth();

		$composer_auth = getenv( 'COMPOSER_AUTH' );
		$this->assertNotFalse( $composer_auth );

		$auth_array = json_decode( $composer_auth, true );
		$this->assertIsArray( $auth_array );
		$this->assertArrayHasKey( 'http-basic', $auth_array );
		$this->assertArrayHasKey( 'repo.example.com', $auth_array['http-basic'] );
		$this->assertArrayHasKey( 'username', $auth_array['http-basic']['repo.example.com'] );
		$this->assertArrayHasKey( 'password', $auth_array['http-basic']['repo.example.com'] );
		$this->assertSame( 'user', $auth_array['http-basic']['repo.example.com']['username'] );
		$this->assertSame( 'pass', $auth_array['http-basic']['repo.example.com']['password'] );
	}

	/**
	 * Test that invalid HTTP_BASIC_AUTH JSON is ignored.
	 */
	public function test_invalid_http_basic_auth_json_ignored() {
		putenv( 'HTTP_BASIC_AUTH=not-valid-json' );

		$this->invoke_set_composer_auth();

		$composer_auth = getenv( 'COMPOSER_AUTH' );
		// No auth should be set because the JSON was invalid
		$this->assertFalse( $composer_auth );
	}

	/**
	 * Test that multiple auth providers can be used together.
	 */
	public function test_multiple_auth_providers() {
		putenv( 'GITHUB_TOKEN=ghp_test123' );
		putenv( 'GITLAB_TOKEN=glpat_test456' );

		$this->invoke_set_composer_auth();

		$composer_auth = getenv( 'COMPOSER_AUTH' );
		$this->assertNotFalse( $composer_auth );

		$auth_array = json_decode( $composer_auth, true );
		$this->assertIsArray( $auth_array );
		$this->assertArrayHasKey( 'github-oauth', $auth_array );
		$this->assertArrayHasKey( 'gitlab-token', $auth_array );
		$this->assertSame( 'ghp_test123', $auth_array['github-oauth']['github.com'] );
		$this->assertSame( 'glpat_test456', $auth_array['gitlab-token']['gitlab.com'] );
	}

	/**
	 * Test that existing COMPOSER_AUTH is preserved.
	 */
	public function test_existing_composer_auth_preserved() {
		$existing_auth = json_encode(
			[
				'github-oauth' => [
					'github.com' => 'existing_token',
				],
			]
		);
		putenv( "COMPOSER_AUTH=$existing_auth" );
		putenv( 'GITLAB_TOKEN=glpat_new_token' );

		$this->invoke_set_composer_auth();

		$composer_auth = getenv( 'COMPOSER_AUTH' );
		$this->assertNotFalse( $composer_auth );

		$auth_array = json_decode( $composer_auth, true );
		$this->assertIsArray( $auth_array );
		// Existing github-oauth should be preserved
		$this->assertArrayHasKey( 'github-oauth', $auth_array );
		$this->assertSame( 'existing_token', $auth_array['github-oauth']['github.com'] );
		// New gitlab-token should be added
		$this->assertArrayHasKey( 'gitlab-token', $auth_array );
		$this->assertSame( 'glpat_new_token', $auth_array['gitlab-token']['gitlab.com'] );
	}

	/**
	 * Test that environment variable tokens don't override existing COMPOSER_AUTH values.
	 */
	public function test_env_tokens_dont_override_composer_auth() {
		$existing_auth = json_encode(
			[
				'github-oauth' => [
					'github.com' => 'existing_token',
				],
			]
		);
		putenv( "COMPOSER_AUTH=$existing_auth" );
		putenv( 'GITHUB_TOKEN=new_token' );

		$this->invoke_set_composer_auth();

		$composer_auth = getenv( 'COMPOSER_AUTH' );
		$this->assertNotFalse( $composer_auth );

		$auth_array = json_decode( $composer_auth, true );
		$this->assertIsArray( $auth_array );
		// Existing github-oauth should be preserved, not overridden by GITHUB_TOKEN
		$this->assertArrayHasKey( 'github-oauth', $auth_array );
		$this->assertSame( 'existing_token', $auth_array['github-oauth']['github.com'] );
	}
}
