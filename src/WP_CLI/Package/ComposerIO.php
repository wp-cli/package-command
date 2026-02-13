<?php
/**
 * A Composer IO class so we can provide some level of interactivity from WP-CLI.
 *
 * Due to PHP 5.6 compatibility, we have two different implementations of this class.
 * This is implemented via traits to make static analysis easier.
 *
 * See https://github.com/wp-cli/package-command/issues/172.
 */

namespace WP_CLI\Package;

use Composer\IO\NullIO;
use WP_CLI;

class ComposerIO extends NullIO {
	/**
	 * {@inheritDoc}
	 */
	public function isVerbose(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function write( $messages, bool $newline = true, int $verbosity = self::NORMAL ): void {
		self::output_clean_message( $messages );
	}

	/**
	 * {@inheritDoc}
	 */
	public function writeError( $messages, bool $newline = true, int $verbosity = self::NORMAL ): void {
		self::output_clean_message( $messages );
	}

	private static function output_clean_message( $messages ) {
		$messages = (array) preg_replace( '#<(https?)([^>]+)>#', '$1$2', $messages );
		foreach ( $messages as $message ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			WP_CLI::log( strip_tags( trim( $message ) ) );
		}
	}
}
