<?php
/**
 * Comment moderation tools.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists, moderates and replies to comments.
 */
class WPMCP_Tools_Comments {

	/**
	 * Registers the tools.
	 *
	 * @param WPMCP_Registry $registry Registry.
	 * @return void
	 */
	public function register( WPMCP_Registry $registry ) {
		$registry->add(
			array(
				'name'         => 'wp_list_comments',
				'title'        => __( 'List comments', 'wp-mcp-connector' ),
				'description'  => __( 'List comments filtered by status, post or search term. Defaults to the pending moderation queue, which is usually what you want. Returns the author name, the comment text, the post it belongs to and whether the author has been approved before. Read the comments before recommending action on them, and never mark something as spam on the strength of the author name alone.', 'wp-mcp-connector' ),
				'group'        => 'comments',
				'capability'   => 'moderate_comments',
				'profiles'     => array( 'read_only', 'author', 'editor', 'admin' ),
				'annotations'  => array(
					'readOnlyHint'   => true,
					'idempotentHint' => true,
				),
				'callback'     => array( $this, 'list_comments' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'status'   => WPMCP_Schema::string( __( 'Which queue to read. Defaults to hold, the pending queue.', 'wp-mcp-connector' ), array( 'hold', 'approve', 'spam', 'trash', 'all' ) ),
						'post_id'  => WPMCP_Schema::integer( __( 'Only comments on this post.', 'wp-mcp-connector' ), 1 ),
						'search'   => WPMCP_Schema::string( __( 'Match against comment content and author.', 'wp-mcp-connector' ) ),
						'per_page' => WPMCP_Schema::integer( __( 'Results to return, 1 to 100. Defaults to 25.', 'wp-mcp-connector' ), 1, 100 ),
						'page'     => WPMCP_Schema::integer( __( 'Page number, starting at 1.', 'wp-mcp-connector' ), 1 ),
					)
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_moderate_comment',
				'title'        => __( 'Moderate a comment', 'wp-mcp-connector' ),
				'description'  => __( 'Approve, unapprove, mark as spam, trash or restore a comment. Trash and spam are both reversible, so prefer them to permanent deletion. When working through a queue, report what you plan to do and get the user\'s agreement before acting in bulk.', 'wp-mcp-connector' ),
				'group'        => 'comments',
				'capability'   => 'moderate_comments',
				'profiles'     => array( 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => true ),
				'callback'     => array( $this, 'moderate_comment' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'id'     => WPMCP_Schema::integer( __( 'Comment ID.', 'wp-mcp-connector' ), 1 ),
						'action' => WPMCP_Schema::string( __( 'What to do with it.', 'wp-mcp-connector' ), array( 'approve', 'unapprove', 'spam', 'unspam', 'trash', 'untrash' ) ),
					),
					array( 'id', 'action' )
				),
			)
		);

		$registry->add(
			array(
				'name'         => 'wp_reply_to_comment',
				'title'        => __( 'Reply to a comment', 'wp-mcp-connector' ),
				'description'  => __( 'Post a public reply to a comment, authored by the connected WordPress user. This is visible to everyone on the site, so show the user the exact wording and get explicit approval before sending it.', 'wp-mcp-connector' ),
				'group'        => 'comments',
				'capability'   => 'moderate_comments',
				'profiles'     => array( 'editor', 'admin' ),
				'annotations'  => array( 'destructiveHint' => false ),
				'callback'     => array( $this, 'reply_to_comment' ),
				'input_schema' => WPMCP_Schema::object(
					array(
						'id'      => WPMCP_Schema::integer( __( 'ID of the comment being replied to.', 'wp-mcp-connector' ), 1 ),
						'content' => WPMCP_Schema::string( __( 'The reply text.', 'wp-mcp-connector' ) ),
					),
					array( 'id', 'content' )
				),
			)
		);
	}

	/**
	 * Lists comments.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>
	 */
	public function list_comments( array $args ) {
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 25;
		$page     = isset( $args['page'] ) ? (int) $args['page'] : 1;
		$status   = isset( $args['status'] ) ? $args['status'] : 'hold';

		$query_args = array(
			'status'  => 'all' === $status ? 'all' : $status,
			'number'  => $per_page,
			'paged'   => $page,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		);

		if ( ! empty( $args['post_id'] ) ) {
			$query_args['post_id'] = (int) $args['post_id'];
		}

		if ( ! empty( $args['search'] ) ) {
			$query_args['search'] = $args['search'];
		}

		$query    = new WP_Comment_Query();
		$comments = $query->query( $query_args );
		$output   = array();

		foreach ( $comments as $comment ) {
			$output[] = array(
				'id'              => (int) $comment->comment_ID,
				'post_id'         => (int) $comment->comment_post_ID,
				'post_title'      => get_the_title( $comment->comment_post_ID ),
				'author'          => $comment->comment_author,
				'author_email'    => current_user_can( 'moderate_comments' ) ? $comment->comment_author_email : null,
				'author_url'      => $comment->comment_author_url,
				'author_ip'       => current_user_can( 'moderate_comments' ) ? $comment->comment_author_IP : null,
				'date'            => $comment->comment_date,
				'content'         => $comment->comment_content,
				'status'          => wp_get_comment_status( $comment ),
				'parent'          => (int) $comment->comment_parent,
				'previously_approved' => (bool) get_comment_meta( $comment->comment_ID, '_wpmcp_seen', true ) || $this->author_has_approved_history( $comment ),
				'link'            => get_comment_link( $comment ),
			);
		}

		$counts = wp_count_comments();

		return array(
			'comments' => $output,
			'returned' => count( $output ),
			'queue'    => array(
				'pending'  => (int) $counts->moderated,
				'approved' => (int) $counts->approved,
				'spam'     => (int) $counts->spam,
				'trash'    => (int) $counts->trash,
			),
		);
	}

	/**
	 * Applies a moderation action.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function moderate_comment( array $args ) {
		$id      = (int) $args['id'];
		$comment = get_comment( $id );

		if ( ! $comment ) {
			return new WP_Error(
				'wpmcp_not_found',
				sprintf(
					/* translators: %d: comment ID. */
					__( 'No comment with ID %d. Use wp_list_comments to find current IDs.', 'wp-mcp-connector' ),
					$id
				)
			);
		}

		if ( ! current_user_can( 'edit_comment', $id ) ) {
			return new WP_Error( 'wpmcp_forbidden', __( 'You do not have permission to moderate that comment.', 'wp-mcp-connector' ) );
		}

		$before = wp_get_comment_status( $comment );
		$action = (string) $args['action'];

		switch ( $action ) {
			case 'approve':
				$ok = wp_set_comment_status( $id, 'approve' );
				break;

			case 'unapprove':
				$ok = wp_set_comment_status( $id, 'hold' );
				break;

			case 'spam':
				$ok = (bool) wp_spam_comment( $id );
				break;

			case 'unspam':
				$ok = (bool) wp_unspam_comment( $id );
				break;

			case 'trash':
				$ok = (bool) wp_trash_comment( $id );
				break;

			case 'untrash':
				$ok = (bool) wp_untrash_comment( $id );
				break;

			default:
				return new WP_Error( 'wpmcp_bad_action', __( 'Unknown moderation action.', 'wp-mcp-connector' ) );
		}

		if ( ! $ok ) {
			return new WP_Error(
				'wpmcp_moderation_failed',
				sprintf(
					/* translators: 1: action, 2: current status. */
					__( 'WordPress refused to %1$s that comment. It is currently "%2$s", so the action may already have been applied.', 'wp-mcp-connector' ),
					$action,
					$before
				)
			);
		}

		return array(
			'moderated' => true,
			'id'        => $id,
			'action'    => $action,
			'status'    => array(
				'before' => $before,
				'after'  => wp_get_comment_status( $id ),
			),
			'reversible' => in_array( $action, array( 'spam', 'trash', 'unapprove' ), true ),
		);
	}

	/**
	 * Posts a reply.
	 *
	 * @param array<string,mixed> $args Validated arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function reply_to_comment( array $args ) {
		$parent = get_comment( (int) $args['id'] );

		if ( ! $parent ) {
			return new WP_Error(
				'wpmcp_not_found',
				sprintf(
					/* translators: %d: comment ID. */
					__( 'No comment with ID %d.', 'wp-mcp-connector' ),
					(int) $args['id']
				)
			);
		}

		if ( ! current_user_can( 'edit_comment', $parent->comment_ID ) ) {
			return new WP_Error( 'wpmcp_forbidden', __( 'You do not have permission to reply to that comment.', 'wp-mcp-connector' ) );
		}

		$user    = wp_get_current_user();
		$content = wp_kses_post( (string) $args['content'] );

		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error( 'wpmcp_empty_reply', __( 'The reply is empty after sanitising. Send plain text or simple HTML.', 'wp-mcp-connector' ) );
		}

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => (int) $parent->comment_post_ID,
				'comment_parent'       => (int) $parent->comment_ID,
				'comment_content'      => $content,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_author_url'   => $user->user_url,
				'user_id'              => $user->ID,
				'comment_approved'     => 1,
				'comment_type'         => 'comment',
			)
		);

		if ( ! $comment_id ) {
			return new WP_Error( 'wpmcp_reply_failed', __( 'WordPress could not save the reply.', 'wp-mcp-connector' ) );
		}

		return array(
			'replied' => true,
			'id'      => (int) $comment_id,
			'link'    => get_comment_link( $comment_id ),
			'message' => __( 'The reply is live on the site.', 'wp-mcp-connector' ),
		);
	}

	/**
	 * Whether this comment author has had a comment approved before.
	 *
	 * A useful moderation signal, and cheap: WordPress already indexes on the
	 * author email.
	 *
	 * @param WP_Comment $comment Comment.
	 * @return bool
	 */
	private function author_has_approved_history( $comment ) {
		if ( empty( $comment->comment_author_email ) ) {
			return false;
		}

		$previous = get_comments(
			array(
				'author_email' => $comment->comment_author_email,
				'status'       => 'approve',
				'number'       => 1,
				'count'        => true,
			)
		);

		return (int) $previous > 0;
	}
}
