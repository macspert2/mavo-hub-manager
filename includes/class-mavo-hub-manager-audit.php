<?php
/**
 * Read-only audit queries over the hub model.
 *
 * Nothing in this class writes. It answers editorial questions the stored
 * model can already answer, but that the per-hub screens cannot show at site
 * scale:
 *
 *   1. Which posts/pages still have no primary hub?
 *   2. Which children never link back to the hub that owns them?
 *   3. How healthy is each hub (children, parent, stale links, issues)?
 *   4. Which stored relationships are broken? (delegates to MHM_Model)
 *
 * Everything is derived from the same three meta keys. No audit result is
 * cached in post meta, and no audit run repairs anything.
 */

defined( 'ABSPATH' ) || exit;

class MHM_Audit {

	public const DEFAULT_PER_PAGE = 50;

	/** How many assigned children one "no link back" pass reads content for. */
	public const DEFAULT_BATCH = 100;

	/** Link-back outcomes. */
	public const LINK_ANCHOR    = 'anchor';
	public const LINK_SHORTCODE = 'shortcode';
	public const LINK_ANCESTOR  = 'ancestor';
	public const LINK_NONE      = 'none';

	/** Resolved outbound targets per post, for one request only. */
	private static array $targets_cache = [];

	/* ------------------------------------------------------------ settings */

	/**
	 * Meta key holding the view counter used for ordering.
	 *
	 * Maman Voyage stores it as plain `views` (see mavo-geo-explorer and
	 * postsByTagOrderedByViews). Posts with no counter yet sort last.
	 */
	public static function views_meta_key(): string {
		return (string) apply_filters( 'mavo_hub_manager_views_meta_key', 'views' );
	}

	public static function get_views( int $post_id ): ?int {
		$raw = get_post_meta( absint( $post_id ), self::views_meta_key(), true );

		return ( '' === $raw || null === $raw ) ? null : (int) $raw;
	}

	/**
	 * Shortcodes whose attribute points at a hub, as tag => attribute.
	 *
	 * `[mavo_hub_strip slug="france" text="…{guide France}…"]` renders a link
	 * back to /france/ on the frontend, so a post carrying it is NOT missing a
	 * link back — even though the stored content contains no <a href>.
	 *
	 * The shortcode does not have to be registered: the stored content is
	 * parsed directly, exactly like the internal-link scanner.
	 */
	public static function link_back_shortcodes(): array {
		return (array) apply_filters(
			'mavo_hub_manager_link_back_shortcodes',
			[ 'mavo_hub_strip' => 'slug' ]
		);
	}

	/* -------------------------------------------------------- link targets */

	/**
	 * Every internal post/page this post points at, split by how.
	 *
	 * @return array{anchor:int[],shortcode:int[]}
	 */
	public static function outbound_targets( int $post_id ): array {
		$post_id = absint( $post_id );

		if ( isset( self::$targets_cache[ $post_id ] ) ) {
			return self::$targets_cache[ $post_id ];
		}

		$post    = MHM_Model::get_eligible_post( $post_id );
		$content = $post ? (string) $post->post_content : '';

		$anchors = [];
		foreach ( MHM_Scanner::extract_links( $content ) as $url ) {
			$target = MHM_Scanner::resolve_url( $url );
			if ( $target && $target !== $post_id ) {
				$anchors[ $target ] = true;
			}
		}

		$shortcodes = [];
		foreach ( self::shortcode_link_targets( $content ) as $target ) {
			if ( $target !== $post_id ) {
				$shortcodes[ $target ] = true;
			}
		}

		$result = [
			'anchor'    => array_map( 'absint', array_keys( $anchors ) ),
			'shortcode' => array_map( 'absint', array_keys( $shortcodes ) ),
		];

		self::$targets_cache[ $post_id ] = $result;

		return $result;
	}

	/** Forget cached scans — long batch runs should not hold every post. */
	public static function flush_target_cache(): void {
		self::$targets_cache = [];
	}

	/**
	 * Post/page IDs referenced by link-back shortcodes in stored content.
	 *
	 * @return int[]
	 */
	public static function shortcode_link_targets( string $content ): array {
		if ( '' === trim( $content ) ) {
			return [];
		}

		$targets = [];

		foreach ( self::link_back_shortcodes() as $tag => $attribute ) {
			$tag       = (string) $tag;
			$attribute = (string) $attribute;

			if ( '' === $tag || false === stripos( $content, '[' . $tag ) ) {
				continue;
			}

			$pattern = '/\[' . preg_quote( $tag, '/' ) . '(?![\w-])([^\]]*)\]/i';
			if ( ! preg_match_all( $pattern, $content, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $raw_atts ) {
				$atts  = shortcode_parse_atts( $raw_atts );
				$value = is_array( $atts ) && isset( $atts[ $attribute ] ) ? trim( (string) $atts[ $attribute ] ) : '';

				if ( '' === $value ) {
					continue;
				}

				$target = MHM_Scanner::resolve_url( self::shortcode_value_to_url( $value ) );
				if ( $target ) {
					$targets[ $target ] = true;
				}
			}
		}

		return array_map( 'absint', array_keys( $targets ) );
	}

	/**
	 * Turn a shortcode slug into a site URL, the same way mavo_hub_strip does:
	 * "france", "/france/" and "en/london-with-kids" all become /<path>/.
	 * A full URL is passed through and judged internal by the scanner.
	 */
	public static function shortcode_value_to_url( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '#^(https?:)?//#i', $value ) ) {
			return $value;
		}

		return home_url( '/' . trim( $value, '/' ) . '/' );
	}

	/**
	 * Does this child link back to the hub that owns it?
	 *
	 * A direct anchor and a link-back shortcode both count as linked. A link to
	 * an ancestor of the hub (Paris instead of Paris en famille) does not: it is
	 * reported separately so the editor can judge it.
	 *
	 * @return array{linked:bool,via:string,ancestor:int}
	 */
	public static function link_back_status( int $child_id, int $hub_id, string $type ): array {
		$child_id = absint( $child_id );
		$hub_id   = absint( $hub_id );

		$result = [ 'linked' => false, 'via' => self::LINK_NONE, 'ancestor' => 0 ];

		if ( ! $child_id || ! $hub_id ) {
			return $result;
		}

		$targets = self::outbound_targets( $child_id );

		if ( in_array( $hub_id, $targets['anchor'], true ) ) {
			return [ 'linked' => true, 'via' => self::LINK_ANCHOR, 'ancestor' => 0 ];
		}

		if ( in_array( $hub_id, $targets['shortcode'], true ) ) {
			return [ 'linked' => true, 'via' => self::LINK_SHORTCODE, 'ancestor' => 0 ];
		}

		$reachable = array_merge( $targets['anchor'], $targets['shortcode'] );

		foreach ( MHM_Model::get_hub_ancestors( $hub_id, $type ) as $ancestor ) {
			if ( in_array( (int) $ancestor, $reachable, true ) ) {
				$result['via']      = self::LINK_ANCESTOR;
				$result['ancestor'] = (int) $ancestor;
				break;
			}
		}

		return $result;
	}

	/* ------------------------------------------------------- query helpers */

	/** "This meta is absent, empty or zero" — get_primary_hub() reads all three as none. */
	private static function missing_meta_clause( string $key ): array {
		return [
			'relation' => 'OR',
			[ 'key' => $key, 'compare' => 'NOT EXISTS' ],
			[ 'key' => $key, 'value' => '', 'compare' => '=' ],
			[ 'key' => $key, 'value' => '0', 'compare' => '=' ],
		];
	}

	/**
	 * "This post is not itself a hub of these types."
	 *
	 * A top-level geographic hub legitimately has no geographic parent, so hubs
	 * are excluded from the missing-hub report by default.
	 */
	private static function not_hub_clause( array $types ): array {
		return [
			'relation' => 'OR',
			[ 'key' => MHM_Model::META_HUB_TYPE, 'compare' => 'NOT EXISTS' ],
			[ 'key' => MHM_Model::META_HUB_TYPE, 'value' => array_values( $types ), 'compare' => 'NOT IN' ],
		];
	}

	/**
	 * Ordering clauses for the view counter. Posts without the meta still
	 * appear — they sort last, because MySQL puts NULL last on DESC.
	 */
	private static function views_clause(): array {
		$key = self::views_meta_key();

		return [
			'relation'       => 'OR',
			'mhm_views'      => [ 'key' => $key, 'compare' => 'EXISTS', 'type' => 'NUMERIC' ],
			'mhm_views_none' => [ 'key' => $key, 'compare' => 'NOT EXISTS' ],
		];
	}

	/** Shared query scaffolding: post type, status, language, no caches we do not need. */
	private static function base_args( array $args ): array {
		$post_type = isset( $args['post_type'] ) ? (string) $args['post_type'] : 'any';
		$status    = isset( $args['status'] ) ? (string) $args['status'] : 'publish';

		$query = [
			'post_type'              => in_array( $post_type, MHM_Model::POST_TYPES, true ) ? [ $post_type ] : MHM_Model::POST_TYPES,
			'post_status'            => in_array( $status, [ 'publish', 'draft', 'pending', 'future', 'private' ], true ) ? $status : 'any',
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		];

		// Polylang filters admin queries by the admin language unless told
		// otherwise; '' means every language.
		$query['lang'] = isset( $args['lang'] ) ? (string) $args['lang'] : '';

		return $query;
	}

	/* --------------------------------------------------- 1. posts with no hub */

	/**
	 * Posts/pages with no primary hub, most viewed first.
	 *
	 * @param array $args mode: geo|theme|both|any, lang, post_type, status,
	 *                    paged, per_page, include_hubs.
	 * @return array{rows:int[],total:int,pages:int,paged:int,per_page:int,mode:string}
	 */
	public static function missing_hub( array $args = [] ): array {
		$mode = isset( $args['mode'] ) && in_array( $args['mode'], [ 'geo', 'theme', 'both', 'any' ], true )
			? (string) $args['mode']
			: 'geo';

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, absint( $args['per_page'] ) ) ) : self::DEFAULT_PER_PAGE;
		$paged    = isset( $args['paged'] ) ? max( 1, absint( $args['paged'] ) ) : 1;

		$geo     = self::missing_meta_clause( MHM_Model::META_GEO_HUB );
		$theme   = self::missing_meta_clause( MHM_Model::META_THEME_HUB );
		$missing = [];

		switch ( $mode ) {
			case 'geo':
				$missing = $geo;
				break;

			case 'theme':
				$missing = $theme;
				break;

			case 'both': // Missing both at once.
				$missing = [ 'relation' => 'AND', $geo, $theme ];
				break;

			case 'any': // Missing at least one of the two.
				$missing = [ 'relation' => 'OR', $geo, $theme ];
				break;
		}

		$meta_query = [
			'relation' => 'AND',
			self::views_clause(),
			$missing,
		];

		if ( empty( $args['include_hubs'] ) ) {
			$exclude = ( 'geo' === $mode || 'theme' === $mode ) ? [ $mode ] : MHM_Model::types();
			$meta_query[] = self::not_hub_clause( $exclude );
		}

		$query_args = array_merge(
			self::base_args( $args ),
			[
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery -- audit screen, on demand only.
				'orderby'        => [ 'mhm_views' => 'DESC', 'date' => 'DESC' ],
			]
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = (string) $args['search'];
		}

		$query = new WP_Query( $query_args );
		$total = (int) ( $query->found_posts ?? count( $query->posts ) );

		return [
			'rows'     => array_map( 'absint', (array) $query->posts ),
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per_page ),
			'paged'    => $paged,
			'per_page' => $per_page,
			'mode'     => $mode,
		];
	}

	/* ------------------------------------------- 2. children with no link back */

	/**
	 * Children that point at a hub whose page they never link back to.
	 *
	 * Reading post content is the expensive part, so this walks the assigned
	 * children in view-count order and only inspects one batch per run. The
	 * caller pages through with `offset`.
	 *
	 * @param array $args type: geo|theme|any, lang, post_type, status, offset, batch.
	 * @return array{rows:array,total:int,offset:int,batch:int,scanned:int,linked:int,ancestor_only:int}
	 */
	public static function no_link_back( array $args = [] ): array {
		$type   = isset( $args['type'] ) && MHM_Model::is_valid_type( (string) $args['type'] ) ? (string) $args['type'] : 'any';
		$batch  = isset( $args['batch'] ) ? max( 1, min( 500, absint( $args['batch'] ) ) ) : self::DEFAULT_BATCH;
		$offset = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) ) : 0;

		$types    = 'any' === $type ? MHM_Model::types() : [ $type ];
		$assigned = [ 'relation' => 'OR' ];

		foreach ( $types as $one ) {
			$assigned[] = [ 'key' => MHM_Model::meta_key_for_type( $one ), 'compare' => 'EXISTS' ];
		}

		$query_args = array_merge(
			self::base_args( $args ),
			[
				'posts_per_page' => $batch,
				'offset'         => $offset,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery -- audit screen, on demand only.
					'relation' => 'AND',
					self::views_clause(),
					$assigned,
				],
				'orderby'        => [ 'mhm_views' => 'DESC', 'date' => 'DESC' ],
			]
		);

		$query    = new WP_Query( $query_args );
		$children = array_map( 'absint', (array) $query->posts );
		$total    = (int) ( $query->found_posts ?? count( $children ) );

		$rows          = [];
		$linked        = 0;
		$ancestor_only = 0;

		foreach ( $children as $child_id ) {
			foreach ( $types as $one ) {
				$hub_id = MHM_Model::get_primary_hub( $child_id, $one );

				if ( null === $hub_id || $hub_id === $child_id ) {
					continue;
				}

				$status = self::link_back_status( $child_id, $hub_id, $one );

				if ( $status['linked'] ) {
					$linked++;
					continue;
				}

				if ( self::LINK_ANCESTOR === $status['via'] ) {
					$ancestor_only++;
				}

				$rows[] = [
					'child'    => $child_id,
					'type'     => $one,
					'hub'      => $hub_id,
					'via'      => $status['via'],
					'ancestor' => $status['ancestor'],
					'views'    => self::get_views( $child_id ),
					'missing'  => ! MHM_Model::get_eligible_post( $hub_id ),
				];
			}

			// Content of a scanned child is not needed again in this run.
			self::flush_target_cache();
		}

		return [
			'rows'          => $rows,
			'total'         => $total,
			'offset'        => $offset,
			'batch'         => $batch,
			'scanned'       => count( $children ),
			'linked'        => $linked,
			'ancestor_only' => $ancestor_only,
		];
	}

	/* --------------------------------------------------- 3. hub health report */

	/**
	 * One row per hub: parent, depth, direct children, and — when asked —
	 * the stale/unlinked comparison that the per-hub scanner shows.
	 *
	 * @param array $args type, lang, search, stale (bool).
	 * @return array{rows:array,summary:array}
	 */
	public static function hub_health( array $args = [] ): array {
		$hubs = MHM_Model::get_hubs(
			[
				'type'   => isset( $args['type'] ) ? (string) $args['type'] : '',
				'lang'   => isset( $args['lang'] ) ? (string) $args['lang'] : '',
				'search' => isset( $args['search'] ) ? (string) $args['search'] : '',
			]
		);

		$with_stale = ! empty( $args['stale'] );

		$rows    = [];
		$summary = [
			'hubs'          => 0,
			'geo'           => 0,
			'theme'         => 0,
			'no_children'   => 0,
			'top_level'     => 0,
			'with_issues'   => 0,
			'stale'         => 0,
			'unassigned'    => 0,
			'stale_scanned' => $with_stale,
		];

		foreach ( $hubs as $hub_id ) {
			$hub_id = (int) $hub_id;
			$post   = MHM_Model::get_eligible_post( $hub_id );
			$type   = MHM_Model::get_hub_type( $hub_id );

			if ( ! $post || null === $type ) {
				continue;
			}

			$children  = MHM_Model::get_hub_children( $hub_id, $type );
			$parent    = MHM_Model::get_primary_hub( $hub_id, $type );
			$ancestors = MHM_Model::get_hub_ancestors( $hub_id, $type );
			$issues    = MHM_Model::get_hub_issues( $hub_id );

			$row = [
				'hub'        => $hub_id,
				'type'       => $type,
				'status'     => $post->post_status,
				'post_type'  => $post->post_type,
				'lang'       => MHM_Model::get_language( $hub_id ),
				'parent'     => $parent,
				'depth'      => count( $ancestors ),
				'children'   => count( $children ),
				'issues'     => $issues,
				'stale'      => null,
				'unassigned' => null,
			];

			if ( $with_stale ) {
				$scan = MHM_Scanner::scan( $hub_id );

				if ( ! is_wp_error( $scan ) ) {
					$reverse           = MHM_Scanner::reverse_report( $hub_id, $type, $scan['linked_ids'] );
					$row['stale']      = count( $reverse['assigned_not_linked'] );
					$row['unassigned'] = count( $reverse['linked_not_assigned'] );

					$summary['stale']      += $row['stale'];
					$summary['unassigned'] += $row['unassigned'];
				}
			}

			$summary['hubs']++;
			$summary[ $type ]++;

			if ( ! $row['children'] ) {
				$summary['no_children']++;
			}
			if ( null === $parent ) {
				$summary['top_level']++;
			}
			if ( $issues ) {
				$summary['with_issues']++;
			}

			$rows[] = $row;
		}

		// Most broken first, then emptiest, then deepest — the rows an editor
		// actually needs to look at float to the top.
		usort(
			$rows,
			static function ( $a, $b ) {
				$cmp = count( $b['issues'] ) <=> count( $a['issues'] );
				$cmp = $cmp ?: ( (int) $b['stale'] <=> (int) $a['stale'] );
				$cmp = $cmp ?: ( $a['children'] <=> $b['children'] );

				return $cmp ?: strcasecmp( (string) get_the_title( $a['hub'] ), (string) get_the_title( $b['hub'] ) );
			}
		);

		self::flush_target_cache();

		return [ 'rows' => $rows, 'summary' => $summary ];
	}
}
