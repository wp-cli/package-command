<?php

namespace WP_CLI\Package;

use RuntimeException;
use WP_CLI\Utils;

/**
 * Reads the legacy Composer repository without loading Composer.
 */
class PackageIndex {

	private $insecure;

	public function __construct( $insecure = false ) {
		$this->insecure = $insecure;
	}

	/**
	 * @return array Packages keyed by their original names.
	 */
	public function packages() {
		$base     = 'https://wp-cli.org/package-index/';
		$index    = $this->fetch( $base . 'packages.json' );
		$packages = self::parse( $index );
		foreach ( $index['includes'] ?? [] as $file => $metadata ) {
			$packages = array_replace( $packages, self::parse( $this->fetch( $base . $file ) ) );
		}
		return $packages;
	}

	private function fetch( $url ) {
		$response = Utils\http_request(
			'GET',
			$url,
			null,
			[],
			[
				'insecure'      => $this->insecure,
				'halt_on_error' => false,
			]
		);
		$data     = json_decode( $response->body, true );
		if ( 200 !== $response->status_code || ! is_array( $data ) ) {
			throw new RuntimeException( "Failed to read package index: {$url}" );
		}
		return $data;
	}

	/**
	 * @return array Packages in index order, with versions in index order.
	 */
	public static function parse( array $data ) {
		$packages = [];
		foreach ( $data['packages'] ?? [] as $name => $versions ) {
			$first             = reset( $versions );
			$packages[ $name ] = [
				'name'        => $first['name'] ?? $name,
				'description' => $first['description'] ?? '',
				'authors'     => implode( ', ', array_column( $first['authors'] ?? [], 'name' ) ),
				'versions'    => array_keys( $versions ),
			];
		}
		return $packages;
	}
}
