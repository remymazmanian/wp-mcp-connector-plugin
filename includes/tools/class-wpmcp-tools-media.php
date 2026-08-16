<?php
/**
 * Media library tools.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists, uploads, edits and removes attachments.
 */
class WPMCP_Tools_Media {

	/**
	 * Cache key prefix for in-progress chunked uploads.
	 */
	const CHUNK_PREFIX = 'wpmcp_upload_';

	/**
	 * Where in-progress uploads live. An option, not a transient: see read_session.
	 */
	const OPTION_UPLOADS = 'wpmcp_uploads_in_progress';

	/**
	 * How long a half-finished upload survives before it is abandoned.
	 *
	 * Six hours, not thirty minutes. A client whose tool layer truncates every
	 * slice needs hundreds of round trips to move one photograph, and it will
	 * pause between them for reasons that have nothing to do with this server:
	 * the operator asks it something else, a turn ends, a rate limit bites.
	 * Expiring that work because nobody spoke for half an hour throws away an
	 * hour of grinding and forces a restart from zero, which is the one outcome
	 * the resumable protocol exists to prevent.
	 */
	const UPLOAD_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Recommended base64 characters per chunk.
	 *
	 * Sized to sit well inside the per-message output limit of every hosted
	 * client seen so far, while keeping an ordinary photograph to a handful of
	 * calls rather than dozens.
	 */
	const CHUNK_CHARS = 24000;

	/**
	 * Hard ceiling on chunks, so a stuck client cannot append forever.
	 */
	const MAX_CHUNKS = 4000;

	/**
	 * Registers the tools.
	 *
	 * @param WPMCP_Registry $registry Registry.
	 * @return void
	 */
	public function register( WPMCP_Registry $registry ) {
		$registry->add(
			array(
				'name'         => 'wp_list_media',
				'title'        => __( 'List media library items', 'wp-mcp-connector' ),
				'description'  => __( 'Browse or search the media library. Returns attachment IDs, URLs, dimensions, alt text and file sizes. Use this to find an existing image to reuse as a featured image before uploading a new one.', 'wp-mcp-connector' ),
				'group'        => 'media',
				'capability'   => 'upload_files',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'list_media' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'search'    => WPMCP_Schema::string( __( 'Match against filename, title, caption and alt text.', 'wp-mcp-connector' ) ),
						'mime_type' => WPMCP_Schema::string( __( 'Filter by MIME type or prefix, for example image or image/png.', 'wp-mcp-connector' ) ),
						'parent'    => WPMCP_Schema::integer( __( 'Only items attached to this post ID.', 'wp-mcp-connector' ), 0 ),
						'per_page'  => WPMCP_Schema::integer( __( 'Results per page, 1 to 100. Defaults to 20.', 'wp-mcp-connector' ), 1, 100 ),
						'page'      => WPMCP_Schema::integer( __( 'Page number, starting at 1.', 'wp-mcp-connector' ), 1 ),
					)
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_upload_media',
				'title'        => __( 'Upload a file to the media library', 'wp-mcp-connector' ),
				'description'  => __( 'Add a file to the media library. There are three routes and all of them work: pass url and the server downloads the file itself, which is a single call and costs you nothing to send; pass base64_data to send the bytes inline; or call wp_begin_media_upload to carry the same file across several calls, which has no size ceiling and resumes automatically if a call is cut short. Prefer url whenever the image is reachable at one, even a long signed one, because it is one call instead of several. If the only copy lives in your own session storage with no URL the public internet can reach, chunk it: do not give up and do not ask the user to upload it by hand. Always supply alt text for images: it is the single most useful field for accessibility and image SEO, and an inaccurate one is worse than none. Returns the attachment ID, which you can pass to wp_create_post or wp_update_post as featured_image_id.', 'wp-mcp-connector' ),
				'group'        => 'media',
				'capability'   => 'upload_files',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => false ),
				'callback'     => array( $this, 'upload_media' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'url'         => WPMCP_Schema::string( __( 'URL of the file for the server to download. The preferred route: it costs you nothing to send and handles files of any size. Signed and query-string URLs are fine as long as they resolve without a login.', 'wp-mcp-connector' ) ),
						'base64_data' => WPMCP_Schema::string( __( 'File contents as base64. A data: URI prefix is accepted and stripped. The server has no character limit worth worrying about here; the thing that fails is your own tool layer truncating the argument in transit. If that happens, send the same bytes through wp_begin_media_upload instead, which splits them across calls and resumes from wherever the last one stopped.', 'wp-mcp-connector' ) ),
						'filename'    => WPMCP_Schema::string( __( 'Filename to store it under, including extension. Required with base64_data. Use descriptive, hyphenated words rather than IMG_1234.jpg.', 'wp-mcp-connector' ) ),
						'title'       => WPMCP_Schema::string( __( 'Attachment title.', 'wp-mcp-connector' ) ),
						'alt_text'    => WPMCP_Schema::string( __( 'Alt text describing the image for screen readers and search engines.', 'wp-mcp-connector' ) ),
						'caption'     => WPMCP_Schema::string( __( 'Caption shown beneath the image.', 'wp-mcp-connector' ) ),
						'description' => WPMCP_Schema::string( __( 'Longer description stored on the attachment page.', 'wp-mcp-connector' ) ),
						'post_id'     => WPMCP_Schema::integer( __( 'Attach the upload to this post.', 'wp-mcp-connector' ), 1 ),
						'credit'      => WPMCP_Schema::string( __( 'Who the photograph belongs to, as it should be shown. For a real photograph this is not optional bookkeeping: it is what lets anyone check later that the image was used with permission.', 'wp-mcp-connector' ) ),
						'license'     => WPMCP_Schema::string( __( 'The terms it is used under, for example "press pack, editorial use", "Unsplash License", or "supplied by brand". Say what you actually know and nothing more.', 'wp-mcp-connector' ) ),
					)
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_begin_media_upload',
				'title'        => __( 'Start a chunked upload', 'wp-mcp-connector' ),
				'description'  => sprintf(
					/* translators: %d: recommended characters of base64 per chunk. */
					__( 'Begin uploading a file in pieces, for when you hold the bytes yourself and there is no URL the server can fetch. This is the route for an image you generated: it never exceeds your per-message output limit because each piece is a separate call. Call this once, then call wp_append_media_chunk repeatedly with roughly %d characters of base64 each, then call wp_finish_media_upload. Supply alt_text and title here rather than afterwards. Set post_id and set_featured to attach the finished image to a post in one flow.', 'wp-mcp-connector' ),
					self::CHUNK_CHARS
				),
				'group'        => 'media',
				'capability'   => 'upload_files',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => false ),
				'callback'     => array( $this, 'begin_media_upload' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'filename'     => WPMCP_Schema::string( __( 'Filename including extension. Use descriptive hyphenated words, not IMG_1234.jpg.', 'wp-mcp-connector' ) ),
						'title'        => WPMCP_Schema::string( __( 'Attachment title, written for a human and distinct from the filename.', 'wp-mcp-connector' ) ),
						'alt_text'     => WPMCP_Schema::string( __( 'Alt text describing what is actually in the frame.', 'wp-mcp-connector' ) ),
						'caption'      => WPMCP_Schema::string( __( 'Caption shown beneath the image.', 'wp-mcp-connector' ) ),
						'description'  => WPMCP_Schema::string( __( 'Longer description stored on the attachment page.', 'wp-mcp-connector' ) ),
						'post_id'      => WPMCP_Schema::integer( __( 'Attach the finished upload to this post.', 'wp-mcp-connector' ), 1 ),
						'set_featured' => WPMCP_Schema::boolean( __( 'Also set it as that post\'s featured image when the upload completes.', 'wp-mcp-connector' ) ),
						'sha256'       => WPMCP_Schema::string( __( 'Optional SHA-256 of the original file, lowercase hex. Supplied here, it is verified after assembly so a corrupted transfer fails loudly instead of storing a broken image.', 'wp-mcp-connector' ) ),
						'chunk_characters' => WPMCP_Schema::integer( __( 'How many base64 characters you want to send per chunk. Pick a size you can emit reliably in one message: larger means fewer round trips, smaller means less chance of a truncated call. Defaults to 24000. Anything from 500 upwards is accepted, so if your tool layer truncates large arguments, go small and send more calls rather than giving up.', 'wp-mcp-connector' ), 500, 400000 ),
					),
					array( 'filename' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_append_media_chunk',
				'title'        => __( 'Send one chunk of a file', 'wp-mcp-connector' ),
				'description'  => __( 'Append the next piece of a chunked upload. This is resumable by character offset, not by fixed chunk, so a truncated call is not a failure: the server keeps every character that arrived and the reply gives you next_offset, the exact position to continue from. Send the next slice starting there. Repeat until next_offset equals the total length of your base64 string, then call wp_finish_media_upload. You do not need to know the chunk size in advance and you never need to restart.', 'wp-mcp-connector' ),
				'group'        => 'media',
				'capability'   => 'upload_files',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => false ),
				'callback'     => array( $this, 'append_media_chunk' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'upload_id' => WPMCP_Schema::string( __( 'The id returned by wp_begin_media_upload.', 'wp-mcp-connector' ) ),
						'data'      => WPMCP_Schema::string( __( 'The next slice of the base64 string. Slice the base64 text itself, not the raw bytes. If your tool layer truncates it in transit that is fine: whatever arrives is kept, and the reply tells you the exact character to continue from.', 'wp-mcp-connector' ) ),
						'offset'    => WPMCP_Schema::integer( __( 'Optional. The base64 character position this slice starts at. Omit it and the slice is simply appended where the last one ended, which is what you want unless you are recovering from an error.', 'wp-mcp-connector' ), 0 ),
					),
					array( 'upload_id', 'data' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_media_upload_status',
				'title'        => __( 'Check a chunked upload', 'wp-mcp-connector' ),
				'description'  => __( 'Ask where an in-progress upload got to. Use this after any error or interruption instead of starting again: it reports the next chunk index expected and how many bytes are already stored, so you can resume from the right place rather than resending the whole file.', 'wp-mcp-connector' ),
				'group'        => 'media',
				'capability'   => 'upload_files',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'media_upload_status' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'upload_id' => WPMCP_Schema::string( __( 'The id returned by wp_begin_media_upload.', 'wp-mcp-connector' ) ),
					),
					array( 'upload_id' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_finish_media_upload',
				'title'        => __( 'Complete a chunked upload', 'wp-mcp-connector' ),
				'description'  => __( 'Assemble the chunks into the media library. Verifies the file really is the type its extension claims, and checks the SHA-256 if you supplied one. Returns the attachment ID, and sets the featured image if that was requested when the upload began.', 'wp-mcp-connector' ),
				'group'        => 'media',
				'capability'   => 'upload_files',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => false ),
				'callback'     => array( $this, 'finish_media_upload' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'upload_id' => WPMCP_Schema::string( __( 'The id returned by wp_begin_media_upload.', 'wp-mcp-connector' ) ),
					),
					array( 'upload_id' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_update_media',
				'title'        => __( 'Update media metadata', 'wp-mcp-connector' ),
				'description'  => __( 'Change the title, alt text, caption or description of an existing attachment. Useful for fixing missing alt text across the library without re-uploading anything.', 'wp-mcp-connector' ),
				'group'        => 'media',
				'capability'   => 'upload_files',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'destructiveHint' => false,
					'idempotentHint'  => true,
				),
				'callback'     => array( $this, 'update_media' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'id'          => WPMCP_Schema::integer( __( 'Attachment ID.', 'wp-mcp-connector' ), 1 ),
						'title'       => WPMCP_Schema::string( __( 'New title.', 'wp-mcp-connector' ) ),
						'alt_text'    => WPMCP_Schema::string( __( 'New alt text.', 'wp-mcp-connector' ) ),
						'caption'     => WPMCP_Schema::string( __( 'New caption.', 'wp-mcp-connector' ) ),
						'description' => WPMCP_Schema::string( __( 'New description.', 'wp-mcp-connector' ) ),
						'credit'      => WPMCP_Schema::string( __( 'Who the photograph belongs to.', 'wp-mcp-connector' ) ),
						'license'     => WPMCP_Schema::string( __( 'The terms it is used under.', 'wp-mcp-connector' ) ),
						'source_url'  => WPMCP_Schema::string( __( 'Where it came from.', 'wp-mcp-connector' ) ),
					),
					array( 'id' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_insert_media_into_post',
				'title'        => __( 'Place an image inside a post', 'wp-mcp-connector' ),
				'description'  => __( 'Insert an already-uploaded image into a post body as a proper WordPress image block, so it renders responsively and the editor treats it as a real image rather than pasted HTML. Position it at the end, at the start, or after a numbered paragraph. Alt text is taken from the attachment, so set that first with wp_update_media if it is missing. This is how an in-article image gets added; featured images are set separately with featured_image_id or set_featured.', 'wp-mcp-connector' ),
				'group'        => 'media',
				'capability'   => 'edit_posts',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => false ),
				'callback'     => array( $this, 'insert_media_into_post' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'post_id'         => WPMCP_Schema::integer( __( 'Post to insert into.', 'wp-mcp-connector' ), 1 ),
						'attachment_id'   => WPMCP_Schema::integer( __( 'Image to insert.', 'wp-mcp-connector' ), 1 ),
						'position'        => WPMCP_Schema::string( __( 'Where to place it. Defaults to end.', 'wp-mcp-connector' ), array( 'end', 'start', 'after_paragraph' ) ),
						'after_paragraph' => WPMCP_Schema::integer( __( 'With position after_paragraph: insert after this many paragraphs, counting from 1. Beyond the last paragraph it goes at the end.', 'wp-mcp-connector' ), 1 ),
						'size'            => WPMCP_Schema::string( __( 'Registered image size to render. Defaults to large.', 'wp-mcp-connector' ) ),
						'caption'         => WPMCP_Schema::string( __( 'Optional caption shown beneath the image.', 'wp-mcp-connector' ) ),
						'align'           => WPMCP_Schema::string( __( 'Optional block alignment.', 'wp-mcp-connector' ), array( 'none', 'wide', 'full' ) ),
					),
					array( 'post_id', 'attachment_id' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_rename_media',
				'title'        => __( 'Rename a media file', 'wp-mcp-connector' ),
				'description'  => __( 'Change the stored filename of an attachment, which is the one part of image SEO that cannot be fixed later without breaking URLs. Use it right after an upload to replace a machine-generated name with descriptive hyphenated words. This moves the file and every generated size on disk, and rewrites any post that referenced the old URL, so existing content does not break. Prefer getting the filename right at upload time; this exists for when you did not.', 'wp-mcp-connector' ),
				'group'        => 'media',
				'capability'   => 'upload_files',
				'profiles'     => array( 'author', 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => true ),
				'callback'     => array( $this, 'rename_media' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'id'       => WPMCP_Schema::integer( __( 'Attachment ID.', 'wp-mcp-connector' ), 1 ),
						'filename' => WPMCP_Schema::string( __( 'New filename. The extension is kept from the original and does not need repeating, but including it is fine. Use descriptive hyphenated words, for example clifftop-ceremony-at-sunset.', 'wp-mcp-connector' ) ),
					),
					array( 'id', 'filename' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_delete_media',
				'title'        => __( 'Delete a media item', 'wp-mcp-connector' ),
				'description'  => __( 'Permanently remove an attachment and its generated image sizes from disk. There is no trash for media, so this cannot be undone. Check with wp_list_posts whether the image is still in use before removing it, and confirm with the user first.', 'wp-mcp-connector' ),
				'group'        => 'media',
				'capability'   => 'delete_posts',
				'profiles'     => array( 'admin' ),
				'annotations'  => array( 'destructiveHint' => true ),
				'callback'     => array( $this, 'delete_media' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'id' => WPMCP_Schema::integer( __( 'Attachment ID to delete.', 'wp-mcp-connector' ), 1 ),
					),
					array( 'id' )
				),
			)
		);
	}

	/**
	 * Lists media.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>
	 */
	public function list_media( array $args ) {
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$page     = isset( $args['page'] ) ? (int) $args['page'] : 1;

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $args['search'] ) ) {
			$term = (string) $args['search'];

			// WP_Query's `s` searches post title, content and excerpt, and never
			// looks at the stored file path. That made a search for "post-204"
			// return nothing even though post-204-dress.webp existed, which is
			// exactly the lookup a client does before deciding whether to upload
			// a duplicate. Resolve both sets of IDs and query their union.
			global $wpdb;

			$by_text = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					's'              => $term,
				)
			);

			$by_file = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 200",
					'%' . $wpdb->esc_like( $term ) . '%'
				)
			);

			$matched = array_values( array_unique( array_map( 'intval', array_merge( (array) $by_text, (array) $by_file ) ) ) );

			// post__in with an empty array is ignored by WP_Query and would
			// return everything, so a search with no hits needs an impossible ID.
			$query_args['post__in'] = $matched ? $matched : array( 0 );
		}

		if ( ! empty( $args['mime_type'] ) ) {
			$query_args['post_mime_type'] = $args['mime_type'];
		}

		if ( isset( $args['parent'] ) ) {
			$query_args['post_parent'] = (int) $args['parent'];
		}

		$query = new WP_Query( $query_args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->format_attachment( $post->ID );
		}

		return array(
			'media' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'page'  => $page,
		);
	}

	/**
	 * Uploads a file.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function upload_media( array $args ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$has_url    = ! empty( $args['url'] );
		$has_base64 = ! empty( $args['base64_data'] );

		if ( $has_url === $has_base64 ) {
			return new WP_Error(
				'wpmcp_upload_source',
				__( 'Provide exactly one of url or base64_data.', 'wp-mcp-connector' )
			);
		}

		$max_bytes = (int) wpmcp()->settings()->get( 'max_upload_bytes' );

		if ( $has_url ) {
			$fetched = $this->fetch_url( (string) $args['url'], $max_bytes );
		} else {
			$fetched = $this->decode_base64( (string) $args['base64_data'], isset( $args['filename'] ) ? (string) $args['filename'] : '', $max_bytes );
		}

		if ( is_wp_error( $fetched ) ) {
			return $fetched;
		}

		$filename = ! empty( $args['filename'] ) ? sanitize_file_name( (string) $args['filename'] ) : $fetched['filename'];

		// Preserve the real extension when the caller supplied a name without one.
		if ( ! pathinfo( $filename, PATHINFO_EXTENSION ) && pathinfo( $fetched['filename'], PATHINFO_EXTENSION ) ) {
			$filename .= '.' . pathinfo( $fetched['filename'], PATHINFO_EXTENSION );
		}

		// Still nothing, which means neither the caller nor the source named a
		// type. Sniff the bytes rather than handing WordPress a bare word.
		if ( ! pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			$sniffed = wp_check_filetype_and_ext( $fetched['tmp'], $filename . '.jpg' );

			if ( ! empty( $sniffed['ext'] ) ) {
				$filename .= '.' . $sniffed['ext'];
			}
		}

		// A URL upload knows its own origin, so record it without being asked.
		if ( $has_url && empty( $args['source_url'] ) ) {
			$args['source_url'] = (string) $args['url'];
		}

		return $this->store_file( $fetched['tmp'], $filename, $args );
	}

	/* ---------------------------------------------------------------------
	 * Chunked upload
	 *
	 * The only route that works when a client holds image bytes it cannot
	 * publish at a URL. A photograph base64-encodes to far more characters
	 * than a hosted model can emit in one message, but nothing stops it
	 * emitting them across several, so the transfer is split at the call
	 * boundary and reassembled on disk here.
	 * ------------------------------------------------------------------ */

	/**
	 * Opens a chunked upload.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function begin_media_upload( array $args ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$filename = sanitize_file_name( (string) $args['filename'] );

		if ( '' === $filename || ! pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			return new WP_Error(
				'wpmcp_bad_filename',
				__( 'Provide a filename with an extension, for example harbor-bridge-at-dusk.webp. The extension decides how WordPress stores and serves the file.', 'wp-mcp-connector' )
			);
		}

		$sha = isset( $args['sha256'] ) ? strtolower( trim( (string) $args['sha256'] ) ) : '';

		if ( '' !== $sha && ! preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
			return new WP_Error( 'wpmcp_bad_checksum', __( 'sha256 must be 64 lowercase hex characters, or omitted.', 'wp-mcp-connector' ) );
		}

		$tmp = wp_tempnam( $filename );

		if ( ! $tmp ) {
			return new WP_Error( 'wpmcp_tempfile', __( 'Could not open a temporary file. Check that the server temp directory is writable.', 'wp-mcp-connector' ) );
		}

		// Start empty: wp_tempnam creates the file, and chunks are appended.
		file_put_contents( $tmp, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$chunk_chars = isset( $args['chunk_characters'] ) ? (int) $args['chunk_characters'] : self::CHUNK_CHARS;
		$chunk_chars = max( 500, min( 400000, $chunk_chars ) );

		$upload_id = bin2hex( random_bytes( 8 ) );

		$this->write_session(
			$upload_id,
			array(
				'user_id'      => get_current_user_id(),
				'filename'     => $filename,
				'tmp'          => $tmp,
				'bytes'        => 0,
				'next_index'   => 0,
				'sha256'       => $sha,
				'title'        => isset( $args['title'] ) ? (string) $args['title'] : '',
				'alt_text'     => isset( $args['alt_text'] ) ? (string) $args['alt_text'] : '',
				'caption'      => isset( $args['caption'] ) ? (string) $args['caption'] : '',
				'description'  => isset( $args['description'] ) ? (string) $args['description'] : '',
				'post_id'      => isset( $args['post_id'] ) ? (int) $args['post_id'] : 0,
				'set_featured' => ! empty( $args['set_featured'] ),
				'chunk_chars'  => $chunk_chars,
				'b64_received' => 0,
				'b64_tail'     => '',
			)
		);

		return array(
			'upload_id'                    => $upload_id,
			'next_offset'                  => 0,
			'suggested_chunk_characters'   => $chunk_chars,
			'max_bytes'                    => (int) wpmcp()->settings()->get( 'max_upload_bytes' ),
			'expires_in'                   => self::UPLOAD_TTL,
			'next_step'                    => __( 'Call wp_append_media_chunk with your first slice of base64. If it gets truncated in transit the reply still tells you where to continue, so nothing is wasted.', 'wp-mcp-connector' ),
		);
	}

	/**
	 * Appends one chunk.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function append_media_chunk( array $args ) {
		$session = $this->get_upload_session( $args['upload_id'] );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$data = preg_replace( '/\s+/', '', (string) $args['data'] );

		// Anything that is not base64 is transport damage rather than content,
		// so drop it instead of failing the transfer over a stray character.
		$data = preg_replace( '#[^A-Za-z0-9+/=]#', '', (string) $data );

		if ( '' === $data ) {
			return new WP_Error(
				'wpmcp_empty_chunk',
				sprintf(
					/* translators: %d: character offset. */
					__( 'That slice arrived empty. Resend starting at character %d.', 'wp-mcp-connector' ),
					(int) $session['b64_received']
				)
			);
		}

		// The client may state where its slice begins. If it is behind what the
		// server already holds, the overlap is trimmed rather than rejected:
		// re-sending material after a truncated call is the normal case here,
		// not an error worth stopping for.
		if ( isset( $args['offset'] ) ) {
			$offset   = (int) $args['offset'];
			$received = (int) $session['b64_received'];

			if ( $offset > $received ) {
				return new WP_Error(
					'wpmcp_offset_gap',
					sprintf(
						/* translators: 1: offset sent, 2: offset held. */
						__( 'That slice starts at character %1$d but the server only holds %2$d, so accepting it would leave a hole. Resend starting at character %2$d.', 'wp-mcp-connector' ),
						$offset,
						$received
					)
				);
			}

			if ( $offset < $received ) {
				$skip = $received - $offset;

				if ( $skip >= strlen( $data ) ) {
					return array(
						'accepted_characters' => 0,
						'duplicate'           => true,
						'next_offset'         => $received,
						'bytes_received'      => (int) $session['bytes'],
						'human_size'          => size_format( $session['bytes'] ),
						'next_step'           => __( 'The server already had all of that. Continue from next_offset.', 'wp-mcp-connector' ),
					);
				}

				$data = substr( $data, $skip );
			}
		}

		$max = (int) wpmcp()->settings()->get( 'max_upload_bytes' );

		// Base64 decodes four characters to three bytes, so only a multiple of
		// four can be decoded now. Whatever is left over is carried forward and
		// prepended to the next slice, which is what makes an arbitrary cut in
		// the middle of the stream harmless.
		$pending   = $session['b64_tail'] . $data;
		$decodable = strlen( $pending ) - ( strlen( $pending ) % 4 );
		$ready     = substr( $pending, 0, $decodable );
		$tail      = substr( $pending, $decodable );

		if ( '' !== $ready ) {
			$binary = base64_decode( $ready, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			if ( false === $binary ) {
				return new WP_Error(
					'wpmcp_bad_chunk',
					sprintf(
						/* translators: %d: character offset. */
						__( 'That slice is not valid base64 once joined to what came before. Nothing was stored. Resend starting at character %d.', 'wp-mcp-connector' ),
						(int) $session['b64_received']
					)
				);
			}

			if ( $session['bytes'] + strlen( $binary ) > $max ) {
				$this->discard_upload_session( $args['upload_id'], $session );

				return new WP_Error(
					'wpmcp_too_large',
					sprintf(
						/* translators: %s: size limit. */
						__( 'This upload passed the %s limit and was discarded.', 'wp-mcp-connector' ),
						size_format( $max )
					)
				);
			}

			if ( false === file_put_contents( $session['tmp'], $binary, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return new WP_Error( 'wpmcp_write_failed', __( 'Could not write that slice to disk.', 'wp-mcp-connector' ) );
			}

			$session['bytes'] += strlen( $binary );
		}

		$session['b64_received'] += strlen( $data );
		$session['b64_tail']      = $tail;

		$this->write_session( $args['upload_id'], $session );

		return array(
			'accepted_characters' => strlen( $data ),
			'next_offset'         => (int) $session['b64_received'],
			'bytes_received'      => (int) $session['bytes'],
			'human_size'          => size_format( $session['bytes'] ),
			'next_step'           => __( 'Send the next slice starting at next_offset. When next_offset equals the length of your base64 string, call wp_finish_media_upload.', 'wp-mcp-connector' ),
		);
	}

	/**
	 * Assembles and stores a completed upload.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function finish_media_upload( array $args ) {
		$session = $this->get_upload_session( $args['upload_id'] );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( $session['bytes'] < 1 ) {
			$this->discard_upload_session( $args['upload_id'], $session );

			return new WP_Error( 'wpmcp_empty_upload', __( 'Nothing was received, so there is nothing to store.', 'wp-mcp-connector' ) );
		}

		// Leftover characters mean the base64 string was cut mid-quartet and the
		// last slice never arrived. Say so with the offset to resume from rather
		// than storing a file that is short by a byte or two.
		if ( '' !== $session['b64_tail'] ) {
			return new WP_Error(
				'wpmcp_incomplete_upload',
				sprintf(
					/* translators: 1: leftover characters, 2: offset to resume from. */
					__( 'The transfer stops mid-way through a base64 group: %1$d characters are left over with nothing to pair them with. The file was not stored and nothing was lost. Send the rest starting at character %2$d, then call this again.', 'wp-mcp-connector' ),
					strlen( $session['b64_tail'] ),
					(int) $session['b64_received']
				)
			);
		}

		if ( '' !== $session['sha256'] ) {
			$actual = hash_file( 'sha256', $session['tmp'] );

			if ( ! hash_equals( $session['sha256'], (string) $actual ) ) {
				$this->discard_upload_session( $args['upload_id'], $session );

				return new WP_Error(
					'wpmcp_checksum_mismatch',
					sprintf(
						/* translators: 1: expected checksum, 2: computed checksum. */
						__( 'The assembled file does not match the checksum you supplied, so it was discarded rather than stored. Expected %1$s, got %2$s. Start a new upload.', 'wp-mcp-connector' ),
						$session['sha256'],
						$actual
					)
				);
			}
		}

		$this->forget_session( $args['upload_id'] );

		$stored = $this->store_file( $session['tmp'], $session['filename'], $session );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$attachment_id = (int) $stored['media']['id'];

		$stored['base64_characters_received'] = (int) $session['b64_received'];

		if ( $session['post_id'] && $session['set_featured'] ) {
			if ( current_user_can( 'edit_post', $session['post_id'] ) ) {
				set_post_thumbnail( $session['post_id'], $attachment_id );
				$stored['featured_image_set_on'] = $session['post_id'];
			} else {
				$stored['warning'] = __( 'Uploaded, but the featured image was not set: you cannot edit that post.', 'wp-mcp-connector' );
			}
		}

		return $stored;
	}

	/**
	 * Reports progress on an in-progress upload.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function media_upload_status( array $args ) {
		$session = $this->get_upload_session( $args['upload_id'] );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return array(
			'upload_id'        => (string) $args['upload_id'],
			'filename'         => $session['filename'],
			'next_offset'      => (int) $session['b64_received'],
			'bytes_received'   => (int) $session['bytes'],
			'human_size'       => size_format( $session['bytes'] ),
			'next_step'        => __( 'Send wp_append_media_chunk starting at next_offset, or call wp_finish_media_upload if the whole string is in.', 'wp-mcp-connector' ),
		);
	}

	/**
	 * Reads an upload session from durable storage.
	 *
	 * Sessions used to live in a transient. On a site with a persistent object
	 * cache that means Redis and nothing else, so a cache flush, a deploy purge
	 * or an eviction would throw away a transfer that had taken dozens of round
	 * trips to accumulate. The option survives all of it. A transient is still
	 * read as a fallback so an upload that began under the old code finishes
	 * under the new one instead of dying at the deploy.
	 *
	 * @param string $upload_id Upload id.
	 * @return array<string,mixed>|null
	 */
	private function read_session( $upload_id ) {
		$all = get_option( self::OPTION_UPLOADS, array() );

		if ( is_array( $all ) && isset( $all[ $upload_id ]['session'] ) ) {
			if ( $all[ $upload_id ]['expires'] < time() ) {
				return null;
			}

			return $all[ $upload_id ]['session'];
		}

		$legacy = get_transient( self::CHUNK_PREFIX . $upload_id );

		if ( is_array( $legacy ) ) {
			$this->write_session( $upload_id, $legacy );
			delete_transient( self::CHUNK_PREFIX . $upload_id );

			return $legacy;
		}

		return null;
	}

	/**
	 * Persists an upload session, collecting expired ones on the way.
	 *
	 * @param string              $upload_id Upload id.
	 * @param array<string,mixed> $session   Session record.
	 * @return void
	 */
	private function write_session( $upload_id, array $session ) {
		$all = get_option( self::OPTION_UPLOADS, array() );
		$all = is_array( $all ) ? $all : array();
		$now = time();

		foreach ( $all as $key => $row ) {
			if ( ! isset( $row['expires'] ) || $row['expires'] < $now ) {
				if ( ! empty( $row['session']['tmp'] ) && file_exists( $row['session']['tmp'] ) ) {
					@unlink( $row['session']['tmp'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}

				unset( $all[ $key ] );
			}
		}

		$all[ $upload_id ] = array(
			'session' => $session,
			'expires' => $now + self::UPLOAD_TTL,
		);

		update_option( self::OPTION_UPLOADS, $all, 'no' );
	}

	/**
	 * Forgets an upload session.
	 *
	 * @param string $upload_id Upload id.
	 * @return void
	 */
	private function forget_session( $upload_id ) {
		$all = get_option( self::OPTION_UPLOADS, array() );

		if ( is_array( $all ) && isset( $all[ $upload_id ] ) ) {
			unset( $all[ $upload_id ] );
			update_option( self::OPTION_UPLOADS, $all, 'no' );
		}

		delete_transient( self::CHUNK_PREFIX . $upload_id );
	}

	/**
	 * Loads and authorises an upload session.
	 *
	 * @param string $upload_id Upload id.
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_upload_session( $upload_id ) {
		$upload_id = (string) $upload_id;

		if ( ! preg_match( '/^[a-f0-9]{16}$/', $upload_id ) ) {
			return new WP_Error( 'wpmcp_bad_upload_id', __( 'That is not a valid upload id. Start with wp_begin_media_upload.', 'wp-mcp-connector' ) );
		}

		$session = $this->read_session( $upload_id );

		if ( ! is_array( $session ) ) {
			return new WP_Error(
				'wpmcp_unknown_upload',
				__( 'That upload is unknown or has expired. Uploads are abandoned after 30 minutes of inactivity. Start again with wp_begin_media_upload.', 'wp-mcp-connector' )
			);
		}

		// An upload belongs to the account that opened it, so one credential
		// cannot append to another's transfer.
		if ( (int) $session['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'wpmcp_forbidden', __( 'That upload belongs to a different user.', 'wp-mcp-connector' ) );
		}

		return $session;
	}

	/**
	 * Abandons an upload session and removes its temp file.
	 *
	 * @param string              $upload_id Upload id.
	 * @param array<string,mixed> $session   Session record.
	 * @return void
	 */
	private function discard_upload_session( $upload_id, array $session ) {
		$this->forget_session( (string) $upload_id );

		if ( ! empty( $session['tmp'] ) && file_exists( $session['tmp'] ) ) {
			@unlink( $session['tmp'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Takes a complete file on disk and turns it into an attachment.
	 *
	 * Shared by every upload route: a direct URL fetch, a small base64 payload,
	 * and the chunked flow. Assembling the bytes is the only thing those differ
	 * in; validation, sideloading, metadata and alt text must not.
	 *
	 * Consumes $tmp_path either way, since wp_handle_sideload moves it on success
	 * and it is deleted on failure.
	 *
	 * @param string              $tmp_path Absolute path to the finished file.
	 * @param string              $filename Filename to store it under.
	 * @param array<string,mixed> $args     Title, alt_text, caption, description, post_id.
	 * @return array<string,mixed>|WP_Error
	 */
	private function store_file( $tmp_path, $filename, array $args ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$checked = wp_check_filetype_and_ext( $tmp_path, $filename );

		if ( empty( $checked['type'] ) || ! $checked['type'] ) {
			@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return new WP_Error(
				'wpmcp_disallowed_filetype',
				sprintf(
					/* translators: %s: filename. */
					__( 'WordPress will not accept "%s". Its contents do not match an allowed upload type on this site. If this arrived in chunks, the assembled bytes are probably corrupt; start again.', 'wp-mcp-connector' ),
					$filename
				)
			);
		}

		$file = array(
			'name'     => $filename,
			'type'     => $checked['type'],
			'tmp_name' => $tmp_path,
			'error'    => 0,
			'size'     => filesize( $tmp_path ),
		);

		$sideloaded = wp_handle_sideload( $file, array( 'test_form' => false ) );

		if ( isset( $sideloaded['error'] ) ) {
			@unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return new WP_Error(
				'wpmcp_upload_failed',
				sprintf(
					/* translators: %s: error message from WordPress. */
					__( 'Upload failed: %s', 'wp-mcp-connector' ),
					$sideloaded['error']
				)
			);
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $sideloaded['type'],
				'post_title'     => ! empty( $args['title'] ) ? sanitize_text_field( $args['title'] ) : preg_replace( '/\.[^.]+$/', '', $filename ),
				'post_content'   => ! empty( $args['description'] ) ? sanitize_textarea_field( $args['description'] ) : '',
				'post_excerpt'   => ! empty( $args['caption'] ) ? sanitize_textarea_field( $args['caption'] ) : '',
				'post_status'    => 'inherit',
			),
			$sideloaded['file'],
			! empty( $args['post_id'] ) ? (int) $args['post_id'] : 0,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $sideloaded['file'] ) );

		if ( ! empty( $args['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
		}

		// Provenance for anything not shot in house. Recorded at upload time
		// because that is the only moment the answer is known for certain; a
		// month later nobody remembers where a photograph came from, and on a
		// site whose whole position is saying where figures come from, not
		// knowing where a picture came from is the same failure in another form.
		if ( ! empty( $args['source_url'] ) ) {
			update_post_meta( $attachment_id, '_wpmcp_source_url', esc_url_raw( $args['source_url'] ) );
		}

		if ( ! empty( $args['credit'] ) ) {
			update_post_meta( $attachment_id, '_wpmcp_credit', sanitize_text_field( $args['credit'] ) );
		}

		if ( ! empty( $args['license'] ) ) {
			update_post_meta( $attachment_id, '_wpmcp_license', sanitize_text_field( $args['license'] ) );
		}

		$result = $this->format_attachment( $attachment_id );

		if ( empty( $args['alt_text'] ) && 0 === strpos( (string) $sideloaded['type'], 'image/' ) ) {
			$result['warning'] = __( 'No alt text was set. Call wp_update_media to add one before this image is used in content.', 'wp-mcp-connector' );
		}

		return array(
			'uploaded' => true,
			'media'    => $result,
		);
	}

	/**
	 * Updates attachment metadata.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update_media( array $args ) {
		$id = (int) $args['id'];

		if ( 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error(
				'wpmcp_not_found',
				sprintf(
					/* translators: %d: attachment ID. */
					__( 'No attachment with ID %d.', 'wp-mcp-connector' ),
					$id
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpmcp_forbidden', __( 'You do not have permission to edit that attachment.', 'wp-mcp-connector' ) );
		}

		$postarr = array( 'ID' => $id );

		if ( isset( $args['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $args['title'] );
		}

		if ( isset( $args['caption'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $args['caption'] );
		}

		if ( isset( $args['description'] ) ) {
			$postarr['post_content'] = sanitize_textarea_field( $args['description'] );
		}

		if ( count( $postarr ) > 1 ) {
			wp_update_post( $postarr );
		}

		if ( isset( $args['alt_text'] ) ) {
			if ( '' === $args['alt_text'] ) {
				delete_post_meta( $id, '_wp_attachment_image_alt' );
			} else {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
			}
		}

		foreach ( array( 'credit' => '_wpmcp_credit', 'license' => '_wpmcp_license', 'source_url' => '_wpmcp_source_url' ) as $arg => $key ) {
			if ( isset( $args[ $arg ] ) ) {
				if ( '' === $args[ $arg ] ) {
					delete_post_meta( $id, $key );
				} else {
					update_post_meta( $id, $key, 'source_url' === $arg ? esc_url_raw( $args[ $arg ] ) : sanitize_text_field( $args[ $arg ] ) );
				}
			}
		}

		return array(
			'updated' => true,
			'media'   => $this->format_attachment( $id ),
		);
	}

	/**
	 * Inserts an image into a post body as a real block.
	 *
	 * Built on parse_blocks/serialize_blocks rather than string surgery, because
	 * the body is block markup and splicing HTML into it by offset is how block
	 * comments get orphaned and the editor starts reporting recovery errors.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function insert_media_into_post( array $args ) {
		$post_id       = (int) $args['post_id'];
		$attachment_id = (int) $args['attachment_id'];
		$post          = get_post( $post_id );

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

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'wpmcp_forbidden', __( 'You do not have permission to edit that post.', 'wp-mcp-connector' ) );
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error(
				'wpmcp_not_found',
				sprintf(
					/* translators: %d: attachment ID. */
					__( 'No attachment with ID %d. Upload it first, then insert it.', 'wp-mcp-connector' ),
					$attachment_id
				)
			);
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'wpmcp_not_an_image', __( 'That attachment is not an image, so it cannot be inserted as an image block.', 'wp-mcp-connector' ) );
		}

		$size = ! empty( $args['size'] ) ? sanitize_key( $args['size'] ) : 'large';
		$src  = wp_get_attachment_image_src( $attachment_id, $size );

		if ( ! $src ) {
			return new WP_Error( 'wpmcp_bad_size', __( 'That image size does not exist on this site.', 'wp-mcp-connector' ) );
		}

		$alt     = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$caption = isset( $args['caption'] ) ? sanitize_text_field( $args['caption'] ) : '';
		$align   = isset( $args['align'] ) && 'none' !== $args['align'] ? sanitize_key( $args['align'] ) : '';

		$attrs = array(
			'id'              => $attachment_id,
			'sizeSlug'        => $size,
			'linkDestination' => 'none',
		);

		if ( $align ) {
			$attrs['align'] = $align;
		}

		$classes = 'wp-block-image size-' . $size . ( $align ? ' align' . $align : '' );

		$figure  = '<figure class="' . esc_attr( $classes ) . '">';
		$figure .= '<img src="' . esc_url( $src[0] ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $attachment_id . '"/>';

		if ( '' !== $caption ) {
			$figure .= '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>';
		}

		$figure .= '</figure>';

		$html = '<!-- wp:image ' . wp_json_encode( $attrs ) . ' -->' . "\n" . $figure . "\n" . '<!-- /wp:image -->';

		$blocks    = parse_blocks( $post->post_content );
		$new_block = parse_blocks( $html );
		$new_block = $new_block[0];

		$position = isset( $args['position'] ) ? $args['position'] : 'end';
		$placed   = $position;

		if ( 'start' === $position ) {
			array_unshift( $blocks, $new_block );
		} elseif ( 'after_paragraph' === $position ) {
			$target = isset( $args['after_paragraph'] ) ? max( 1, (int) $args['after_paragraph'] ) : 1;
			$seen   = 0;
			$index  = null;

			foreach ( $blocks as $i => $block ) {
				if ( 'core/paragraph' === $block['blockName'] ) {
					++$seen;

					if ( $seen === $target ) {
						$index = $i + 1;
						break;
					}
				}
			}

			if ( null === $index ) {
				$blocks[] = $new_block;
				$placed   = 'end';
			} else {
				array_splice( $blocks, $index, 0, array( $new_block ) );
			}
		} else {
			$blocks[] = $new_block;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => serialize_blocks( $blocks ),
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'inserted'  => true,
			'post_id'   => $post_id,
			'image'     => $attachment_id,
			'placed_at' => $placed,
			'alt_text'  => '' !== $alt ? $alt : null,
			'warning'   => '' === $alt
				? __( 'This image has no alt text, so it was inserted without any. Set it with wp_update_media and the block will pick it up on the next edit.', 'wp-mcp-connector' )
				: null,
			'edit_link' => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * Renames an attachment's file on disk and repairs every reference to it.
	 *
	 * The filename is the one image-SEO field that is genuinely hard to change
	 * later, because it is baked into the stored URL of the original and of
	 * every generated size. Doing it properly means four things, and skipping
	 * any one of them leaves broken images behind:
	 *
	 *   1. move the original and every registered size on disk;
	 *   2. move sibling derivatives the theme builds from the same stem, which
	 *      are not in attachment metadata and would otherwise be orphaned;
	 *   3. update _wp_attached_file, the size metadata and the guid;
	 *   4. rewrite post content that referenced any of the old URLs.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function rename_media( array $args ) {
		global $wpdb;

		$id = (int) $args['id'];

		if ( 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error(
				'wpmcp_not_found',
				sprintf(
					/* translators: %d: attachment ID. */
					__( 'No attachment with ID %d.', 'wp-mcp-connector' ),
					$id
				)
			);
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'wpmcp_forbidden', __( 'You do not have permission to edit that attachment.', 'wp-mcp-connector' ) );
		}

		$old_path = get_attached_file( $id );

		if ( ! $old_path || ! file_exists( $old_path ) ) {
			return new WP_Error( 'wpmcp_file_missing', __( 'That attachment has no file on disk to rename.', 'wp-mcp-connector' ) );
		}

		$dir       = dirname( $old_path );
		$extension = pathinfo( $old_path, PATHINFO_EXTENSION );
		$old_base  = pathinfo( $old_path, PATHINFO_FILENAME );

		// Accept a name with or without the extension, and never let the caller
		// change the extension: the bytes on disk decide the type, not the name.
		$requested = sanitize_file_name( (string) $args['filename'] );
		$requested = preg_replace( '/\.[a-z0-9]+$/i', '', $requested );
		$requested = sanitize_title( $requested );

		if ( '' === $requested ) {
			return new WP_Error( 'wpmcp_bad_filename', __( 'That filename reduces to nothing usable. Use descriptive hyphenated words.', 'wp-mcp-connector' ) );
		}

		if ( $requested === $old_base ) {
			return array(
				'renamed' => false,
				'reason'  => __( 'The file already has that name, so nothing changed.', 'wp-mcp-connector' ),
				'media'   => $this->format_attachment( $id ),
			);
		}

		$new_name = wp_unique_filename( $dir, $requested . '.' . $extension );
		$new_base = pathinfo( $new_name, PATHINFO_FILENAME );
		$new_path = trailingslashit( $dir ) . $new_name;

		if ( ! @rename( $old_path, $new_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'wpmcp_rename_failed', __( 'The server refused to move the file. Check permissions on the uploads directory.', 'wp-mcp-connector' ) );
		}

		$uploads     = wp_get_upload_dir();
		$to_relative = static function ( $path ) use ( $uploads ) {
			return ltrim( str_replace( trailingslashit( $uploads['basedir'] ), '', $path ), '/' );
		};

		$replacements = array(
			$uploads['baseurl'] . '/' . $to_relative( $old_path ) => $uploads['baseurl'] . '/' . $to_relative( $new_path ),
		);

		$meta  = wp_get_attachment_metadata( $id );
		$moved = 1;

		// Registered sizes carry their own derived filenames.
		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $data ) {
				if ( empty( $data['file'] ) ) {
					continue;
				}

				$old_size = trailingslashit( $dir ) . $data['file'];
				$new_file = str_replace( $old_base, $new_base, $data['file'] );
				$new_size = trailingslashit( $dir ) . $new_file;

				if ( file_exists( $old_size ) && @rename( $old_size, $new_size ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$replacements[ $uploads['baseurl'] . '/' . $to_relative( $old_size ) ] = $uploads['baseurl'] . '/' . $to_relative( $new_size );
					$meta['sizes'][ $size ]['file'] = $new_file;
					++$moved;
				}
			}
		}

		/**
		 * Filters the sibling derivative suffixes moved alongside a rename.
		 *
		 * A theme can build its own images from an attachment's stem, and those
		 * never appear in attachment metadata. On this site the social card and
		 * the Pinterest pin are both built that way, so leaving them behind
		 * would silently break the cards until something regenerated them.
		 *
		 * @param string[] $suffixes Filenames relative to the stem.
		 */
		$suffixes = apply_filters( 'wpmcp_media_derivative_suffixes', array( '-social.jpg', '-pin.jpg' ) );

		foreach ( (array) $suffixes as $suffix ) {
			$old_side = trailingslashit( $dir ) . $old_base . $suffix;
			$new_side = trailingslashit( $dir ) . $new_base . $suffix;

			if ( file_exists( $old_side ) && @rename( $old_side, $new_side ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$replacements[ $uploads['baseurl'] . '/' . $to_relative( $old_side ) ] = $uploads['baseurl'] . '/' . $to_relative( $new_side );
				++$moved;
			}
		}

		update_attached_file( $id, $new_path );

		if ( is_array( $meta ) ) {
			$meta['file'] = $to_relative( $new_path );
			wp_update_attachment_metadata( $id, $meta );
		}

		$wpdb->update( $wpdb->posts, array( 'guid' => $uploads['baseurl'] . '/' . $to_relative( $new_path ) ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );

		// Repair content that pointed at any of the old URLs. Without this the
		// rename is not a rename, it is a broken image with a tidy filename.
		$rewritten = 0;

		foreach ( $replacements as $from => $to ) {
			$rewritten += (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s ) WHERE post_content LIKE %s",
					$from,
					$to,
					'%' . $wpdb->esc_like( $from ) . '%'
				)
			);
		}

		if ( $rewritten > 0 ) {
			clean_post_cache( 0 );
			wp_cache_flush_group( 'posts' );
		}

		return array(
			'renamed'          => true,
			'from'             => basename( $old_path ),
			'to'               => $new_name,
			'files_moved'      => $moved,
			'posts_rewritten'  => $rewritten,
			'media'            => $this->format_attachment( $id ),
			'note'             => $requested !== $new_base
				? __( 'That name was taken, so a numeric suffix was added.', 'wp-mcp-connector' )
				: __( 'Original, generated sizes and theme derivatives all moved together.', 'wp-mcp-connector' ),
		);
	}

	/**
	 * Deletes an attachment.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete_media( array $args ) {
		$id = (int) $args['id'];

		if ( 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error(
				'wpmcp_not_found',
				sprintf(
					/* translators: %d: attachment ID. */
					__( 'No attachment with ID %d.', 'wp-mcp-connector' ),
					$id
				)
			);
		}

		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'wpmcp_forbidden', __( 'You do not have permission to delete that attachment.', 'wp-mcp-connector' ) );
		}

		$url = wp_get_attachment_url( $id );

		if ( ! wp_delete_attachment( $id, true ) ) {
			return new WP_Error( 'wpmcp_delete_failed', __( 'WordPress could not delete that attachment.', 'wp-mcp-connector' ) );
		}

		return array(
			'deleted' => true,
			'id'      => $id,
			'url'     => $url,
			'message' => __( 'The file and all its generated sizes were removed from disk. This cannot be undone.', 'wp-mcp-connector' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Downloads a remote file to a temporary path.
	 *
	 * Uses wp_safe_remote_get, which refuses loopback and private-network hosts,
	 * so a model cannot be talked into fetching http://169.254.169.254/ and
	 * writing cloud credentials into the media library.
	 *
	 * @param string $url       Source URL.
	 * @param int    $max_bytes Size ceiling.
	 * @return array{tmp:string,filename:string}|WP_Error
	 */
	private function fetch_url( $url, $max_bytes ) {
		$url = esc_url_raw( $url );

		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'wpmcp_bad_url', __( 'That is not a valid, publicly reachable URL.', 'wp-mcp-connector' ) );
		}

		$allowed_hosts = (array) wpmcp()->settings()->get( 'allowed_media_hosts', array() );

		if ( $allowed_hosts ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

			if ( ! in_array( $host, $allowed_hosts, true ) ) {
				return new WP_Error(
					'wpmcp_host_not_allowed',
					sprintf(
						/* translators: 1: host, 2: allowed hosts. */
						__( 'Downloads from "%1$s" are not permitted. This site only accepts media from: %2$s', 'wp-mcp-connector' ),
						$host,
						implode( ', ', $allowed_hosts )
					)
				);
			}
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 45,
				'redirection' => 5,
				'stream'      => false,
				'headers'     => array(
					'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
					// Many CDNs and object stores refuse the default
					// "WordPress/x.y; https://site" agent outright, which looks
					// from the caller's side like the file not existing. A
					// browser-shaped agent is what those hosts expect, and this
					// is a plain GET of a URL the caller already has.
					'User-Agent' => 'Mozilla/5.0 (compatible; WP-MCP-Connector/' . WPMCP_VERSION . '; +' . home_url( '/' ) . ')',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wpmcp_download_failed',
				sprintf(
					/* translators: %s: HTTP error message. */
					__( 'Could not download that file: %s. The address may be unreachable from this server, or blocked as a private network address.', 'wp-mcp-connector' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			// Distinguish "you need credentials" from "it is not there", because
			// the recovery is completely different and only one of them is worth
			// the caller retrying.
			if ( in_array( $code, array( 401, 403 ), true ) ) {
				return new WP_Error(
					'wpmcp_download_forbidden',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'The source refused the download with HTTP %d. That address needs credentials this site does not have, which is usual for a file held inside a chat session or a private bucket. Do not retry and do not fall back to base64 for a large file. Tell the user the image is not reachable from their server and ask them to supply it another way.', 'wp-mcp-connector' ),
						$code
					)
				);
			}

			return new WP_Error(
				'wpmcp_download_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The source returned HTTP %d rather than the file. Check the URL is complete, including any query string.', 'wp-mcp-connector' ),
					$code
				)
			);
		}

		// A login wall usually answers 200 with an HTML page rather than an
		// error, so a successful fetch that is not an image is still a failure.
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );

		if ( $content_type && 0 === strpos( $content_type, 'text/html' ) ) {
			return new WP_Error(
				'wpmcp_download_not_a_file',
				__( 'That address returned a web page rather than a file, which usually means it is behind a login or a consent screen. Ask the user to supply the image another way.', 'wp-mcp-connector' )
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new WP_Error( 'wpmcp_download_empty', __( 'The source returned an empty file.', 'wp-mcp-connector' ) );
		}

		if ( strlen( $body ) > $max_bytes ) {
			return new WP_Error(
				'wpmcp_too_large',
				sprintf(
					/* translators: 1: file size, 2: limit. */
					__( 'That file is %1$s, over this site\'s %2$s upload limit for MCP.', 'wp-mcp-connector' ),
					size_format( strlen( $body ) ),
					size_format( $max_bytes )
				)
			);
		}

		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$filename = sanitize_file_name( basename( $path ) );

		if ( '' === $filename ) {
			$filename = 'download';
		}

		// Most image CDNs serve from an extensionless path with the real type in
		// the Content-Type header, so trusting the URL alone produces a filename
		// WordPress refuses. Take the extension from what the server actually
		// said it sent, and fall back to sniffing the bytes.
		if ( ! pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			$types = array(
				'image/jpeg' => 'jpg',
				'image/jpg'  => 'jpg',
				'image/png'  => 'png',
				'image/webp' => 'webp',
				'image/gif'  => 'gif',
				'image/avif' => 'avif',
				'image/svg+xml' => 'svg',
			);

			$mime = strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' );
			$mime = strtolower( trim( (string) $mime ) );

			if ( isset( $types[ $mime ] ) ) {
				$filename .= '.' . $types[ $mime ];
			}
		}

		$written = $this->write_temp( $body, $filename );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		// Last resort: no usable extension from the URL or the header, so ask the
		// file itself what it is.
		if ( ! pathinfo( $written['filename'], PATHINFO_EXTENSION ) ) {
			$sniffed = wp_check_filetype_and_ext( $written['tmp'], $written['filename'] . '.jpg' );

			if ( ! empty( $sniffed['ext'] ) ) {
				$written['filename'] .= '.' . $sniffed['ext'];
			}
		}

		return $written;
	}

	/**
	 * Decodes base64 payload to a temporary file.
	 *
	 * @param string $data      Base64 data, optionally a data: URI.
	 * @param string $filename  Requested filename.
	 * @param int    $max_bytes Size ceiling.
	 * @return array{tmp:string,filename:string}|WP_Error
	 */
	private function decode_base64( $data, $filename, $max_bytes ) {
		if ( '' === $filename ) {
			return new WP_Error( 'wpmcp_missing_filename', __( 'filename is required when uploading base64_data, so WordPress knows the file type.', 'wp-mcp-connector' ) );
		}

		if ( 0 === strpos( $data, 'data:' ) ) {
			$comma = strpos( $data, ',' );
			$data  = false === $comma ? '' : substr( $data, $comma + 1 );
		}

		$binary = base64_decode( preg_replace( '/\s+/', '', $data ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $binary || '' === $binary ) {
			return new WP_Error( 'wpmcp_bad_base64', __( 'base64_data could not be decoded. Send standard base64, with or without a data: URI prefix.', 'wp-mcp-connector' ) );
		}

		if ( strlen( $binary ) > $max_bytes ) {
			return new WP_Error(
				'wpmcp_too_large',
				sprintf(
					/* translators: 1: file size, 2: limit. */
					__( 'That file is %1$s, over this site\'s %2$s upload limit for MCP.', 'wp-mcp-connector' ),
					size_format( strlen( $binary ) ),
					size_format( $max_bytes )
				)
			);
		}

		return $this->write_temp( $binary, sanitize_file_name( $filename ) );
	}

	/**
	 * Writes bytes to a temp file.
	 *
	 * @param string $bytes    File contents.
	 * @param string $filename Suggested filename.
	 * @return array{tmp:string,filename:string}|WP_Error
	 */
	private function write_temp( $bytes, $filename ) {
		$tmp = wp_tempnam( $filename );

		if ( ! $tmp ) {
			return new WP_Error( 'wpmcp_tempfile', __( 'Could not create a temporary file. Check that the server temp directory is writable.', 'wp-mcp-connector' ) );
		}

		if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return new WP_Error( 'wpmcp_tempfile', __( 'Could not write the downloaded file to disk.', 'wp-mcp-connector' ) );
		}

		return array(
			'tmp'      => $tmp,
			'filename' => $filename,
		);
	}

	/**
	 * Serialises an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string,mixed>
	 */
	private function format_attachment( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		$file = get_attached_file( $id );

		return array(
			'id'        => (int) $id,
			'title'     => get_the_title( $id ),
			'url'       => wp_get_attachment_url( $id ),
			'mime_type' => get_post_mime_type( $id ),
			'alt_text'  => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'   => wp_get_attachment_caption( $id ),
			'filename'  => $file ? basename( $file ) : '',
			'filesize'  => $file && file_exists( $file ) ? size_format( filesize( $file ) ) : null,
			'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'date'      => get_the_date( 'c', $id ),
			'parent'    => (int) wp_get_post_parent_id( $id ),
			'source'    => get_post_meta( $id, '_wpmcp_source_url', true ) ?: null,
			'credit'    => get_post_meta( $id, '_wpmcp_credit', true ) ?: null,
			'license'   => get_post_meta( $id, '_wpmcp_license', true ) ?: null,
		);
	}
}
