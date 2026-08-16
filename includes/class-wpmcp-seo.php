<?php
/**
 * SEO metadata adapter.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes SEO metadata through whichever SEO plugin the site runs.
 *
 * Every backend stores the same handful of ideas under different meta keys, so
 * tools speak one canonical vocabulary and this class does the translating. If
 * no known SEO plugin is active, the canonical keys are stored under the
 * plugin's own namespace so nothing is silently dropped.
 */
class WPMCP_SEO {

	/**
	 * Canonical field names, in the order they are useful to a human.
	 *
	 * @return string[]
	 */
	public static function fields() {
		return array(
			'seo_title',
			'meta_description',
			'canonical_url',
			'robots_index',
			'robots_follow',
			'og_title',
			'og_description',
			'og_image',
			'twitter_title',
			'twitter_description',
			'twitter_image',
			'schema_type',
		);
	}

	/**
	 * Detects the active SEO backend.
	 *
	 * The three named plugins are detected automatically. A site running its
	 * own SEO implementation returns 'custom' from the filter below and
	 * supplies its meta prefix through wpmcp_seo_meta_prefix, which keeps this
	 * plugin free of any single site's class names.
	 *
	 * @return string One of 'yoast', 'rankmath', 'seopress', 'custom', 'none'.
	 */
	public static function backend() {
		static $backend = null;

		if ( null !== $backend ) {
			return $backend;
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			$backend = 'yoast';
		} elseif ( defined( 'RANK_MATH_VERSION' ) ) {
			$backend = 'rankmath';
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$backend = 'seopress';
		} else {
			$backend = 'none';
		}

		/**
		 * Filters the detected SEO backend.
		 *
		 * @param string $backend Backend slug.
		 */
		$backend = apply_filters( 'wpmcp_seo_backend', $backend );

		return $backend;
	}

	/**
	 * Maps canonical field names to the active backend's meta keys.
	 *
	 * A null value means the backend has no equivalent for that field, and it is
	 * skipped rather than written somewhere misleading.
	 *
	 * @return array<string,string|null>
	 */
	private static function key_map() {
		/**
		 * Filters the finished map of canonical field name to meta key.
		 *
		 * The last word on where SEO values are written. Use it when a site's
		 * keys do not follow a single prefix, or to correct one key of a
		 * detected backend without replacing the whole map.
		 *
		 * @param array<string,string|null> $map     Field name to meta key.
		 * @param string                    $backend Detected backend slug.
		 */
		return apply_filters( 'wpmcp_seo_key_map', self::raw_key_map(), self::backend() );
	}

	/**
	 * The map before filtering.
	 *
	 * @return array<string,string|null>
	 */
	private static function raw_key_map() {
		switch ( self::backend() ) {
			case 'custom':
				/**
				 * Filters the meta key prefix for a site's own SEO storage.
				 *
				 * Only consulted when the backend is 'custom'. Returning an
				 * empty string falls back to this plugin's own namespace
				 * rather than writing keys with no prefix at all.
				 *
				 * @param string $prefix Meta key prefix, including trailing underscore.
				 */
				$prefix = (string) apply_filters( 'wpmcp_seo_meta_prefix', '' );

				if ( '' === $prefix ) {
					$prefix = '_wpmcp_seo_';
				}

				$map = array();
				foreach ( self::fields() as $field ) {
					$map[ $field ] = $prefix . $field;
				}

				return $map;

			case 'yoast':
				return array(
					'seo_title'           => '_yoast_wpseo_title',
					'meta_description'    => '_yoast_wpseo_metadesc',
					'canonical_url'       => '_yoast_wpseo_canonical',
					'robots_index'        => '_yoast_wpseo_meta-robots-noindex',
					'robots_follow'       => '_yoast_wpseo_meta-robots-nofollow',
					'og_title'            => '_yoast_wpseo_opengraph-title',
					'og_description'      => '_yoast_wpseo_opengraph-description',
					'og_image'            => '_yoast_wpseo_opengraph-image',
					'twitter_title'       => '_yoast_wpseo_twitter-title',
					'twitter_description' => '_yoast_wpseo_twitter-description',
					'twitter_image'       => '_yoast_wpseo_twitter-image',
					'schema_type'         => '_yoast_wpseo_schema_page_type',
				);

			case 'rankmath':
				return array(
					'seo_title'           => 'rank_math_title',
					'meta_description'    => 'rank_math_description',
					'canonical_url'       => 'rank_math_canonical_url',
					'robots_index'        => 'rank_math_robots',
					'robots_follow'       => 'rank_math_robots',
					'og_title'            => 'rank_math_facebook_title',
					'og_description'      => 'rank_math_facebook_description',
					'og_image'            => 'rank_math_facebook_image',
					'twitter_title'       => 'rank_math_twitter_title',
					'twitter_description' => 'rank_math_twitter_description',
					'twitter_image'       => 'rank_math_twitter_image',
					'schema_type'         => null,
				);

			case 'seopress':
				return array(
					'seo_title'           => '_seopress_titles_title',
					'meta_description'    => '_seopress_titles_desc',
					'canonical_url'       => '_seopress_robots_canonical',
					'robots_index'        => '_seopress_robots_index',
					'robots_follow'       => '_seopress_robots_follow',
					'og_title'            => '_seopress_social_fb_title',
					'og_description'      => '_seopress_social_fb_desc',
					'og_image'            => '_seopress_social_fb_img',
					'twitter_title'       => '_seopress_social_twitter_title',
					'twitter_description' => '_seopress_social_twitter_desc',
					'twitter_image'       => '_seopress_social_twitter_img',
					'schema_type'         => null,
				);

			default:
				$map = array();
				foreach ( self::fields() as $field ) {
					$map[ $field ] = '_wpmcp_seo_' . $field;
				}

				return $map;
		}
	}

	/**
	 * Reads a post's SEO metadata in canonical form.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>
	 */
	public static function get( $post_id ) {
		$out = array();

		foreach ( self::key_map() as $field => $key ) {
			if ( null === $key ) {
				continue;
			}

			$value = get_post_meta( $post_id, $key, true );

			if ( '' === $value || null === $value ) {
				continue;
			}

			$out[ $field ] = self::from_storage( $field, (string) $value );
		}

		return $out;
	}

	/**
	 * Writes canonical SEO metadata to a post.
	 *
	 * Fields absent from the input are left alone; an explicit empty string
	 * deletes the value. That distinction matters, because a model updating one
	 * field should never wipe the eleven it did not mention.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string,string> $values  Canonical field values.
	 * @return string[] Names of the fields actually written.
	 */
	public static function update( $post_id, array $values ) {
		$map     = self::key_map();
		$written = array();

		foreach ( $values as $field => $value ) {
			if ( ! isset( $map[ $field ] ) || null === $map[ $field ] ) {
				continue;
			}

			$key   = $map[ $field ];
			$value = self::sanitize( $field, $value );

			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, self::to_storage( $field, $value ) );
			}

			$written[] = $field;
		}

		return $written;
	}

	/**
	 * Sanitises one canonical value.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return string
	 */
	private static function sanitize( $field, $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		if ( in_array( $field, array( 'canonical_url', 'og_image', 'twitter_image' ), true ) ) {
			return esc_url_raw( $value );
		}

		if ( 'robots_index' === $field ) {
			return in_array( $value, array( 'index', 'noindex' ), true ) ? $value : '';
		}

		if ( 'robots_follow' === $field ) {
			return in_array( $value, array( 'follow', 'nofollow' ), true ) ? $value : '';
		}

		if ( in_array( $field, array( 'meta_description', 'og_description', 'twitter_description' ), true ) ) {
			return sanitize_textarea_field( $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Converts a canonical value into the backend's storage format.
	 *
	 * @param string $field Field name.
	 * @param string $value Canonical value.
	 * @return string
	 */
	private static function to_storage( $field, $value ) {
		// Yoast stores robots directives as "1" for the negative case.
		if ( 'yoast' === self::backend() ) {
			if ( 'robots_index' === $field ) {
				return 'noindex' === $value ? '1' : '2';
			}

			if ( 'robots_follow' === $field ) {
				return 'nofollow' === $value ? '1' : '0';
			}
		}

		return $value;
	}

	/**
	 * Converts a stored value back to canonical form.
	 *
	 * @param string $field Field name.
	 * @param string $value Stored value.
	 * @return string
	 */
	private static function from_storage( $field, $value ) {
		if ( 'yoast' === self::backend() ) {
			if ( 'robots_index' === $field ) {
				return '1' === $value ? 'noindex' : 'index';
			}

			if ( 'robots_follow' === $field ) {
				return '1' === $value ? 'nofollow' : 'follow';
			}
		}

		return $value;
	}

	/**
	 * The JSON Schema fragment for an SEO object, shared by several tools.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema() {
		return array(
			'type'                 => 'object',
			'description'          => sprintf(
				/* translators: %s: detected SEO plugin name. */
				__( 'SEO metadata. Written through the site\'s active SEO plugin (detected: %s). Omit a field to leave it unchanged; pass an empty string to clear it.', 'wp-mcp-connector' ),
				self::backend()
			),
			'additionalProperties' => false,
			'properties'           => array(
				'seo_title'           => WPMCP_Schema::string( __( 'Title tag. Aim for under 60 characters.', 'wp-mcp-connector' ) ),
				'meta_description'    => WPMCP_Schema::string( __( 'Meta description. Aim for 120 to 155 characters.', 'wp-mcp-connector' ) ),
				'canonical_url'       => WPMCP_Schema::string( __( 'Canonical URL, absolute.', 'wp-mcp-connector' ) ),
				'robots_index'        => WPMCP_Schema::string( __( 'Whether search engines may index this page.', 'wp-mcp-connector' ), array( 'index', 'noindex' ) ),
				'robots_follow'       => WPMCP_Schema::string( __( 'Whether search engines may follow links on this page.', 'wp-mcp-connector' ), array( 'follow', 'nofollow' ) ),
				'og_title'            => WPMCP_Schema::string( __( 'Open Graph title for Facebook and LinkedIn shares.', 'wp-mcp-connector' ) ),
				'og_description'      => WPMCP_Schema::string( __( 'Open Graph description.', 'wp-mcp-connector' ) ),
				'og_image'            => WPMCP_Schema::string( __( 'Open Graph image URL.', 'wp-mcp-connector' ) ),
				'twitter_title'       => WPMCP_Schema::string( __( 'Twitter card title.', 'wp-mcp-connector' ) ),
				'twitter_description' => WPMCP_Schema::string( __( 'Twitter card description.', 'wp-mcp-connector' ) ),
				'twitter_image'       => WPMCP_Schema::string( __( 'Twitter card image URL.', 'wp-mcp-connector' ) ),
				'schema_type'         => WPMCP_Schema::string( __( 'Schema.org page type, for example Article or FAQPage.', 'wp-mcp-connector' ) ),
			),
		);
	}
}
