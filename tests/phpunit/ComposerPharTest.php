<?php

use WP_CLI\Package\ComposerPhar;
use WP_CLI\Tests\TestCase;
use WP_CLI\Utils;

require_once VENDOR_DIR . '/wp-cli/wp-cli/php/utils.php';
require_once VENDOR_DIR . '/wp-cli/wp-cli/php/class-wp-cli.php';

class ComposerPharTest extends TestCase {

	private $environment;
	private $directory;

	public function set_up() {
		parent::set_up();
		$this->environment = [];
		foreach ( [ 'WP_CLI_COMPOSER_BINARY', 'WP_CLI_PHP_ARGS', 'COMPOSER_AUTH' ] as $name ) {
			$this->environment[ $name ] = getenv( $name );
		}
		$this->directory = sys_get_temp_dir() . '/' . uniqid( 'composer-test-', true );
		mkdir( $this->directory );
		file_put_contents( $this->directory . '/composer.phar', '<?php echo json_encode($argv);' );
		file_put_contents( $this->directory . '/composer', '' );
	}

	public function tear_down() {
		foreach ( $this->environment as $name => $value ) {
			putenv( false === $value ? $name : $name . '=' . $value );
		}
		unlink( $this->directory . '/composer.phar' );
		unlink( $this->directory . '/composer' );
		rmdir( $this->directory );
		parent::tear_down();
	}

	public function test_phar_command_and_php_arguments() {
		$binary = $this->directory . '/composer.phar';
		putenv( 'WP_CLI_COMPOSER_BINARY=' . $binary );
		putenv( 'WP_CLI_PHP_ARGS=-d memory_limit=123M' );
		// The override is resolved with realpath(), e.g. /var to /private/var on macOS, or 8.3 short names on Windows.
		$resolved = (string) realpath( $binary );
		$composer = new ComposerPhar();
		$command  = $composer->command( [ 'update', 'vendor/package:^1.0' ], $this->directory . '/with spaces', true );
		$this->assertSame(
			Utils\esc_cmd( '%s', WP_CLI::get_php_binary() ) . ' -d memory_limit=123M ' . Utils\esc_cmd( '%s', $resolved ) . ' ' . implode( ' ', array_map( 'escapeshellarg', [ 'update', 'vendor/package:^1.0', '--working-dir=' . $this->directory . '/with spaces', '--no-interaction', '--no-ansi', '--no-progress', '--quiet' ] ) ),
			$command
		);
		$argv = $composer->run_json( [ 'outdated', '--format=json' ], $this->directory );
		$this->assertSame( [ $resolved, 'outdated', '--format=json', '--working-dir=' . $this->directory, '--no-interaction', '--no-ansi' ], $argv );
	}

	public function test_executable_does_not_use_php_arguments() {
		$binary = $this->directory . '/composer';
		putenv( 'WP_CLI_COMPOSER_BINARY=' . $binary );
		putenv( 'WP_CLI_PHP_ARGS=-d memory_limit=123M' );
		$command = ( new ComposerPhar() )->command( [ 'update' ], $this->directory );
		$this->assertSame( 0, strpos( $command, Utils\esc_cmd( '%s', (string) realpath( $binary ) ) . ' ' ) );
		$this->assertFalse( strpos( $command, 'memory_limit' ) );
	}

	public function test_unreadable_override() {
		putenv( 'WP_CLI_COMPOSER_BINARY=' . $this->directory . '/missing' );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'WP_CLI_COMPOSER_BINARY is not readable:' );
		( new ComposerPhar() )->locate();
	}

	public function test_child_environment_and_quiet_exit_status() {
		putenv( 'WP_CLI_COMPOSER_BINARY=' . $this->directory . '/composer.phar' );
		putenv( 'WP_CLI_PHP_ARGS' );
		putenv( 'COMPOSER_AUTH={"test":"inherited"}' );
		file_put_contents( $this->directory . '/composer.phar', '<?php echo json_encode([getenv("COMPOSER_AUTH"), getenv("COMPOSER_NO_INTERACTION")]);' );
		$this->assertSame( [ '{"test":"inherited"}', '1' ], ( new ComposerPhar() )->run_json( [ 'outdated' ], $this->directory ) );
		file_put_contents( $this->directory . '/composer.phar', '<?php for ($i = 0; $i < 10000; ++$i) { fwrite(STDOUT, "stdout\\n"); fwrite(STDERR, "stderr\\n"); } exit(7);' );
		$this->assertSame( 7, ( new ComposerPhar() )->run( [ 'update' ], $this->directory, true ) );
	}

	public function test_invalid_json() {
		putenv( 'WP_CLI_COMPOSER_BINARY=' . $this->directory . '/composer.phar' );
		file_put_contents( $this->directory . '/composer.phar', '<?php echo "invalid";' );
		$this->expectException( RuntimeException::class );
		( new ComposerPhar() )->run_json( [ 'outdated' ], $this->directory );
	}

	public function test_both_streams_are_logged_without_ansi_or_empty_lines() {
		putenv( 'WP_CLI_COMPOSER_BINARY=' . $this->directory . '/composer.phar' );
		putenv( 'WP_CLI_PHP_ARGS' );
		file_put_contents( $this->directory . '/composer.phar', '<?php fwrite(STDOUT, "\\033[32mstdout\\033[0m  \\n\\n"); fwrite(STDERR, "stderr  \\nlast line");' );
		$property = new ReflectionProperty( 'WP_CLI', 'logger' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$previous = $property->getValue();
		$logger   = new WP_CLI\Loggers\Execution();
		WP_CLI::set_logger( $logger );
		try {
			$this->assertSame( 0, ( new ComposerPhar() )->run( [ 'update' ], $this->directory ) );
			$lines = explode( "\n", $logger->stdout );
			sort( $lines );
			$this->assertSame( [ '', 'last line', 'stderr', 'stdout' ], $lines );
			$this->assertSame( '', $logger->stderr );
		} finally {
			WP_CLI::set_logger( $previous );
		}
	}

	public function test_nonzero_json_exit() {
		putenv( 'WP_CLI_COMPOSER_BINARY=' . $this->directory . '/composer.phar' );
		file_put_contents( $this->directory . '/composer.phar', '<?php echo "{}"; exit(2);' );
		$this->expectException( RuntimeException::class );
		( new ComposerPhar() )->run_json( [ 'outdated' ], $this->directory );
	}

	public function test_stale_version_list_falls_back_to_cached_phar() {
		putenv( 'WP_CLI_COMPOSER_BINARY' );
		$cache_dir = $this->directory . '/cache';
		$requests  = [];
		$composer  = $this->composer_phar(
			$cache_dir,
			static function ( $url ) use ( &$requests ) {
				$requests[] = $url;
				throw new RuntimeException( "Could not download {$url}." );
			}
		);
		mkdir( $cache_dir . '/composer', 0777, true );
		file_put_contents( $cache_dir . '/composer/versions.json', self::versions_json() );
		touch( $cache_dir . '/composer/versions.json', time() - 2 * ComposerPhar::VERSIONS_TTL );
		file_put_contents( $cache_dir . '/composer/composer-2.8.0.phar', '' );
		try {
			$this->assertSame( $cache_dir . '/composer/composer-2.8.0.phar', $composer->locate( true ) );
			$this->assertSame( [ ComposerPhar::VERSIONS_URL ], $requests );
		} finally {
			$this->remove_cache( $cache_dir );
		}
	}

	public function test_download_is_published_to_the_cache() {
		putenv( 'WP_CLI_COMPOSER_BINARY' );
		$cache_dir = $this->directory . '/cache';
		$phar      = '<?php echo "composer";';
		$composer  = $this->composer_phar(
			$cache_dir,
			static function ( $url, $options ) use ( $phar ) {
				if ( ComposerPhar::VERSIONS_URL === $url ) {
					return (object) [ 'body' => self::versions_json() ];
				}
				if ( isset( $options['filename'] ) ) {
					file_put_contents( $options['filename'], $phar );
					return (object) [ 'body' => '' ];
				}
				return (object) [ 'body' => hash( 'sha256', $phar ) . '  composer.phar' ];
			}
		);
		try {
			$path = $composer->locate( true );
			$this->assertSame( $cache_dir . '/composer/composer-2.9.0.phar', $path );
			$this->assertSame( $phar, file_get_contents( $path ) );
			$this->assertSame( [ $path, $cache_dir . '/composer/versions.json' ], glob( $cache_dir . '/composer/*' ) );
		} finally {
			$this->remove_cache( $cache_dir );
		}
	}

	private static function versions_json() {
		return json_encode(
			[
				'stable' => [
					[
						'version' => '2.9.0',
						'min-php' => 70205,
					],
				],
			]
		);
	}

	private function composer_phar( $cache_dir, callable $request ) {
		return new class( new WP_CLI\FileCache( $cache_dir, 15552000, 314572800 ), $request ) extends ComposerPhar {
			private $file_cache;
			private $request;

			public function __construct( $file_cache, $request ) {
				parent::__construct();
				$this->file_cache = $file_cache;
				$this->request    = $request;
			}

			protected function cache() {
				return $this->file_cache;
			}

			protected function request( $url, $options = [] ) {
				return ( $this->request )( $url, $options );
			}
		};
	}

	private function remove_cache( $cache_dir ) {
		array_map( 'unlink', glob( $cache_dir . '/composer/*' ) ?: [] );
		rmdir( $cache_dir . '/composer' );
		rmdir( $cache_dir );
	}
}
