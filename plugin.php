<?php
/**
 * Plugin Name: Advanced Custom Fields Multilingual Fixer
 * Description: It fixes the flaw from 1.6.0 release
 * Author: OnTheGoSystems
 * Plugin URI: https://wpml.org/
 * Author URI: http://www.onthegosystems.com/
 * Version: 0.0.1
 *
 * @package WPML\ACF\Fixer
 */

namespace WPML\ACF\Fixer {

	const THRESHOLD = 1000;

	function getPostMetaChunk( $limit, $offset ) {
		global $wpdb;

		$sql   = "SELECT * FROM {$wpdb->postmeta} WHERE post_id > %d AND post_id <= %d";
		$query = $wpdb->prepare( $sql, $offset, $offset + $limit );
		$metas = $wpdb->get_results( $query );
//		$metas[] = getPostMetaById( 8861993 );

		if ( ! is_array( $metas ) ) {
			$metas = [];
		}

		return $metas;
	}

	function getPostMetaById( $metaId ) {
		global $wpdb;

		$sql = "SELECT * FROM {$wpdb->postmeta} WHERE meta_id = %d";

		return $wpdb->get_row( $wpdb->prepare( $sql, $metaId ) );
	}

	function updateMetaData( $metaId, $data ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->postmeta,
			[ 'meta_value' => maybe_serialize( $data ) ],
			[ 'meta_id' => $metaId ]
		);
	}

	function getPostCount() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts}" );
	}

	function getNextChunk( $chunkSize ) {
		$metaWithKeyStartingWithUnderScore = function ( $meta ) {
			return startsWith( $meta->meta_key, '_' );
		};

		$offset = 0;

		while ( $postMeta = getPostMetaChunk( $chunkSize, $offset ) ) {
			$postMeta = array_filter( $postMeta, $metaWithKeyStartingWithUnderScore );
			yield $postMeta;
			$offset += $chunkSize;
		}
	}

	function createProgressBar( $chunkSize ) {
		return \WP_CLI\Utils\make_progress_bar( 'Clearing post meta', ceil( getPostCount() / $chunkSize ) );
	}

	function hasLargeNestedElement( $meta ) {
		foreach ( $meta->meta_value as $key => $val ) {
			if ( strlen( $val[0] ) > 100000 ) {
				return true;
			}
		}

		return false;
	}

	function isAffected( $meta ) {
		$meta->meta_value = maybe_unserialize( $meta->meta_value );

		return is_array( $meta->meta_value  ) && ( isset( $meta->meta_value [ $meta->meta_key ] ) || ( count( $meta->meta_value  ) > THRESHOLD && hasLargeNestedElement( $meta ) ) );
	}

	function clearMeta( $meta ) {
		if ( isAffected( $meta ) ) {
			$metaValue = maybe_unserialize( $meta->meta_value );

			if ( isset( $metaValue[ $meta->meta_key ] ) ) {
				$meta->meta_value = array_pop( $metaValue[ $meta->meta_key ] );
			} else {
				$meta->meta_value = '';
			}

			$meta = clearMeta( $meta );
		}

		return $meta;
	}

	function maybeUpdateMeta( $meta ) {
		$meta->meta_value = maybe_unserialize( $meta->meta_value );

		$newMeta = clone $meta;
		$newMeta = clearMeta( $newMeta );

		if ( $meta->meta_value !== $newMeta->meta_value ) {
			updateMetaData( $meta->meta_id, $newMeta->meta_value );
		}
	}

	function logIfAffected( $meta ) {
		if ( isAffected( $meta ) ) {
			file_put_contents(
				__DIR__ . '/affected.cvs',
				implode( ',', [ $meta->meta_id, $meta->meta_key, $meta->post_id ] ) . PHP_EOL,
				FILE_APPEND
			);
		};
	}

	function commandTemplate( $callback, $successMessage ) {
		$chunkSize   = 1000;
		$progressBar = createProgressBar( $chunkSize );

		foreach ( getNextChunk( $chunkSize ) as $postMeta ) {
			$callback( $postMeta );

			$progressBar->tick();
		}


		$progressBar->finish();
		\WP_CLI::success( $successMessage );
	}

	function createClearCommandHandler() {
		return function () {
			commandTemplate(
				function ( $postMeta ) {
					array_map( 'WPML\ACF\Fixer\maybeUpdateMeta', $postMeta );
				},
				'All posts cleared'
			);
		};
	}

	function createListCommandHandler() {
		return function () {
			commandTemplate(
				function ( $postMeta ) {
					array_map( 'WPML\ACF\Fixer\logIfAffected', $postMeta );
				},
				'List of affected posts has been generated'
			);
		};
	}
}

namespace {

	if ( defined( 'WP_CLI' ) ) {
		/**
		 * Clear postmeta corrupted by the bug from ACFML 1.6.0
		 *
		 */
		$clear = \WPML\ACF\Fixer\createClearCommandHandler();
		\WP_CLI::add_command( 'acfml clear', $clear );

		/**
		 * List postmeta corrupted by the bug from ACFML 1.6.0
		 *
		 */
		$list = \WPML\ACF\Fixer\createListCommandHandler();
		\WP_CLI::add_command( 'acfml list', $list );
	}
}