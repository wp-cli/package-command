<?php

namespace WP_CLI\Package;

use RuntimeException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Downloads and runs Composer without loading its dependencies into WP-CLI.
 */
class ComposerPhar {

	const VERSIONS_URL = 'https://getcomposer.org/versions';
	const VERSIONS_TTL = 86400;

	private $insecure;
	private $path;

	public function __construct( $insecure = false ) {
		$this->insecure = $insecure;
	}

	/**
	 * @return string Path to the configured binary or verified, cached Phar.
	 */
	public function locate( $quiet = false ) {
		$binary = getenv( 'WP_CLI_COMPOSER_BINARY' );
		if ( false !== $binary ) {
			if ( ! is_file( $binary ) || ! is_readable( $binary ) ) {
				throw new RuntimeException( "WP_CLI_COMPOSER_BINARY is not readable: {$binary}" );
			}
			return realpath( $binary );
		}
		if ( null !== $this->path ) {
			return $this->path;
		}

		$cache   = WP_CLI::get_cache();
		$version = null;
		foreach ( $this->versions( $cache )['stable'] ?? [] as $release ) {
			if ( preg_match( '/^2\.\d+\.\d+$/D', $release['version'] ) && $release['min-php'] <= PHP_VERSION_ID ) {
				$version = $release['version'];
				break;
			}
		}
		if ( null === $version ) {
			// Offline or getcomposer.org unreachable: reuse the newest Composer already in the cache.
			$cached = self::cached_versions( $cache );
			if ( $cached ) {
				$this->path = $cache->has( "composer/composer-{$cached[0]}.phar" );
				WP_CLI::debug( "Using cached Composer {$cached[0]}; version list unavailable.", 'packages' );
				return $this->path;
			}
			$version = 'latest-stable';
		}

		$key  = "composer/composer-{$version}.phar";
		$path = $cache->has( $key );
		if ( $path ) {
			$this->path = $path;
			return $path;
		}

		$temp_dir = Utils\get_temp_dir() . uniqid( 'wp-cli-composer-', true );
		if ( ! mkdir( $temp_dir, 0700 ) ) {
			throw new RuntimeException( 'Could not create Composer download directory.' );
		}
		$temp = $temp_dir . '/composer.phar';
		register_shutdown_function(
			static function () use ( $temp, $temp_dir ) {
				if ( file_exists( $temp ) ) {
					unlink( $temp );
				}
				rmdir( $temp_dir );
			}
		);
		$path = $cache->is_enabled() ? $cache->get_root() . $key : $temp;
		if ( ! $quiet ) {
			WP_CLI::log( "Downloading Composer {$version} to {$path}..." );
		}
		$url = "https://getcomposer.org/download/{$version}/composer.phar";
		$this->request( $url, [ 'filename' => $temp ] );
		$checksum = trim( $this->request( $url . '.sha256sum' )->body );
		if ( ! preg_match( '/^([a-f0-9]{64})(?:\s|$)/i', $checksum, $matches ) || ! hash_equals( strtolower( $matches[1] ), hash_file( 'sha256', $temp ) ) ) {
			throw new RuntimeException( 'Composer download failed SHA-256 verification.' );
		}
		chmod( $temp, 0755 );
		if ( $cache->is_enabled() ) {
			if ( ! $cache->import( $key, $temp ) ) {
				throw new RuntimeException( 'Could not cache Composer.' );
			}
			chmod( $path, 0755 );
		}
		$this->path = $path;
		return $path;
	}

	/**
	 * The release list from getcomposer.org. A copy lives in the cache for a day, so a run of package
	 * commands costs one request; when the network is down, a stale copy still names the version to use.
	 *
	 * @return array Decoded list, empty when neither the network nor the cache has one.
	 */
	private function versions( $cache ) {
		$key = 'composer/versions.json';
		if ( $cache->is_enabled() ) {
			$fresh = $cache->read( $key, self::VERSIONS_TTL );
			if ( false !== $fresh && is_array( json_decode( $fresh, true ) ) ) {
				return json_decode( $fresh, true );
			}
		}
		try {
			$body     = $this->request( self::VERSIONS_URL )->body;
			$versions = json_decode( $body, true );
			if ( ! is_array( $versions ) ) {
				throw new RuntimeException( 'Composer version list is not valid JSON.' );
			}
			if ( $cache->is_enabled() ) {
				$cache->write( $key, $body );
			}
			return $versions;
		} catch ( \Exception $e ) {
			WP_CLI::debug( $e->getMessage(), 'packages' );
		}
		if ( $cache->is_enabled() ) {
			$stale = $cache->read( $key );
			if ( false !== $stale && is_array( json_decode( $stale, true ) ) ) {
				WP_CLI::debug( 'Using the cached Composer version list; getcomposer.org is unreachable.', 'packages' );
				return json_decode( $stale, true );
			}
		}
		return [];
	}

	/**
	 * Composer versions present in the cache, newest first.
	 *
	 * @return string[]
	 */
	private static function cached_versions( $cache ) {
		if ( ! $cache->is_enabled() ) {
			return [];
		}
		$versions = [];
		foreach ( glob( $cache->get_root() . 'composer/composer-*.phar' ) ?: [] as $file ) {
			if ( preg_match( '/composer-(\d+\.\d+\.\d+)\.phar$/', $file, $matches ) ) {
				$versions[] = $matches[1];
			}
		}
		usort( $versions, 'version_compare' );
		return array_reverse( $versions );
	}

	private function request( $url, $options = [] ) {
		$response = Utils\http_request(
			'GET',
			$url,
			null,
			[],
			array_merge(
				[
					'timeout'       => 600,
					'insecure'      => $this->insecure,
					'halt_on_error' => false,
				],
				$options
			)
		);
		if ( $response->status_code < 200 || $response->status_code >= 300 ) {
			throw new RuntimeException( "Could not download {$url} (HTTP code {$response->status_code})." );
		}
		return $response;
	}

	/**
	 * Builds a shell command, keeping each Composer argument separate.
	 *
	 * @return string
	 */
	public function command( array $args, $working_dir, $quiet = false ) {
		$binary = $this->locate( $quiet );
		$prefix = '';
		if ( false === getenv( 'WP_CLI_COMPOSER_BINARY' ) || 'phar' === strtolower( pathinfo( $binary, PATHINFO_EXTENSION ) ) ) {
			$prefix   = Utils\esc_cmd( '%s', WP_CLI::get_php_binary() ) . ' ';
			$php_args = getenv( 'WP_CLI_PHP_ARGS' );
			if ( false !== $php_args && '' !== $php_args ) {
				$prefix .= $php_args . ' ';
			}
		}
		$args[] = '--working-dir=' . $working_dir;
		$args[] = '--no-interaction';
		$args[] = '--no-ansi';
		// Progress is an install/update option, not a global Composer option.
		if ( in_array( $args[0], [ 'install', 'update', 'remove', 'require' ], true ) ) {
			$args[] = '--no-progress';
		}
		if ( $quiet ) {
			$args[] = '--quiet';
		}
		return $prefix . Utils\esc_cmd( '%s', $binary ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) );
	}

	/**
	 * @return int Composer's exit code.
	 */
	public function run( array $args, string $working_dir, bool $quiet = false ) {
		list( $code ) = $this->execute( $args, $working_dir, $quiet, false );
		return $code;
	}

	/**
	 * @return array Decoded JSON, or an exception when Composer fails.
	 */
	public function run_json( array $args, string $working_dir ) {
		list( $code, $stdout ) = $this->execute( $args, $working_dir, false, true );
		$json                  = json_decode( $stdout, true );
		if ( 0 !== $code || ! is_array( $json ) ) {
			throw new RuntimeException( "Failed to check package updates (Composer return code {$code}): invalid or unsuccessful JSON response." );
		}
		return $json;
	}

	/**
	 * Runs Composer and relays its output. Composer writes its progress to stderr; when streaming, both
	 * streams go through WP_CLI::log() so nothing reaches WP-CLI's own stderr. Reading two pipes can
	 * deadlock (non-blocking pipes are unsupported on Windows), so stderr is merged into stdout when
	 * streaming and parked in a file when stdout is captured as JSON.
	 *
	 * @return array{0:int,1:string} Exit code and captured stdout (empty unless $capture).
	 */
	private function execute( array $args, $working_dir, $quiet, $capture ) {
		$command                        = $this->command( $args, $working_dir, $quiet );
		$env                            = getenv();
		$env['COMPOSER_NO_INTERACTION'] = '1';
		$stderr_file                    = $capture ? tempnam( Utils\get_temp_dir(), 'wp-cli-composer-' ) : null;
		if ( ! $capture ) {
			$command .= ' 2>&1'; // The shell merges the streams; only one pipe is read below.
		}
		$descriptors = [
			[ 'pipe', 'r' ],
			[ 'pipe', 'w' ],
			$capture ? [ 'file', $stderr_file, 'w' ] : [ 'pipe', 'w' ],
		];
		$process     = Utils\proc_open_compat( $command, $descriptors, $pipes, $working_dir, $env );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Could not start Composer.' );
		}
		fclose( $pipes[0] );
		$stdout = '';
		$buffer = '';
		while ( ! feof( $pipes[1] ) ) {
			$chunk = fread( $pipes[1], 8192 );
			if ( false === $chunk || '' === $chunk ) {
				continue;
			}
			if ( $capture ) {
				$stdout .= $chunk;
				continue;
			}
			$buffer .= $chunk;
			while ( preg_match( '/^(.*?)[\r\n]+/s', $buffer, $matches ) ) {
				$this->output( $matches[1], $quiet, false );
				$buffer = substr( $buffer, strlen( $matches[0] ) );
			}
		}
		$this->output( $buffer, $quiet, false );
		fclose( $pipes[1] );
		if ( isset( $pipes[2] ) ) {
			fclose( $pipes[2] );
		}
		$code = proc_close( $process );
		if ( null !== $stderr_file ) {
			foreach ( preg_split( '/[\r\n]+/', (string) file_get_contents( $stderr_file ) ) as $line ) {
				$this->output( $line, $quiet, true );
			}
			unlink( $stderr_file );
		}
		return [ $code, $stdout ];
	}

	private function output( $line, $quiet, $debug ) {
		$line = rtrim( preg_replace( '/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $line ) );
		if ( $quiet || '' === $line ) {
			return;
		}
		if ( $debug ) {
			WP_CLI::debug( $line, 'packages' );
		} else {
			WP_CLI::log( $line );
		}
	}
}
