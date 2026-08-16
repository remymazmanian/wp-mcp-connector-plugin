<?php
/**
 * Content tools: posts, pages and any other public post type.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create, read, update, delete and search content.
 */
class WPMCP_Tools_Content {

	/**
	 * Registers the tools.
	 *
	 * @param WPMCP_Registry $registry Registry.
	 * @return void
	 */
	public function register( WPMCP_Registry $registry ) {
		$post_types = $this->post_type_slugs();

		$registry->add(
			array(
				'name'         => 'wp_list_posts',
				'title'        => __( 'List posts and pages', 'wp-mcp-connector' ),
				'description'  => __( 'List content of any post type with filters for status, author, category, tag, date and search term. Returns summaries without the full body, so it is cheap to call. Use this to find things and to browse; use wp_get_post when you need the actual content of one item. Results are paginated: check the returned total and page values before assuming you have seen everything.', 'wp-mcp-connector' ),
				'group'        => 'content',
				'capability'   => 'edit_posts',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'list_posts' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'post_type' => WPMCP_Schema::string( __( 'Post type slug. Use "any" to search across all of them. Defaults to post.', 'wp-mcp-connector' ), array_merge( $post_types, array( 'any' ) ) ),
						'status'    => WPMCP_Schema::string( __( 'Post status. "any" covers everything except trash and auto-drafts. Defaults to any.', 'wp-mcp-connector' ), array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'any' ) ),
						'search'    => WPMCP_Schema::string( __( 'Keyword to match in title and content.', 'wp-mcp-connector' ) ),
						'author'    => WPMCP_Schema::integer( __( 'Restrict to one author, by user ID.', 'wp-mcp-connector' ), 1 ),
						'category'  => WPMCP_Schema::string( __( 'Category slug to filter by.', 'wp-mcp-connector' ) ),
						'tag'       => WPMCP_Schema::string( __( 'Tag slug to filter by.', 'wp-mcp-connector' ) ),
						'orderby'   => WPMCP_Schema::string( __( 'Sort field. Defaults to date.', 'wp-mcp-connector' ), array( 'date', 'modified', 'title', 'menu_order', 'comment_count', 'ID' ) ),
						'order'     => WPMCP_Schema::string( __( 'Sort direction. Defaults to DESC.', 'wp-mcp-connector' ), array( 'ASC', 'DESC' ) ),
						'per_page'  => WPMCP_Schema::integer( __( 'Results per page, 1 to 100. Defaults to 20.', 'wp-mcp-connector' ), 1, 100 ),
						'page'      => WPMCP_Schema::integer( __( 'Page number, starting at 1.', 'wp-mcp-connector' ), 1 ),
					)
				),
				'output_schema' => WPMCP_Schema::object(
					array(
						'posts' => WPMCP_Schema::arr( __( 'Matching posts.', 'wp-mcp-connector' ), array( 'type' => 'object' ) ),
						'total' => WPMCP_Schema::integer( __( 'Total matches across all pages.', 'wp-mcp-connector' ) ),
						'pages' => WPMCP_Schema::integer( __( 'Total number of pages.', 'wp-mcp-connector' ) ),
						'page'  => WPMCP_Schema::integer( __( 'Page returned.', 'wp-mcp-connector' ) ),
					)
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_get_post',
				'title'        => __( 'Get one post or page', 'wp-mcp-connector' ),
				'description'  => __( 'Fetch a single post or page in full: body content, excerpt, status, author, categories, tags, featured image, custom fields and SEO metadata. Accepts either a numeric ID or a URL or slug. Always call this before editing something, so that your update preserves the existing block markup instead of overwriting it.', 'wp-mcp-connector' ),
				'group'        => 'content',
				'capability'   => 'edit_posts',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'get_post' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'id'   => WPMCP_Schema::integer( __( 'Post ID.', 'wp-mcp-connector' ), 1 ),
						'slug' => WPMCP_Schema::string( __( 'Post slug or full permalink, as an alternative to the ID.', 'wp-mcp-connector' ) ),
					)
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_create_post',
				'title'        => __( 'Create a post or page', 'wp-mcp-connector' ),
				'description'  => __( 'Create new content. Content should be WordPress block markup (<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->) for the block editor, or plain HTML if the site uses the classic editor. Defaults to a draft: only pass status "publish" when the user has clearly asked for it to go live. Categories and tags may be given as names or slugs, and are created if they do not exist.', 'wp-mcp-connector' ),
				'group'        => 'content',
				'capability'   => 'edit_posts',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => false ),
				'callback'     => array( $this, 'create_post' ),
				'input_schema' => WPMCP_Schema::object( $this->writable_properties( $post_types, true ), array( 'title' ) ),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_update_post',
				'title'        => __( 'Update a post or page', 'wp-mcp-connector' ),
				'description'  => __( 'Modify existing content. Only the fields you pass are changed, so you can update just the excerpt or just the SEO metadata without touching the body. To edit the body safely, call wp_get_post first, modify the returned content, and send the whole new body back. Passing status "publish" on a draft publishes it.', 'wp-mcp-connector' ),
				'group'        => 'content',
				'capability'   => 'edit_posts',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => true ),
				'callback'     => array( $this, 'update_post' ),
				'input_schema' => WPMCP_Schema::object(
					array_merge(
						array( 'id' => WPMCP_Schema::integer( __( 'ID of the post to update.', 'wp-mcp-connector' ), 1 ) ),
						$this->writable_properties( $post_types, false )
					),
					array( 'id' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_delete_post',
				'title'        => __( 'Trash or delete a post', 'wp-mcp-connector' ),
				'description'  => __( 'Move a post or page to the trash, where it can be restored. Permanent deletion requires force: true and cannot be undone, so only pass it when the user has explicitly asked for permanent removal. Confirm with the user before calling this on published content.', 'wp-mcp-connector' ),
				'group'        => 'content',
				'capability'   => 'delete_posts',
				'profiles'     => array( 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => true ),
				'callback'     => array( $this, 'delete_post' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'id'    => WPMCP_Schema::integer( __( 'ID of the post to remove.', 'wp-mcp-connector' ), 1 ),
						'force' => WPMCP_Schema::boolean( __( 'Delete permanently instead of trashing. Irreversible. Defaults to false.', 'wp-mcp-connector' ) ),
					),
					array( 'id' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_search_content',
				'title'        => __( 'Search the site', 'wp-mcp-connector' ),
				'description'  => __( 'Full-text search across posts, pages and other content, returning a matching snippet for each hit. Reach for this rather than listing everything when you are looking for where a topic, phrase or product is mentioned.', 'wp-mcp-connector' ),
				'group'        => 'content',
				'capability'   => 'edit_posts',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'search_content' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'query'     => WPMCP_Schema::string( __( 'What to search for.', 'wp-mcp-connector' ) ),
						'post_type' => WPMCP_Schema::string( __( 'Restrict to one post type. Defaults to any.', 'wp-mcp-connector' ), array_merge( $post_types, array( 'any' ) ) ),
						'status'    => WPMCP_Schema::string( __( 'Restrict to one status. Defaults to any.', 'wp-mcp-connector' ), array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ) ),
						'per_page'  => WPMCP_Schema::integer( __( 'Results to return, 1 to 50. Defaults to 10.', 'wp-mcp-connector' ), 1, 50 ),
					),
					array( 'query' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_publish_article',
				'title'        => __( 'Create a complete article in one call', 'wp-mcp-connector' ),
				'description'  => __( 'Build a finished post in a single call: the body, the featured image, any in-article images, and the SEO metadata. Each image can come from wherever you have it: a url the server downloads, base64 for bytes you hold yourself, an upload_id from a chunked transfer you already finished, or an attachment_id already in the library. Mix them freely in one call. Prefer url whenever the image exists at one, because nothing then passes through you and one call does the work of eight. This is the tool to reach for when you have written an article and found photographs for it: use it instead of wp_create_post followed by separate upload, insert and SEO calls. Images are placed in the body at the paragraph you name. Give every image alt text describing what is actually in the frame, and a credit and licence for any photograph you did not make. If an image cannot be fetched the article is still created and the response says which one failed and why, so nothing is lost.', 'wp-mcp-connector' ),
				'group'        => 'content',
				'capability'   => 'edit_posts',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => false ),
				'callback'     => array( $this, 'publish_article' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'title'          => WPMCP_Schema::string( __( 'The post title, as plain text.', 'wp-mcp-connector' ) ),
						'content'        => WPMCP_Schema::string( __( 'The full body as WordPress block markup. Images are added separately through the images field, so do not write image markup here.', 'wp-mcp-connector' ) ),
						'excerpt'        => WPMCP_Schema::string( __( 'Short summary used in listings and as an SEO fallback.', 'wp-mcp-connector' ) ),
						'status'         => WPMCP_Schema::string( __( 'Defaults to draft. Only pass publish when the operator has asked for it to go live.', 'wp-mcp-connector' ), array( 'draft', 'publish', 'pending', 'private' ) ),
						'post_type'      => WPMCP_Schema::string( __( 'Defaults to post.', 'wp-mcp-connector' ) ),
						'categories'     => WPMCP_Schema::arr( __( 'Category names or slugs. Missing ones are created.', 'wp-mcp-connector' ), array( 'type' => 'string' ) ),
						'tags'           => WPMCP_Schema::arr( __( 'Tag names or slugs.', 'wp-mcp-connector' ), array( 'type' => 'string' ) ),
						'seo'            => WPMCP_SEO::schema(),
						'featured_image' => WPMCP_Schema::object(
							array(
								'url'           => WPMCP_Schema::string( __( 'Direct URL of the image file, for a photograph you found. The server downloads it. Must return an image, not a download page.', 'wp-mcp-connector' ) ),
								'base64'        => WPMCP_Schema::string( __( 'Raw image data as base64, for an image you hold rather than one that exists at a URL. If your tool layer truncates the argument before the whole string arrives, move the file with wp_begin_media_upload and pass the resulting upload_id here instead.', 'wp-mcp-connector' ) ),
								'upload_id'     => WPMCP_Schema::string( __( 'A chunked upload you already completed with wp_append_media_chunk but have not finished. This finishes it and uses the result.', 'wp-mcp-connector' ) ),
								'attachment_id' => WPMCP_Schema::integer( __( 'An image already in the media library. Nothing is downloaded; it is simply used.', 'wp-mcp-connector' ), 1 ),
								'alt'           => WPMCP_Schema::string( __( 'Alt text describing what is actually in the frame.', 'wp-mcp-connector' ) ),
								'filename' => WPMCP_Schema::string( __( 'Descriptive hyphenated filename without extension. Generated from the alt text when omitted.', 'wp-mcp-connector' ) ),
								'title'    => WPMCP_Schema::string( __( 'Attachment title for a human.', 'wp-mcp-connector' ) ),
								'credit'   => WPMCP_Schema::string( __( 'Who the photograph belongs to.', 'wp-mcp-connector' ) ),
								'license'  => WPMCP_Schema::string( __( 'The terms it is used under.', 'wp-mcp-connector' ) ),
							),
							array( 'alt' )
						),
						'images'         => WPMCP_Schema::arr(
							__( 'In-article images, placed in the body in the order given.', 'wp-mcp-connector' ),
							WPMCP_Schema::object(
								array(
									'url'             => WPMCP_Schema::string( __( 'Direct URL of the image file, for a photograph you found.', 'wp-mcp-connector' ) ),
									'base64'          => WPMCP_Schema::string( __( 'Raw image data as base64, for an image you hold rather than one that exists at a URL. If the argument gets truncated in transit, use wp_begin_media_upload and pass upload_id here instead.', 'wp-mcp-connector' ) ),
									'upload_id'       => WPMCP_Schema::string( __( 'A chunked upload to finish and use.', 'wp-mcp-connector' ) ),
									'attachment_id'   => WPMCP_Schema::integer( __( 'An image already in the media library.', 'wp-mcp-connector' ), 1 ),
									'alt'             => WPMCP_Schema::string( __( 'Alt text describing what is actually in the frame.', 'wp-mcp-connector' ) ),
									'after_paragraph' => WPMCP_Schema::integer( __( 'Place it after this many paragraphs, counting from 1. Omit to append at the end.', 'wp-mcp-connector' ), 1 ),
									'caption'         => WPMCP_Schema::string( __( 'Caption shown beneath the image.', 'wp-mcp-connector' ) ),
									'filename'        => WPMCP_Schema::string( __( 'Descriptive hyphenated filename without extension.', 'wp-mcp-connector' ) ),
									'title'           => WPMCP_Schema::string( __( 'Attachment title for a human.', 'wp-mcp-connector' ) ),
									'credit'          => WPMCP_Schema::string( __( 'Who the photograph belongs to.', 'wp-mcp-connector' ) ),
									'license'         => WPMCP_Schema::string( __( 'The terms it is used under.', 'wp-mcp-connector' ) ),
								),
								array( 'alt' )
							)
						),
					),
					array( 'title', 'content' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_update_seo_meta',
				'title'        => __( 'Update SEO metadata', 'wp-mcp-connector' ),
				'description'  => sprintf(
					/* translators: %s: detected SEO plugin slug. */
					__( 'Set the SEO title, meta description, canonical URL, robots directives and social card fields for a post or page. Writes through the site\'s active SEO plugin (detected: %s), so the values appear in that plugin\'s editor panel too. Fields you omit are left untouched.', 'wp-mcp-connector' ),
					WPMCP_SEO::backend()
				),
				'group'        => 'content',
				'capability'   => 'edit_posts',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'destructiveHint' => false,
					'idempotentHint'  => true,
				),
				'callback'     => array( $this, 'update_seo_meta' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'id'  => WPMCP_Schema::integer( __( 'Post ID.', 'wp-mcp-connector' ), 1 ),
						'seo' => WPMCP_SEO::schema(),
					),
					array( 'id', 'seo' )
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Tool callbacks
	 * ------------------------------------------------------------------ */

	/**
	 * Lists posts.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function list_posts( array $args ) {
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$page     = isset( $args['page'] ) ? (int) $args['page'] : 1;
		$status   = isset( $args['status'] ) ? $args['status'] : 'any';

		$query_args = array(
			'post_type'           => isset( $args['post_type'] ) ? $args['post_type'] : 'post',
			'post_status'         => 'any' === $status ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : $status,
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => isset( $args['orderby'] ) ? $args['orderby'] : 'date',
			'order'               => isset( $args['order'] ) ? $args['order'] : 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		if ( ! empty( $args['author'] ) ) {
			$query_args['author'] = (int) $args['author'];
		}

		if ( ! empty( $args['category'] ) ) {
			$query_args['category_name'] = $args['category'];
		}

		if ( ! empty( $args['tag'] ) ) {
			$query_args['tag'] = $args['tag'];
		}

		$query = new WP_Query( $query_args );
		$posts = array();

		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) && 'publish' !== $post->post_status ) {
				continue;
			}

			$posts[] = $this->format_post( $post, false );
		}

		return array(
			'posts' => $posts,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'page'  => $page,
		);
	}

	/**
	 * Gets one post in full.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_post( array $args ) {
		$post = $this->resolve_post( $args );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'wpmcp_forbidden', __( 'You do not have permission to read that post.', 'wp-mcp-connector' ) );
		}

		return $this->format_post( $post, true );
	}

	/**
	 * Creates a post.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_post( array $args ) {
		$post_type = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
		$type      = get_post_type_object( $post_type );

		if ( ! $type ) {
			return new WP_Error(
				'wpmcp_unknown_post_type',
				sprintf(
					/* translators: 1: requested post type, 2: available post types. */
					__( 'Unknown post type "%1$s". This site has: %2$s', 'wp-mcp-connector' ),
					$post_type,
					implode( ', ', $this->post_type_slugs() )
				)
			);
		}

		if ( ! current_user_can( $type->cap->create_posts ) ) {
			return new WP_Error(
				'wpmcp_forbidden',
				sprintf(
					/* translators: %s: post type label. */
					__( 'You do not have permission to create %s.', 'wp-mcp-connector' ),
					$type->labels->name
				)
			);
		}

		$status = isset( $args['status'] ) ? $args['status'] : 'draft';

		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
			return new WP_Error(
				'wpmcp_cannot_publish',
				__( 'You can create drafts but not publish. Create it as a draft and ask the site owner to review it.', 'wp-mcp-connector' )
			);
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_title'   => wp_strip_all_tags( (string) $args['title'] ),
			'post_content' => isset( $args['content'] ) ? $this->sanitize_content( $args['content'] ) : '',
			'post_excerpt' => isset( $args['excerpt'] ) ? sanitize_textarea_field( $args['excerpt'] ) : '',
			'post_status'  => $status,
			'post_author'  => get_current_user_id(),
		);

		$prepared = $this->apply_common_fields( $postarr, $args );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$post_id = wp_insert_post( $prepared, true );

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'wpmcp_insert_failed',
				sprintf(
					/* translators: %s: WordPress error message. */
					__( 'WordPress refused to create the post: %s', 'wp-mcp-connector' ),
					$post_id->get_error_message()
				)
			);
		}

		$applied = $this->apply_relations( $post_id, $args );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		return array(
			'created'   => true,
			'post'      => $this->format_post( get_post( $post_id ), false ),
			'edit_link' => get_edit_post_link( $post_id, 'raw' ),
			'notes'     => $applied,
		);
	}

	/**
	 * Updates a post.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update_post( array $args ) {
		$post = get_post( (int) $args['id'] );

		if ( ! $post ) {
			return new WP_Error(
				'wpmcp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'No post with ID %d. Use wp_list_posts or wp_search_content to find the right ID.', 'wp-mcp-connector' ),
					(int) $args['id']
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'wpmcp_forbidden',
				sprintf(
					/* translators: %d: post ID. */
					__( 'You do not have permission to edit post %d.', 'wp-mcp-connector' ),
					$post->ID
				)
			);
		}

		$postarr = array( 'ID' => $post->ID );

		if ( isset( $args['title'] ) ) {
			$postarr['post_title'] = wp_strip_all_tags( (string) $args['title'] );
		}

		if ( isset( $args['content'] ) ) {
			$postarr['post_content'] = $this->sanitize_content( $args['content'] );
		}

		if ( isset( $args['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $args['excerpt'] );
		}

		if ( isset( $args['status'] ) ) {
			$type = get_post_type_object( $post->post_type );

			if ( in_array( $args['status'], array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
				return new WP_Error( 'wpmcp_cannot_publish', __( 'You do not have permission to publish on this site.', 'wp-mcp-connector' ) );
			}

			$postarr['post_status'] = $args['status'];
		}

		$prepared = $this->apply_common_fields( $postarr, $args );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$result = wp_update_post( $prepared, true );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wpmcp_update_failed',
				sprintf(
					/* translators: %s: WordPress error message. */
					__( 'WordPress refused to update the post: %s', 'wp-mcp-connector' ),
					$result->get_error_message()
				)
			);
		}

		$applied = $this->apply_relations( $post->ID, $args );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		return array(
			'updated'   => true,
			'post'      => $this->format_post( get_post( $post->ID ), false ),
			'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
			'notes'     => $applied,
		);
	}

	/**
	 * Trashes or deletes a post.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete_post( array $args ) {
		$post_id = (int) $args['id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'wpmcp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'No post with ID %d.', 'wp-mcp-connector' ),
					$post_id
				)
			);
		}

		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return new WP_Error(
				'wpmcp_forbidden',
				sprintf(
					/* translators: %d: post ID. */
					__( 'You do not have permission to delete post %d.', 'wp-mcp-connector' ),
					$post_id
				)
			);
		}

		$force = ! empty( $args['force'] );
		$title = $post->post_title;

		if ( $force ) {
			$result = wp_delete_post( $post_id, true );
		} else {
			$result = wp_trash_post( $post_id );
		}

		if ( ! $result ) {
			return new WP_Error( 'wpmcp_delete_failed', __( 'WordPress could not remove that post. It may already be gone.', 'wp-mcp-connector' ) );
		}

		return array(
			'deleted'   => true,
			'permanent' => $force,
			'id'        => $post_id,
			'title'     => $title,
			'message'   => $force
				? __( 'Permanently deleted. This cannot be undone.', 'wp-mcp-connector' )
				: __( 'Moved to trash. It can be restored from the WordPress admin.', 'wp-mcp-connector' ),
		);
	}

	/**
	 * Searches content.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function search_content( array $args ) {
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 10;
		$status   = isset( $args['status'] ) ? $args['status'] : 'any';

		$query = new WP_Query(
			array(
				's'                   => (string) $args['query'],
				'post_type'           => isset( $args['post_type'] ) ? $args['post_type'] : 'any',
				'post_status'         => 'any' === $status ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : $status,
				'posts_per_page'      => $per_page,
				'ignore_sticky_posts' => true,
			)
		);

		$results = array();

		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$results[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'type'      => $post->post_type,
				'status'    => $post->post_status,
				'url'       => get_permalink( $post ),
				'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
				'snippet'   => $this->snippet( $post, (string) $args['query'] ),
			);
		}

		return array(
			'query'   => (string) $args['query'],
			'results' => $results,
			'total'   => (int) $query->found_posts,
		);
	}

	/**
	 * Builds a complete article, images and all, from one call.
	 *
	 * Exists because the alternative is eight round trips: create, upload,
	 * upload, insert, insert, rename, rename, SEO. Every one of those is a
	 * chance for a hosted client on a phone to stall halfway and leave a
	 * half-finished post behind. Doing the whole thing server-side means the
	 * operator either gets a finished article or gets told exactly what failed.
	 *
	 * Image failures are deliberately not fatal. A photograph that will not
	 * download is a fixable annoyance; losing the article that was written
	 * around it is not.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function publish_article( array $args ) {
		$media = new WPMCP_Tools_Media();

		$created = $this->create_post(
			array_filter(
				array(
					'post_type'  => isset( $args['post_type'] ) ? $args['post_type'] : 'post',
					'title'      => $args['title'],
					'content'    => $args['content'],
					'excerpt'    => isset( $args['excerpt'] ) ? $args['excerpt'] : '',
					'status'     => isset( $args['status'] ) ? $args['status'] : 'draft',
					'categories' => isset( $args['categories'] ) ? $args['categories'] : null,
					'tags'       => isset( $args['tags'] ) ? $args['tags'] : null,
				),
				static function ( $value ) {
					return null !== $value && '' !== $value;
				}
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$post_id  = (int) $created['post']['id'];
		$problems = array();
		$added    = array();

		/**
		 * Fetches one image and records its provenance.
		 *
		 * @param array<string,mixed> $spec Image specification.
		 * @return int|WP_Error Attachment ID.
		 */
		$fetch = function ( array $spec ) use ( $media, $post_id ) {
			// An image already in the library needs nothing fetched. Its alt and
			// provenance are still refreshed, because the caller has just told us
			// what the picture is and that is worth recording even when the bytes
			// were already here.
			if ( ! empty( $spec['attachment_id'] ) ) {
				$existing = (int) $spec['attachment_id'];

				if ( 'attachment' !== get_post_type( $existing ) ) {
					return new WP_Error( 'wpmcp_not_found', sprintf( __( 'No attachment with ID %d.', 'wp-mcp-connector' ), $existing ) );
				}

				$media->update_media(
					array_filter(
						array(
							'id'       => $existing,
							'alt_text' => $spec['alt'],
							'caption'  => isset( $spec['caption'] ) ? $spec['caption'] : '',
							'credit'   => isset( $spec['credit'] ) ? $spec['credit'] : '',
							'license'  => isset( $spec['license'] ) ? $spec['license'] : '',
						),
						static function ( $value ) {
							return '' !== $value && null !== $value;
						}
					)
				);

				return $existing;
			}

			// A chunked transfer that reached the end but was never finalised.
			if ( ! empty( $spec['upload_id'] ) ) {
				$done = $media->finish_media_upload( array( 'upload_id' => $spec['upload_id'] ) );

				if ( is_wp_error( $done ) ) {
					return $done;
				}

				$id = (int) $done['media']['id'];

				$media->update_media(
					array_filter(
						array(
							'id'       => $id,
							'alt_text' => $spec['alt'],
							'caption'  => isset( $spec['caption'] ) ? $spec['caption'] : '',
							'credit'   => isset( $spec['credit'] ) ? $spec['credit'] : '',
							'license'  => isset( $spec['license'] ) ? $spec['license'] : '',
						),
						static function ( $value ) {
							return '' !== $value && null !== $value;
						}
					)
				);

				return $id;
			}

			if ( empty( $spec['url'] ) && empty( $spec['base64'] ) ) {
				return new WP_Error(
					'wpmcp_no_image_source',
					__( 'That image has no source. Give one of url, base64, upload_id or attachment_id.', 'wp-mcp-connector' )
				);
			}

			// A descriptive filename is the one SEO field that cannot be fixed
			// later without breaking URLs, so derive one from the alt text when
			// the caller has not supplied it rather than keeping whatever the
			// source host happened to call the file.
			$filename = ! empty( $spec['filename'] )
				? sanitize_title( preg_replace( '/\.[a-z0-9]+$/i', '', $spec['filename'] ) )
				: sanitize_title( mb_substr( $spec['alt'], 0, 60 ) );

			$result = $media->upload_media(
				array_filter(
					array(
						'url'         => isset( $spec['url'] ) ? $spec['url'] : '',
						'base64_data' => isset( $spec['base64'] ) ? $spec['base64'] : '',
						'filename'    => $filename,
						'alt_text' => $spec['alt'],
						'title'    => isset( $spec['title'] ) ? $spec['title'] : $spec['alt'],
						'caption'  => isset( $spec['caption'] ) ? $spec['caption'] : '',
						'credit'   => isset( $spec['credit'] ) ? $spec['credit'] : '',
						'license'  => isset( $spec['license'] ) ? $spec['license'] : '',
						'post_id'  => $post_id,
					),
					static function ( $value ) {
						return '' !== $value && null !== $value;
					}
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return (int) $result['media']['id'];
		};

		$featured = isset( $args['featured_image'] ) ? (array) $args['featured_image'] : array();

		if ( ! empty( $featured['url'] ) || ! empty( $featured['base64'] ) || ! empty( $featured['upload_id'] ) || ! empty( $featured['attachment_id'] ) ) {
			$id = $fetch( $featured );

			if ( is_wp_error( $id ) ) {
				$problems[] = array(
					'image'  => isset( $featured['url'] ) ? $featured['url'] : 'supplied directly',
					'role'   => 'featured',
					'reason' => $id->get_error_message(),
				);
			} else {
				set_post_thumbnail( $post_id, $id );
				$added[] = array(
					'id'   => $id,
					'role' => 'featured',
				);
			}
		}

		foreach ( (array) ( isset( $args['images'] ) ? $args['images'] : array() ) as $spec ) {
			// Any of the four sources counts. Testing only for a url silently
			// dropped generated and already-uploaded images, which is worse than
			// failing: the article came back reporting no problems.
			if ( empty( $spec['url'] ) && empty( $spec['base64'] ) && empty( $spec['upload_id'] ) && empty( $spec['attachment_id'] ) ) {
				continue;
			}

			$id = $fetch( $spec );

			if ( is_wp_error( $id ) ) {
				$problems[] = array(
					'image'  => $spec['url'],
					'role'   => 'in-article',
					'reason' => $id->get_error_message(),
				);
				continue;
			}

			$placed = $media->insert_media_into_post(
				array_filter(
					array(
						'post_id'         => $post_id,
						'attachment_id'   => $id,
						'position'        => isset( $spec['after_paragraph'] ) ? 'after_paragraph' : 'end',
						'after_paragraph' => isset( $spec['after_paragraph'] ) ? (int) $spec['after_paragraph'] : null,
						'caption'         => isset( $spec['caption'] ) ? $spec['caption'] : '',
					),
					static function ( $value ) {
						return null !== $value && '' !== $value;
					}
				)
			);

			$added[] = array(
				'id'        => $id,
				'role'      => 'in-article',
				'placed_at' => is_wp_error( $placed ) ? 'failed' : $placed['placed_at'],
			);
		}

		$seo_written = array();

		if ( ! empty( $args['seo'] ) && is_array( $args['seo'] ) ) {
			$seo = WPMCP_SEO::update( $post_id, $args['seo'] );

			if ( $seo ) {
				$seo_written = $seo;
			}
		}

		$post = get_post( $post_id );

		return array(
			'created'        => true,
			'id'             => $post_id,
			'status'         => $post->post_status,
			'url'            => get_permalink( $post_id ),
			'edit_link'      => get_edit_post_link( $post_id, 'raw' ),
			'word_count'     => str_word_count( wp_strip_all_tags( $post->post_content ) ),
			'images_added'   => $added,
			'featured_image' => get_post_thumbnail_id( $post_id ) ? (int) get_post_thumbnail_id( $post_id ) : null,
			'seo_fields'     => $seo_written,
			'problems'       => $problems,
			'summary'        => $problems
				? sprintf(
					/* translators: 1: images added, 2: images that failed. */
					__( 'Article created with %1$d image(s). %2$d could not be fetched: see problems for the reason on each. Everything else was written.', 'wp-mcp-connector' ),
					count( $added ),
					count( $problems )
				)
				: sprintf(
					/* translators: %d: number of images. */
					__( 'Article created with %d image(s), SEO written, nothing outstanding.', 'wp-mcp-connector' ),
					count( $added )
				),
		);
	}

	/**
	 * Updates SEO metadata only.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update_seo_meta( array $args ) {
		$post_id = (int) $args['id'];

		if ( ! get_post( $post_id ) ) {
			return new WP_Error(
				'wpmcp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'No post with ID %d.', 'wp-mcp-connector' ),
					$post_id
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'wpmcp_forbidden', __( 'You do not have permission to edit that post.', 'wp-mcp-connector' ) );
		}

		$written = WPMCP_SEO::update( $post_id, (array) $args['seo'] );

		if ( ! $written ) {
			return new WP_Error(
				'wpmcp_seo_no_fields',
				sprintf(
					/* translators: %s: comma separated field names. */
					__( 'None of those fields are supported by the active SEO plugin. Supported fields: %s', 'wp-mcp-connector' ),
					implode( ', ', WPMCP_SEO::fields() )
				)
			);
		}

		return array(
			'updated' => true,
			'id'      => $post_id,
			'backend' => WPMCP_SEO::backend(),
			'fields'  => $written,
			'seo'     => WPMCP_SEO::get( $post_id ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The writable field schema shared by create and update.
	 *
	 * @param string[] $post_types Registered post type slugs.
	 * @param bool     $for_create Whether this is the create variant.
	 * @return array<string,array<string,mixed>>
	 */
	private function writable_properties( array $post_types, $for_create ) {
		$properties = array(
			'title'              => WPMCP_Schema::string( __( 'The post title, as plain text.', 'wp-mcp-connector' ) ),
			'content'            => WPMCP_Schema::string( __( 'The full body. Use WordPress block markup for the block editor, or HTML for the classic editor. This replaces the existing body entirely.', 'wp-mcp-connector' ) ),
			'excerpt'            => WPMCP_Schema::string( __( 'Short summary used in listings and as an SEO fallback.', 'wp-mcp-connector' ) ),
			'status'             => WPMCP_Schema::string( __( 'Publication status. Defaults to draft on create.', 'wp-mcp-connector' ), array( 'draft', 'publish', 'pending', 'private', 'future' ) ),
			'slug'               => WPMCP_Schema::string( __( 'URL slug. Generated from the title when omitted.', 'wp-mcp-connector' ) ),
			'date'               => WPMCP_Schema::string( __( 'Publish date in site local time, as YYYY-MM-DD HH:MM:SS. Required when status is future.', 'wp-mcp-connector' ) ),
			'author'             => WPMCP_Schema::integer( __( 'Author user ID. Requires the capability to edit other people\'s posts.', 'wp-mcp-connector' ), 1 ),
			'categories'         => WPMCP_Schema::arr( __( 'Category names or slugs. Missing categories are created.', 'wp-mcp-connector' ), array( 'type' => 'string' ) ),
			'tags'               => WPMCP_Schema::arr( __( 'Tag names or slugs. Missing tags are created.', 'wp-mcp-connector' ), array( 'type' => 'string' ) ),
			'featured_image_id'  => WPMCP_Schema::integer( __( 'Attachment ID to use as the featured image. Use wp_upload_media or wp_list_media to get one.', 'wp-mcp-connector' ), 1 ),
			'parent'             => WPMCP_Schema::integer( __( 'Parent post ID, for hierarchical types such as pages.', 'wp-mcp-connector' ), 0 ),
			'menu_order'         => WPMCP_Schema::integer( __( 'Sort order for hierarchical types.', 'wp-mcp-connector' ) ),
			'template'           => WPMCP_Schema::string( __( 'Page template file name, for example template-wide.php.', 'wp-mcp-connector' ) ),
			'comment_status'     => WPMCP_Schema::string( __( 'Whether comments are open.', 'wp-mcp-connector' ), array( 'open', 'closed' ) ),
			'seo'                => WPMCP_SEO::schema(),
		);

		if ( $for_create ) {
			$properties = array_merge(
				array( 'post_type' => WPMCP_Schema::string( __( 'Post type to create. Defaults to post.', 'wp-mcp-connector' ), $post_types ) ),
				$properties
			);
		}

		return $properties;
	}

	/**
	 * Applies scalar fields shared by create and update onto a postarr.
	 *
	 * @param array<string,mixed> $postarr Post array being built.
	 * @param array<string,mixed> $args    Tool arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	private function apply_common_fields( array $postarr, array $args ) {
		if ( isset( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
		}

		if ( isset( $args['date'] ) ) {
			$timestamp = strtotime( (string) $args['date'] );

			if ( ! $timestamp ) {
				return new WP_Error( 'wpmcp_bad_date', __( 'Could not read that date. Use the format YYYY-MM-DD HH:MM:SS.', 'wp-mcp-connector' ) );
			}

			$postarr['post_date']     = gmdate( 'Y-m-d H:i:s', $timestamp );
			$postarr['post_date_gmt'] = get_gmt_from_date( $postarr['post_date'] );
		}

		if ( isset( $args['author'] ) ) {
			if ( ! current_user_can( 'edit_others_posts' ) ) {
				return new WP_Error( 'wpmcp_forbidden', __( 'You cannot assign posts to another author.', 'wp-mcp-connector' ) );
			}

			if ( ! get_user_by( 'id', (int) $args['author'] ) ) {
				return new WP_Error( 'wpmcp_unknown_user', __( 'That author ID does not exist. Use wp_list_users to find one.', 'wp-mcp-connector' ) );
			}

			$postarr['post_author'] = (int) $args['author'];
		}

		if ( isset( $args['parent'] ) ) {
			$postarr['post_parent'] = (int) $args['parent'];
		}

		if ( isset( $args['menu_order'] ) ) {
			$postarr['menu_order'] = (int) $args['menu_order'];
		}

		if ( isset( $args['comment_status'] ) ) {
			$postarr['comment_status'] = $args['comment_status'];
		}

		return $postarr;
	}

	/**
	 * Applies terms, featured image, template and SEO after the post exists.
	 *
	 * Returns notes rather than failing hard: a post that saved but whose
	 * featured image ID was wrong should still be reported as saved, with the
	 * problem stated plainly so the model can fix it.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $args    Tool arguments.
	 * @return string[]|WP_Error
	 */
	private function apply_relations( $post_id, array $args ) {
		$notes = array();

		if ( isset( $args['categories'] ) ) {
			$ids = $this->resolve_terms( (array) $args['categories'], 'category' );

			if ( is_wp_error( $ids ) ) {
				return $ids;
			}

			wp_set_post_terms( $post_id, $ids, 'category' );
		}

		if ( isset( $args['tags'] ) ) {
			$ids = $this->resolve_terms( (array) $args['tags'], 'post_tag' );

			if ( is_wp_error( $ids ) ) {
				return $ids;
			}

			wp_set_post_terms( $post_id, $ids, 'post_tag' );
		}

		if ( isset( $args['featured_image_id'] ) ) {
			$attachment_id = (int) $args['featured_image_id'];

			if ( 'attachment' === get_post_type( $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
			} else {
				/* translators: %d: attachment ID. */
				$notes[] = sprintf( __( 'Attachment %d does not exist, so no featured image was set.', 'wp-mcp-connector' ), $attachment_id );
			}
		}

		if ( isset( $args['template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $args['template'] ) );
		}

		if ( isset( $args['seo'] ) && is_array( $args['seo'] ) ) {
			$written = WPMCP_SEO::update( $post_id, $args['seo'] );

			if ( ! $written ) {
				$notes[] = __( 'No SEO fields were written; the active SEO plugin does not support the fields provided.', 'wp-mcp-connector' );
			}
		}

		return $notes;
	}

	/**
	 * Resolves term names or slugs to IDs, creating any that are missing.
	 *
	 * @param string[] $terms    Term names, slugs or numeric IDs.
	 * @param string   $taxonomy Taxonomy.
	 * @return int[]|WP_Error
	 */
	private function resolve_terms( array $terms, $taxonomy ) {
		$ids = array();

		foreach ( $terms as $value ) {
			$value = trim( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			if ( ctype_digit( $value ) && term_exists( (int) $value, $taxonomy ) ) {
				$ids[] = (int) $value;
				continue;
			}

			$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );

			if ( ! $term ) {
				$term = get_term_by( 'name', $value, $taxonomy );
			}

			if ( $term ) {
				$ids[] = (int) $term->term_id;
				continue;
			}

			if ( ! current_user_can( 'manage_categories' ) ) {
				return new WP_Error(
					'wpmcp_cannot_create_term',
					sprintf(
						/* translators: 1: term name, 2: taxonomy name. */
						__( 'The term "%1$s" does not exist in %2$s and you do not have permission to create it. Use one of the existing terms from wp_list_terms.', 'wp-mcp-connector' ),
						$value,
						$taxonomy
					)
				);
			}

			$created = wp_insert_term( $value, $taxonomy );

			if ( is_wp_error( $created ) ) {
				return new WP_Error(
					'wpmcp_term_failed',
					sprintf(
						/* translators: 1: term name, 2: error message. */
						__( 'Could not create the term "%1$s": %2$s', 'wp-mcp-connector' ),
						$value,
						$created->get_error_message()
					)
				);
			}

			$ids[] = (int) $created['term_id'];
		}

		return $ids;
	}

	/**
	 * Finds a post from an id, slug or permalink argument.
	 *
	 * @param array<string,mixed> $args Tool arguments.
	 * @return WP_Post|WP_Error
	 */
	private function resolve_post( array $args ) {
		if ( ! empty( $args['id'] ) ) {
			$post = get_post( (int) $args['id'] );

			if ( $post ) {
				return $post;
			}

			return new WP_Error(
				'wpmcp_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'No post with ID %d. Use wp_search_content to find the right one.', 'wp-mcp-connector' ),
					(int) $args['id']
				)
			);
		}

		if ( ! empty( $args['slug'] ) ) {
			$slug = (string) $args['slug'];

			if ( false !== strpos( $slug, '://' ) ) {
				$post_id = url_to_postid( $slug );

				if ( $post_id ) {
					return get_post( $post_id );
				}

				$path = (string) wp_parse_url( $slug, PHP_URL_PATH );
				$slug = basename( untrailingslashit( $path ) );
			}

			$query = new WP_Query(
				array(
					'name'                => sanitize_title( $slug ),
					'post_type'           => 'any',
					'post_status'         => 'any',
					'posts_per_page'      => 1,
					'ignore_sticky_posts' => true,
				)
			);

			if ( $query->posts ) {
				return $query->posts[0];
			}

			return new WP_Error(
				'wpmcp_not_found',
				sprintf(
					/* translators: %s: slug. */
					__( 'Nothing found at the slug "%s".', 'wp-mcp-connector' ),
					$slug
				)
			);
		}

		return new WP_Error( 'wpmcp_missing_argument', __( 'Pass either an id or a slug.', 'wp-mcp-connector' ) );
	}

	/**
	 * Serialises a post for the wire.
	 *
	 * @param WP_Post $post         Post.
	 * @param bool    $with_content Whether to include the body and metadata.
	 * @return array<string,mixed>
	 */
	private function format_post( WP_Post $post, $with_content ) {
		$data = array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'slug'      => $post->post_name,
			'url'       => get_permalink( $post ),
			'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
			'date'      => $post->post_date,
			'modified'  => $post->post_modified,
			'author'    => array(
				'id'   => (int) $post->post_author,
				'name' => get_the_author_meta( 'display_name', $post->post_author ),
			),
			'excerpt'   => $post->post_excerpt,
		);

		if ( ! $with_content ) {
			$data['word_count'] = str_word_count( wp_strip_all_tags( $post->post_content ) );

			return $data;
		}

		$data['content']        = $post->post_content;
		$data['word_count']     = str_word_count( wp_strip_all_tags( $post->post_content ) );
		$data['comment_status'] = $post->comment_status;
		$data['menu_order']     = (int) $post->menu_order;
		$data['parent']         = (int) $post->post_parent;
		$data['template']       = get_post_meta( $post->ID, '_wp_page_template', true );
		$data['seo']            = WPMCP_SEO::get( $post->ID );

		$thumbnail_id = get_post_thumbnail_id( $post->ID );

		$data['featured_image'] = $thumbnail_id
			? array(
				'id'  => (int) $thumbnail_id,
				'url' => wp_get_attachment_url( $thumbnail_id ),
				'alt' => get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
			)
			: null;

		$taxonomies = array();

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public && ! $taxonomy->show_ui ) {
				continue;
			}

			$terms = wp_get_post_terms( $post->ID, $taxonomy->name, array( 'fields' => 'names' ) );

			if ( ! is_wp_error( $terms ) && $terms ) {
				$taxonomies[ $taxonomy->name ] = $terms;
			}
		}

		$data['taxonomies'] = $taxonomies;

		return $data;
	}

	/**
	 * Builds a short match snippet around a search term.
	 *
	 * @param WP_Post $post  Post.
	 * @param string  $query Search term.
	 * @return string
	 */
	private function snippet( WP_Post $post, $query ) {
		$text     = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$text     = preg_replace( '/\s+/', ' ', (string) $text );
		$position = stripos( $text, $query );

		if ( false === $position ) {
			return mb_substr( $text, 0, 180 ) . ( mb_strlen( $text ) > 180 ? '…' : '' );
		}

		$start = max( 0, $position - 70 );

		return ( $start > 0 ? '…' : '' ) . mb_substr( $text, $start, 200 ) . '…';
	}

	/**
	 * Post types an MCP client may touch.
	 *
	 * Restricted to types with an editing UI, which keeps internal machinery
	 * such as revisions, menu items and block templates out of reach.
	 *
	 * @return string[]
	 */
	private function post_type_slugs() {
		$types = get_post_types(
			array(
				'show_ui' => true,
			),
			'names'
		);

		unset( $types['attachment'], $types['wp_block'], $types['wp_template'], $types['wp_template_part'], $types['wp_navigation'], $types['wp_font_family'], $types['wp_font_face'] );

		/**
		 * Filters the post types exposed to MCP clients.
		 *
		 * @param string[] $types Post type slugs.
		 */
		return array_values( apply_filters( 'wpmcp_exposed_post_types', array_values( $types ) ) );
	}

	/**
	 * Sanitises post body content.
	 *
	 * wp_kses_post would strip block comments and break the block editor, so
	 * content is only sanitised for users without unfiltered_html, matching how
	 * WordPress treats the same content coming from the editor.
	 *
	 * @param string $content Raw content.
	 * @return string
	 */
	private function sanitize_content( $content ) {
		$content = (string) $content;

		if ( current_user_can( 'unfiltered_html' ) ) {
			return $content;
		}

		return wp_kses_post( $content );
	}
}
