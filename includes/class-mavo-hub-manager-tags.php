<?php
/**
 * Finding children by tag.
 *
 * A second discovery source beside the internal-link scanner, and deliberately
 * nothing more than that. A tag is a signal about what a post is about; the
 * source of truth stays the child's own primary-hub meta, exactly as with
 * links (see the "Link discovery vs source of truth" note in the model).
 *
 * So this class only *finds* and *classifies*. Every row it produces goes
 * through MHM_Scanner::classify(), which means the states, the preselection
 * rules and the batch-assign path are identical to the scanner's: an existing
 * primary hub is never silently overwritten, cross-language pairs are never
 * assigned automatically, and unpublished posts are shown but never
 * preselected.
 *
 * Nothing about the tag is stored. Which tag a hub draws from is suggested by
 * name each time, so the three meta keys remain the whole data model.
 */

defined( 'ABSPATH' ) || exit;

class MHM_Tags {

	public const TAXONOMY = 'post_tag';

	public const PER_PAGE = 50;

	/**
	 * The tag that looks like it belongs to this hub.
	 *
	 * Tried in order: the hub's own slug, its title turned into a slug, then
	 * its title as a tag name — /italie/ finds the tag "italie". Returns null
	 * when nothing matches, which is not an error: the admin picks one.
	 */
	public static function suggest_for_hub( int $hub_id ): ?WP_Term {
		$hub = MHM_Model::get_eligible_post( $hub_id );

		if ( ! $hub || ! taxonomy_exists( self::TAXONOMY ) ) {
			return null;
		}

		$candidates = [];

		if ( '' !== (string) $hub->post_name ) {
			$candidates[] = [ 'slug', (string) $hub->post_name ];
		}

		$title = (string) get_the_title( $hub );

		if ( '' !== $title ) {
			$candidates[] = [ 'slug', sanitize_title( $title ) ];
			$candidates[] = [ 'name', $title ];
		}

		foreach ( $candidates as [ $field, $value ] ) {
			if ( '' === $value ) {
				continue;
			}

			$term = get_term_by( $field, $value, self::TAXONOMY );

			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}

		return null;
	}

	/** One tag by ID, or null. */
	public static function get_tag( int $term_id ): ?WP_Term {
		$term_id = absint( $term_id );

		if ( ! $term_id || ! taxonomy_exists( self::TAXONOMY ) ) {
			return null;
		}

		$term = get_term( $term_id, self::TAXONOMY );

		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * Tags whose name or slug matches, most used first — for the picker.
	 *
	 * @return WP_Term[]
	 */
	public static function search( string $search, int $limit = 20 ): array {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return [];
		}

		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'search'     => $search,
				'number'     => max( 1, $limit ),
				'orderby'    => 'count',
				'order'      => 'DESC',
				'hide_empty' => true,
			]
		);

		return is_array( $terms ) ? array_filter( $terms, static fn( $t ) => $t instanceof WP_Term ) : [];
	}

	/**
	 * Posts carrying one tag, classified against the selected hub.
	 *
	 * Paged with one row fetched beyond the page, so "is there more?" never
	 * costs a count over the whole tag.
	 *
	 * @param array $args paged, per_page, status ('publish'|'any'), sort ('date'|'views').
	 * @return array|WP_Error
	 */
	public static function candidates( int $hub_id, int $term_id, array $args = [] ) {
		$type = MHM_Model::get_hub_type( $hub_id );

		if ( null === $type ) {
			return new WP_Error( 'mhm_not_a_hub', __( 'The selected post is not a hub.', 'mavo-hub-manager' ) );
		}

		$term = self::get_tag( $term_id );

		if ( ! $term ) {
			return new WP_Error( 'mhm_no_tag', __( 'That tag no longer exists.', 'mavo-hub-manager' ) );
		}

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, absint( $args['per_page'] ) ) ) : self::PER_PAGE;
		$paged    = isset( $args['paged'] ) ? max( 1, absint( $args['paged'] ) ) : 1;
		$status   = ( isset( $args['status'] ) && 'any' === $args['status'] ) ? 'any' : 'publish';
		$sort     = ( isset( $args['sort'] ) && 'views' === $args['sort'] ) ? 'views' : 'date';

		$query_args = [
			'post_type'              => MHM_Model::POST_TYPES,
			'post_status'            => $status,
			'fields'                 => 'ids',
			'posts_per_page'         => $per_page + 1,
			'offset'                 => ( $paged - 1 ) * $per_page,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			// Every language: a cross-language post is shown as a diagnostic
			// rather than hidden, the way the link scanner shows one.
			'lang'                   => '',
			'tax_query'              => [ // phpcs:ignore WordPress.DB.SlowDBQuery -- the whole point of this screen.
				[
					'taxonomy' => self::TAXONOMY,
					'field'    => 'term_id',
					'terms'    => [ (int) $term->term_id ],
				],
			],
		];

		if ( 'views' === $sort ) {
			// Narrowed by the tag first, so this sort stays affordable — but it
			// joins the counter, so posts without one drop out of the list.
			$query_args['meta_key'] = MHM_Audit::views_meta_key(); // phpcs:ignore WordPress.DB.SlowDBQuery
			$query_args['orderby']  = 'meta_value_num';
			$query_args['order']    = 'DESC';
		} else {
			$query_args['orderby'] = 'date';
			$query_args['order']   = 'DESC';
		}

		$found    = array_map( 'absint', (array) ( new WP_Query( $query_args ) )->posts );
		$has_more = count( $found ) > $per_page;
		$found    = array_slice( $found, 0, $per_page );

		$rows   = [];
		$counts = array_fill_keys(
			[
				MHM_Scanner::UNASSIGNED,
				MHM_Scanner::ALREADY_ASSIGNED_HERE,
				MHM_Scanner::CONFLICT,
				MHM_Scanner::CROSS_LANGUAGE,
				MHM_Scanner::INVALID_TARGET,
				MHM_Scanner::SELF,
			],
			0
		);

		foreach ( $found as $post_id ) {
			$row = MHM_Scanner::classify( $post_id, $hub_id, $type );

			$rows[]                    = $row;
			$counts[ $row['state'] ]   = ( $counts[ $row['state'] ] ?? 0 ) + 1;
		}

		return [
			'hub'      => absint( $hub_id ),
			'type'     => $type,
			'term'     => $term,
			'rows'     => $rows,
			'counts'   => $counts,
			'paged'    => $paged,
			'per_page' => $per_page,
			'has_more' => $has_more,
			'status'   => $status,
			'sort'     => $sort,
		];
	}
}
