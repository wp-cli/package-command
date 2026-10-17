<?php

namespace WP_CLI\Package;

use RuntimeException;

/**
 * Reads Composer's installed metadata and update reports.
 */
class InstalledPackages {

	public static function read( $path ) {
		if ( ! is_file( $path ) ) {
			return [];
		}
		$data = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( "Parse error in {$path}: " . json_last_error_msg() );
		}
		$packages = [];
		foreach ( $data['packages'] ?? $data as $package ) {
			$name              = $package['name'];
			$version           = $package['version'];
			$packages[ $name ] = [
				'name'             => $name,
				'description'      => $package['description'] ?? '',
				'authors'          => implode( ', ', array_column( $package['authors'] ?? [], 'name' ) ),
				'version'          => $version,
				'full_version'     => $version . ( 0 === strpos( $version, 'dev-' ) && isset( $package['source']['reference'] ) ? ' ' . $package['source']['reference'] : '' ),
				'source_reference' => $package['source']['reference'] ?? '',
			];
		}
		return $packages;
	}

	public static function has_naming_error( $name ) {
		if ( ! preg_match( '{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$}iD', $name ) ) {
			return 'The package name is invalid, it should have a vendor name, a forward slash, and a package name.';
		}
		return null;
	}

	/**
	 * @param array      $package Installed metadata.
	 * @param array|null $outdated Composer output, or null if the check failed.
	 * @return array
	 */
	public static function with_update( array $package, $outdated ) {
		$package['update']         = null === $outdated ? 'error' : 'none';
		$package['update_version'] = null === $outdated ? 'error' : '';
		foreach ( $outdated['installed'] ?? [] as $candidate ) {
			if ( strtolower( $package['name'] ) !== strtolower( $candidate['name'] ) ) {
				continue;
			}
			// Composer appends the source reference to dev versions ("dev-main 180a970"); the column shows the version.
			$latest = explode( ' ', trim( (string) $candidate['latest'] ), 2 )[0];
			if ( 'up-to-date' === ( $candidate['latest-status'] ?? '' ) || $candidate['latest'] === $package['version'] ) {
				break;
			}
			$package['update']         = 'available';
			$package['update_version'] = $latest;
			break;
		}
		return $package;
	}
}
