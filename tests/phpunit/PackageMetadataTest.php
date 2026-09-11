<?php

use WP_CLI\Package\InstalledPackages;
use WP_CLI\Package\PackageIndex;
use WP_CLI\Tests\TestCase;

class PackageMetadataTest extends TestCase {

	public function test_package_index() {
		$packages = PackageIndex::parse( json_decode( file_get_contents( __DIR__ . '/fixtures/package-index.json' ), true ) );
		$this->assertSame( [ 'Vendor/Command', 'vendor/other' ], array_keys( $packages ) );
		$this->assertSame( [ 'v1.0.0', 'dev-main' ], $packages['Vendor/Command']['versions'] );
		$this->assertSame( 'First Author, Second Author', $packages['Vendor/Command']['authors'] );
		$this->assertSame( 'A command', $packages['Vendor/Command']['description'] );
		$this->assertSame( '', $packages['vendor/other']['authors'] );
		$this->assertSame( [], PackageIndex::parse( [ 'includes' => [ 'include.json' => [] ] ] ) );
	}

	public function test_installed_json_shapes() {
		$path    = tempnam( sys_get_temp_dir(), 'installed-' );
		$package = [
			'name'    => 'vendor/command',
			'version' => 'dev-main',
			'source'  => [ 'reference' => 'abcdef123456789' ],
			'authors' => [ [ 'name' => 'Author' ] ],
		];
		try {
			file_put_contents( $path, json_encode( [ $package ] ) );
			$composer_one = InstalledPackages::read( $path );
			file_put_contents(
				$path,
				json_encode(
					[
						'packages' => [ $package ],
						'dev'      => true,
					]
				)
			);
			$this->assertSame( $composer_one, InstalledPackages::read( $path ) );
			$this->assertSame( 'dev-main abcdef123456789', $composer_one['vendor/command']['full_version'] );
			$this->assertSame( 'Author', $composer_one['vendor/command']['authors'] );
			$package['version'] = 'v1.0.0';
			file_put_contents( $path, json_encode( [ $package ] ) );
			$this->assertSame( 'v1.0.0', InstalledPackages::read( $path )['vendor/command']['full_version'] );
		} finally {
			unlink( $path );
		}
		$this->assertSame( [], InstalledPackages::read( $path ) );
	}

	public function test_package_names() {
		foreach ( [ 'vendor/package', 'Vendor/Package', 'vendor/foo--bar', 'vendor/foo_bar.baz' ] as $name ) {
			$this->assertNull( InstalledPackages::has_naming_error( $name ), $name );
		}
		foreach ( [ '..', '../outside', 'vendor/../outside', '/absolute', 'vendor/foo---bar', "vendor/package\n" ] as $name ) {
			$this->assertNotNull( InstalledPackages::has_naming_error( $name ), $name );
		}
	}

	public function test_outdated_mapping() {
		$package = [
			'name'    => 'Vendor/Command',
			'version' => 'v1.0.0',
		];
		$updates = [
			'installed' => [
				[
					'name'   => 'vendor/other',
					'latest' => 'v5.0.0',
				],
				[
					'name'   => 'vendor/command',
					'latest' => 'v2.0.0',
				],
			],
		];
		$updated = InstalledPackages::with_update( $package, $updates );
		$this->assertSame( 'available', $updated['update'] );
		$this->assertSame( 'v2.0.0', $updated['update_version'] );
		$package['version'] = 'v2.0.0';
		$this->assertSame( 'none', InstalledPackages::with_update( $package, $updates )['update'] );
		$this->assertSame( '', InstalledPackages::with_update( $package, [] )['update_version'] );
		$this->assertSame( 'error', InstalledPackages::with_update( $package, null )['update'] );
		$this->assertSame( 'error', InstalledPackages::with_update( $package, null )['update_version'] );
	}
}
