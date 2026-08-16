<?php
/**
 * Site introspection tools.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports what this WordPress install actually is: version, theme, plugins,
 * post types, users.
 */
class WPMCP_Tools_Site {

	/**
	 * Registers the tools.
	 *
	 * @param WPMCP_Registry $registry Registry.
	 * @return void
	 */
	public function register( WPMCP_Registry $registry ) {
		$registry->add(
			array(
				'name'         => 'wp_get_site_info',
				'title'        => __( 'Get site information', 'wp-mcp-connector' ),
				'description'  => __( 'Summarise the site: name, tagline, URLs, WordPress and PHP versions, active theme, registered post types and taxonomies, content counts, and the identity and role of the connected user. Call this first in a new conversation. It tells you which post types exist and what the connected account is allowed to do, which stops you guessing at either.', 'wp-mcp-connector' ),
				'group'        => 'site',
				'capability'   => 'read',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'get_site_info' ),
				'input_schema' => WPMCP_Schema::object( array() ),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_list_plugins',
				'title'        => __( 'List installed plugins', 'wp-mcp-connector' ),
				'description'  => __( 'List every installed plugin with its version, author, active state and whether an update is waiting. Read only: this tool cannot activate, deactivate or update anything. Useful for diagnosing conflicts and for knowing which SEO, caching or form plugin the site actually uses before you make assumptions about it.', 'wp-mcp-connector' ),
				'group'        => 'site',
				'capability'   => 'activate_plugins',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'list_plugins' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'status' => WPMCP_Schema::string( __( 'Filter by state. Defaults to all.', 'wp-mcp-connector' ), array( 'all', 'active', 'inactive', 'update_available' ) ),
					)
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_list_themes',
				'title'        => __( 'List installed themes', 'wp-mcp-connector' ),
				'description'  => __( 'List installed themes with version, author, parent theme and which one is active. Read only. Check this before writing template-specific markup, since a block theme and a classic theme need different content.', 'wp-mcp-connector' ),
				'group'        => 'site',
				'capability'   => 'switch_themes',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'list_themes' ),
				'input_schema' => WPMCP_Schema::object( array() ),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_list_users',
				'title'        => __( 'List users', 'wp-mcp-connector' ),
				'description'  => __( 'List site users with their ID, display name, role and post count. Email addresses are only included for callers who can edit users. Use this to find an author ID when assigning content.', 'wp-mcp-connector' ),
				'group'        => 'site',
				'capability'   => 'list_users',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'list_users' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'role'     => WPMCP_Schema::string( __( 'Filter by role slug, for example editor.', 'wp-mcp-connector' ) ),
						'search'   => WPMCP_Schema::string( __( 'Match against login, display name and email.', 'wp-mcp-connector' ) ),
						'per_page' => WPMCP_Schema::integer( __( 'Results to return, 1 to 100. Defaults to 20.', 'wp-mcp-connector' ), 1, 100 ),
					)
				),
			)
		);
	}

	/**
	 * Builds the site summary.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>
	 */
	public function get_site_info( array $args ) {
		$theme = wp_get_theme();
		$user  = wp_get_current_user();

		$post_types = array();

		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			$counts = wp_count_posts( $type->name );

			$post_types[] = array(
				'slug'         => $type->name,
				'label'        => $type->labels->name,
				'hierarchical' => (bool) $type->hierarchical,
				'published'    => isset( $counts->publish ) ? (int) $counts->publish : 0,
				'draft'        => isset( $counts->draft ) ? (int) $counts->draft : 0,
			);
		}

		$taxonomies = array();

		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $taxonomy ) {
			$taxonomies[] = array(
				'slug'       => $taxonomy->name,
				'label'      => $taxonomy->labels->name,
				'post_types' => array_values( (array) $taxonomy->object_type ),
				'terms'      => (int) wp_count_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => false ) ),
			);
		}

		return array(
			'name'             => get_bloginfo( 'name' ),
			'description'      => get_bloginfo( 'description' ),
			'url'              => home_url( '/' ),
			'admin_url'        => admin_url(),
			'language'         => get_bloginfo( 'language' ),
			'timezone'         => wp_timezone_string(),
			'wordpress'        => get_bloginfo( 'version' ),
			'php'              => PHP_VERSION,
			'environment'      => wp_get_environment_type(),
			'multisite'        => is_multisite(),
			'permalink_struct' => get_option( 'permalink_structure' ),
			'theme'            => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'author'  => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
				'block_theme' => wp_is_block_theme(),
			),
			'seo_plugin'       => WPMCP_SEO::backend(),
			'post_types'       => $post_types,
			'taxonomies'       => $taxonomies,
			'comments'         => array(
				'approved' => (int) wp_count_comments()->approved,
				'pending'  => (int) wp_count_comments()->moderated,
				'spam'     => (int) wp_count_comments()->spam,
			),
			'connected_as'     => array(
				'id'           => $user->ID,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'roles'        => array_values( (array) $user->roles ),
				'can_publish'  => current_user_can( 'publish_posts' ),
				'can_upload'   => current_user_can( 'upload_files' ),
				'is_admin'     => current_user_can( 'manage_options' ),
			),
			'mcp'              => array(
				'plugin_version' => WPMCP_VERSION,
				'profile'        => wpmcp()->settings()->get( 'profile' ),
			),
		);
	}

	/**
	 * Lists plugins.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>
	 */
	public function list_plugins( array $args ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$filter  = isset( $args['status'] ) ? $args['status'] : 'all';
		$updates = get_site_transient( 'update_plugins' );
		$pending = isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
		$output  = array();

		foreach ( get_plugins() as $file => $plugin ) {
			$active     = is_plugin_active( $file );
			$has_update = isset( $pending[ $file ] );

			if ( 'active' === $filter && ! $active ) {
				continue;
			}

			if ( 'inactive' === $filter && $active ) {
				continue;
			}

			if ( 'update_available' === $filter && ! $has_update ) {
				continue;
			}

			$output[] = array(
				'file'           => $file,
				'name'           => $plugin['Name'],
				'version'        => $plugin['Version'],
				'author'         => wp_strip_all_tags( (string) $plugin['Author'] ),
				'description'    => wp_strip_all_tags( (string) $plugin['Description'] ),
				'active'         => $active,
				'network_active' => is_multisite() && is_plugin_active_for_network( $file ),
				'update_to'      => $has_update && isset( $pending[ $file ]->new_version ) ? $pending[ $file ]->new_version : null,
			);
		}

		return array(
			'plugins' => $output,
			'total'   => count( $output ),
			'note'    => __( 'This tool is read only. Activating, deactivating or updating a plugin must be done from the WordPress admin.', 'wp-mcp-connector' ),
		);
	}

	/**
	 * Lists themes.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>
	 */
	public function list_themes( array $args ) {
		$active  = get_stylesheet();
		$updates = get_site_transient( 'update_themes' );
		$pending = isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
		$output  = array();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$output[] = array(
				'slug'        => $slug,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'author'      => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'parent'      => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
				'active'      => $slug === $active,
				'block_theme' => $theme->is_block_theme(),
				'update_to'   => isset( $pending[ $slug ]['new_version'] ) ? $pending[ $slug ]['new_version'] : null,
			);
		}

		return array(
			'themes' => $output,
			'active' => $active,
			'note'   => __( 'This tool is read only. Switching themes must be done from the WordPress admin.', 'wp-mcp-connector' ),
		);
	}

	/**
	 * Lists users.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>
	 */
	public function list_users( array $args ) {
		$query_args = array(
			'number'  => isset( $args['per_page'] ) ? (int) $args['per_page'] : 20,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		);

		if ( ! empty( $args['role'] ) ) {
			$query_args['role'] = sanitize_key( $args['role'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$query_args['search']         = '*' . $args['search'] . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$can_see_emails = current_user_can( 'edit_users' );
		$users          = array();

		foreach ( get_users( $query_args ) as $user ) {
			$entry = array(
				'id'           => $user->ID,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'roles'        => array_values( (array) $user->roles ),
				'posts'        => (int) count_user_posts( $user->ID, 'post', true ),
			);

			// Email addresses are personal data, so they are only exposed to a
			// caller who could read them in the admin anyway.
			if ( $can_see_emails ) {
				$entry['email'] = $user->user_email;
			}

			$users[] = $entry;
		}

		return array(
			'users' => $users,
			'total' => count( $users ),
		);
	}
}
