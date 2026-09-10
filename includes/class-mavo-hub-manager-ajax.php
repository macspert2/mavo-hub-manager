<?php
/**
 * Admin-only AJAX: the two active search boxes.
 *
 *   context = mark   → find a post/page to mark as a hub
 *   context = child  → find a post/page to add manually as a child of the
 *                      selected hub (same language, valid for that hub type)
 *
 * Both require a logged-in administrator and a valid nonce, and both return at
 * most 20 rows. Nothing here writes anything.
 */

defined( 'ABSPATH' ) || exit;

class MHM_Ajax {

	public const ACTION     = 'mavo_hub_manager_search';
	public const NONCE      = 'mavo_hub_manager_search';
	public const MAX_RESULTS = 20;

	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'handle_search' ] );
		add_filter( 'posts_search', [ __CLASS__, 'title_only_search' ], 10, 2 );
	}

	public static function handle_search(): void {
		if ( ! current_user_can( MHM_Admin::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'mavo-hub-manager' ) ], 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		$term    = isset( $_REQUEST['q'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) : '';
		$context = isset( $_REQUEST['context'] ) ? sanitize_key( wp_unslash( $_REQUEST['context'] ) ) : 'mark';
		$hub_id  = isset( $_REQUEST['hub'] ) ? absint( wp_unslash( $_REQUEST['hub'] ) ) : 0;

		$term = trim( $term );
		if ( mb_strlen( $term ) < 2 ) {
			wp_send_json_success( [ 'results' => [], 'message' => __( 'Type at least two characters.', 'mavo-hub-manager' ) ] );
		}

		$candidates = self::find_posts( $term );

		if ( 'child' === $context ) {
			$results = self::child_results( $candidates, $hub_id );
		} else {
			$results = self::mark_results( $candidates );
		}

		wp_send_json_success( [ 'results' => array_slice( $results, 0, self::MAX_RESULTS ) ] );
	}

	/* --------------------------------------------------------------- lookup */

	/**
	 * Posts/pages matching a title fragment, plus the exact ID when the term is
	 * numeric. Over-fetches a little because the child context filters after.
	 *
	 * @return int[]
	 */
	private static function find_posts( string $term ): array {
		$ids = [];

		if ( ctype_digit( $term ) ) {
			$exact = MHM_Model::get_eligible_post( (int) $term );
			if ( $exact ) {
				$ids[] = (int) $exact->ID;
			}
		}

		$query = new WP_Query(
			[
				'post_type'              => MHM_Model::POST_TYPES,
				'post_status'            => 'any',
				's'                      => $term,
				'mhm_title_search'       => true,
				'posts_per_page'         => self::MAX_RESULTS * 3,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'lang'                   => '', // Search every language; the caller filters.
			]
		);

		foreach ( (array) $query->posts as $id ) {
			$id = absint( $id );
			if ( $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Restrict the WP_Query search clause to post_title for our own queries.
	 *
	 * @param string   $search
	 * @param WP_Query $query
	 */
	public static function title_only_search( $search, $query ) {
		global $wpdb;

		if ( ! $query instanceof WP_Query || ! $query->get( 'mhm_title_search' ) ) {
			return $search;
		}

		$term = (string) $query->get( 's' );
		if ( '' === $term ) {
			return $search;
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		return $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s ", $like );
	}

	/* -------------------------------------------------------------- shaping */

	/** Rows for "find a post or page to mark as a hub". */
	private static function mark_results( array $ids ): array {
		$results = [];

		foreach ( $ids as $id ) {
			$post = MHM_Model::get_eligible_post( (int) $id );
			if ( ! $post ) {
				continue;
			}

			$results[] = self::base_row( $post );
		}

		return $results;
	}

	/**
	 * Rows for "add child manually": same language as the hub, and only
	 * candidates the hub could validly own.
	 */
	private static function child_results( array $ids, int $hub_id ): array {
		$type = MHM_Model::get_hub_type( $hub_id );
		if ( null === $type ) {
			return [];
		}

		$results = [];

		foreach ( $ids as $id ) {
			$post = MHM_Model::get_eligible_post( (int) $id );
			if ( ! $post ) {
				continue;
			}

			$child_id = (int) $post->ID;

			if ( ! MHM_Model::is_same_language( $child_id, $hub_id ) ) {
				continue; // Cross-language children are never proposed.
			}

			$valid = MHM_Model::validate_relationship( $child_id, $hub_id, $type );
			if ( is_wp_error( $valid ) ) {
				continue; // Self, cycle, wrong type — not offerable.
			}

			$row                = self::base_row( $post );
			$current            = MHM_Model::get_primary_hub( $child_id, $type );
			$row['current_hub'] = MHM_Scanner::hub_summary( $current );
			$row['conflict']    = null !== $current && $current !== $hub_id;

			$results[] = $row;
		}

		return $results;
	}

	private static function base_row( WP_Post $post ): array {
		$id = (int) $post->ID;

		return [
			'id'         => $id,
			'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'post_type'  => $post->post_type,
			'status'     => $post->post_status,
			'lang'       => MHM_Model::get_language( $id ),
			'hub_type'   => MHM_Model::get_hub_type( $id ),
			'edit_link'  => (string) get_edit_post_link( $id, 'raw' ),
		];
	}
}
