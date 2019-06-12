<?php
/**
 * Plugin Name:     Byline Manager SST Add-On
 * Plugin URI:      https://github.com/alleyinteractive/byline-manager-sst
 * Description:     Allow SST to set Bylines
 * Author:          Matthew Boynes
 * Author URI:      https://www.alley.co
 * Text Domain:     byline-manager-sst
 * Version:         0.1.0
 *
 * @package         Byline_Manager_SST
 */

namespace Byline_Manager\SST;

use WP_Error;
use WP_Post;

if ( ! method_exists( '\Byline_Manager\Utils', 'set_post_byline' ) ) {
	return;
}

add_action( 'rest_api_init', __NAMESPACE__ . '\add_rest_field' );

/**
 * Register the byline_entries REST field to the sst-post object.
 */
function add_rest_field() {
	register_rest_field(
		'sst-post',
		'byline_entries',
		[
			'update_callback' => function( array $byline_entries, WP_Post $post ) {
				$meta = [
					'byline_entries' => [],
				];

				foreach ( $byline_entries as $entry ) {
					// Don't save empty items.
					if ( empty( $entry['text'] ) && empty( $entry['name'] ) ) {
						return new WP_Error(
							'invalid-byline',
							__( 'Byline entry must contain either the name or text property.', 'byline-manager-sst' )
						);
					}
					// Don't save ambiguous entries.
					if ( ! empty( $entry['text'] ) && ! empty( $entry['name'] ) ) {
						return new WP_Error(
							'invalid-byline',
							__( 'Byline entry must not contain both the name and text properties.', 'byline-manager-sst' )
						);
					}

					if ( ! empty( $entry['text'] ) ) {
						$meta['byline_entries'][] = [
							'type' => 'text',
							'atts' => [
								'text' => wp_kses_post( $entry['text'] ),
							],
						];
					} else {
						$byline_id = get_or_create_byline_id_by_name(
							$entry['name'],
							$entry['data']
						);
						if ( is_wp_error( $byline_id ) ) {
							return $byline_id;
						}

						$meta['byline_entries'][] = [
							'type' => 'byline_id',
							'atts' => compact( 'byline_id' ),
						];
					}
				}

				if ( ! empty( $meta['byline_entries'] ) ) {
					\Byline_Manager\Utils::set_post_byline( $post->ID, $meta );
				}

				return true;
			},
			'schema'          => [
				'description' => __( 'Byline entries which together make up the byline.', 'byline-manager-sst' ),
				'type'        => 'array',
				'items'       => [
					'description' => __( 'Byline entry. One of either `name` or `text` is required, but not both.', 'byline-manager-sst' ),
					'type'        => 'object',
					'properties'  => [
						'name' => [
							'description' => __( 'The name of a profile on the site. If no existing profile found matching the name, one is created.', 'byline-manager-sst' ),
							'type'        => 'string',
							'required'    => false,
						],
						'text' => [
							'description' => __( 'Arbitrary text to add to the byline not associated to a profile.', 'byline-manager-sst' ),
							'type'        => 'string',
							'required'    => false,
						],
					],
				],
			],
		]
	);
}

/**
 * Given a name, get the byline_id associated with the profile matching that
 * name, or create a new profile and return the associated byline_id.
 *
 * @param string $name Author name.
 * @param array  $data Author data.
 * @return int|\WP_Error Byline term ID on success, WP_Error on failure.
 */
function get_or_create_byline_id_by_name( string $name, array $data ) {
	$profile = get_page_by_title( $name, OBJECT, \Byline_Manager\PROFILE_POST_TYPE );
	if ( ! $profile ) {
		$profile_id = wp_insert_post(
			[
				'post_title'  => $name,
				'post_type'   => \Byline_Manager\PROFILE_POST_TYPE,
				'post_status' => 'publish',
			],
			true
		);
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		foreach ( array_keys( $data ) as $key ) {
			update_post_meta( $profile_id, $key, $data[ $key ] );
		}
	} else {
		$profile_id = $profile->ID;
	}

	$byline_id = intval( get_post_meta( $profile_id, 'byline_id', true ) );
	if ( ! $byline_id ) {
		return new WP_Error(
			'byline-error',
			/* translators: 1: Byline entry name */
			sprintf( __( 'Unable to get byline ID for "%1$s"', 'byline-manager-sst' ), $name )
		);
	}

	return $byline_id;
}
