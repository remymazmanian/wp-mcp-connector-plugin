<?php
/**
 * Taxonomy tools: categories, tags and custom taxonomies.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and creates taxonomy terms.
 */
class WPMCP_Tools_Taxonomy {

	/**
	 * Registers the tools.
	 *
	 * @param WPMCP_Registry $registry Registry.
	 * @return void
	 */
	public function register( WPMCP_Registry $registry ) {
		$registry->add(
			array(
				'name'         => 'wp_list_terms',
				'title'        => __( 'List categories and tags', 'wp-mcp-connector' ),
				'description'  => __( 'List the terms in a taxonomy with their slugs, post counts and hierarchy. Call this before assigning categories or tags so you reuse the site\'s existing taxonomy instead of inventing near-duplicates like "Case Study" alongside an existing "Case Studies".', 'wp-mcp-connector' ),
				'group'        => 'taxonomy',
				'capability'   => 'edit_posts',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'list_terms' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'taxonomy'   => WPMCP_Schema::string( __( 'Taxonomy slug, for example category or post_tag. Defaults to category.', 'wp-mcp-connector' ) ),
						'search'     => WPMCP_Schema::string( __( 'Filter terms by name.', 'wp-mcp-connector' ) ),
						'hide_empty' => WPMCP_Schema::boolean( __( 'Skip terms with no posts. Defaults to false.', 'wp-mcp-connector' ) ),
						'per_page'   => WPMCP_Schema::integer( __( 'Maximum terms to return, 1 to 200. Defaults to 100.', 'wp-mcp-connector' ), 1, 200 ),
					)
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_create_term',
				'title'        => __( 'Create a category or tag', 'wp-mcp-connector' ),
				'description'  => __( 'Create a new taxonomy term. Check wp_list_terms first: an existing term with a slightly different name should be reused rather than duplicated. Note that wp_create_post and wp_update_post already create missing terms on the fly, so this is only needed when you want a description or a parent set.', 'wp-mcp-connector' ),
				'group'        => 'taxonomy',
				'capability'   => 'manage_categories',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => false ),
				'callback'     => array( $this, 'create_term' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'name'        => WPMCP_Schema::string( __( 'Display name of the term.', 'wp-mcp-connector' ) ),
						'taxonomy'    => WPMCP_Schema::string( __( 'Taxonomy slug. Defaults to category.', 'wp-mcp-connector' ) ),
						'slug'        => WPMCP_Schema::string( __( 'URL slug. Generated from the name when omitted.', 'wp-mcp-connector' ) ),
						'description' => WPMCP_Schema::string( __( 'Term description, shown on archive pages by some themes.', 'wp-mcp-connector' ) ),
						'parent'      => WPMCP_Schema::integer( __( 'Parent term ID, for hierarchical taxonomies such as category.', 'wp-mcp-connector' ), 0 ),
					),
					array( 'name' )
				),
			)
		);
	}

	/**
	 * Lists terms.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function list_terms( array $args ) {
		$taxonomy = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : 'category';

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'wpmcp_unknown_taxonomy',
				sprintf(
					/* translators: 1: requested taxonomy, 2: available taxonomies. */
					__( 'Unknown taxonomy "%1$s". This site has: %2$s', 'wp-mcp-connector' ),
					$taxonomy,
					implode( ', ', get_taxonomies( array( 'show_ui' => true ), 'names' ) )
				)
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => ! empty( $args['hide_empty'] ),
				'search'     => isset( $args['search'] ) ? $args['search'] : '',
				'number'     => isset( $args['per_page'] ) ? (int) $args['per_page'] : 100,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$output = array();

		foreach ( $terms as $term ) {
			$output[] = array(
				'id'          => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'count'       => (int) $term->count,
				'parent'      => (int) $term->parent,
				'url'         => get_term_link( $term ),
			);
		}

		return array(
			'taxonomy' => $taxonomy,
			'terms'    => $output,
			'total'    => count( $output ),
		);
	}

	/**
	 * Creates a term.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_term( array $args ) {
		$taxonomy = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : 'category';

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'wpmcp_unknown_taxonomy',
				sprintf(
					/* translators: %s: taxonomy slug. */
					__( 'Unknown taxonomy "%s".', 'wp-mcp-connector' ),
					$taxonomy
				)
			);
		}

		$taxonomy_object = get_taxonomy( $taxonomy );

		if ( ! current_user_can( $taxonomy_object->cap->manage_terms ) ) {
			return new WP_Error( 'wpmcp_forbidden', __( 'You do not have permission to create terms in that taxonomy.', 'wp-mcp-connector' ) );
		}

		$name     = sanitize_text_field( (string) $args['name'] );
		$existing = get_term_by( 'name', $name, $taxonomy );

		if ( $existing ) {
			return array(
				'created' => false,
				'reason'  => __( 'A term with that name already exists, so it was reused rather than duplicated.', 'wp-mcp-connector' ),
				'term'    => array(
					'id'   => (int) $existing->term_id,
					'name' => $existing->name,
					'slug' => $existing->slug,
				),
			);
		}

		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug'        => isset( $args['slug'] ) ? sanitize_title( $args['slug'] ) : '',
				'description' => isset( $args['description'] ) ? sanitize_textarea_field( $args['description'] ) : '',
				'parent'      => isset( $args['parent'] ) ? (int) $args['parent'] : 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wpmcp_term_failed',
				sprintf(
					/* translators: %s: WordPress error message. */
					__( 'Could not create the term: %s', 'wp-mcp-connector' ),
					$result->get_error_message()
				)
			);
		}

		$term = get_term( $result['term_id'], $taxonomy );

		return array(
			'created' => true,
			'term'    => array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
				'url'  => get_term_link( $term ),
			),
		);
	}
}
