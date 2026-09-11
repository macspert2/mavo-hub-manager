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
 *
 * ---------------------------------------------------------------------------
 * Cost rules, learned the hard way — these reports run over the whole site:
 *
 *   * Every meta condition is AND'ed and uses EXISTS / NOT EXISTS only. An OR
 *     group over postmeta makes WordPress emit one LEFT JOIN per branch and
 *     MySQL then joins them against each other; three such groups is enough to
 *     hang the request.
 *   * No SQL_CALC_FOUND_ROWS: 'no_found_rows' is always on and one extra row is
 *     fetched to answer "is there a next page?".
 *   * The link-back check never resolves a URL to an ID. url_to_postid() runs
 *     the rewrite rules plus a query per link, which is thousands of queries
 *     for one batch. Links are compared to the hub's own permalink instead —
 *     see url_keys() — which costs nothing.
 * ---------------------------------------------------------------------------
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

	/** Per-request caches: identity keys per post, link keys per post. */
	private static array $post_keys_cache = [];
	private static array $link_keys_cache = [];

	/* ------------------------------------------------------------ settings */

	/**
	 * Meta key holding the view counter used for ordering.
	 *
	 * Maman Voyage stores it as plain `views` (see mavo-geo-explorer and
	 * postsByTagOrderedByViews).
	 */
	public static function views_meta_key(): string {
		return (string) apply_filters( 'mavo_hub_manager_views_meta_key', 'views' );
	}

	public static function get_views( int $post_id ): ?int {
		$raw = get_post_meta( absint( $post_id ), self::views_meta_key(), true );

		return ( '' === $raw || null === $raw ) ? null : (int) $raw;
	}

	/**
	 * Shortcodes that render a link back to a hub, as tag => attribute.
	 *
	 * `[mavo_hub_strip slug="france" text="…{guide France}…"]` renders a link
	 * back to /france/ on the frontend, so a post carrying it is NOT missing a
	 * link back — even though the stored content contains no <a href>.
	 *
	 * The attribute names where the shortcode keeps its target. An empty one
	 * means the shortcode has no target attribute at all and always links to
	 * the post's own hubs — `[geo_related]` from mavo-for-you, whose block
	 * places the post's primary hubs as its first cards. Such a tag must also
	 * be listed in link_back_hub_shortcodes() for the hub lookup to happen.
	 *
	 * No shortcode has to be registered, and none is ever rendered: the stored
	 * content is parsed as text, exactly like the internal-link scanner.
	 */
	public static function link_back_shortcodes(): array {
		return (array) apply_filters(
			'mavo_hub_manager_link_back_shortcodes',
			[
				'mavo_hub_strip' => 'slug',
				'geo_related'    => '',
			]
		);
	}

	/**
	 * Link-back shortcodes whose target attribute is optional, as a tag list.
	 *
	 * Since the hub hierarchy exists, `[mavo_hub_strip]` needs no slug: it
	 * links to the post's own primary hubs. Reading only the slug attribute
	 * would report exactly those posts as missing a link back, so a slugless
	 * occurrence is resolved against the child's own hub meta instead.
	 *
	 * Which hubs it points at is read from the shortcode the same way the
	 * shortcode itself reads it, still without rendering anything:
	 *
	 *   [mavo_hub_strip]                     both primary hubs
	 *   [mavo_hub_strip hub="geo"]           the geographic hub only
	 *   [mavo_hub_strip text="…{geo:…}…"]    the types its markers name
	 *   [geo_related]                        both primary hubs
	 *
	 * `[geo_related]` takes no target attribute: its block leads with the
	 * post's own hub cards, so carrying it counts as a link back to both
	 * primary hubs. It is a recommendation block, though, not a fixed link —
	 * the hub card can be crowded out by the block's own limits, and the
	 * frontend may swap the block for personalized picks — so this is a
	 * deliberate editorial decision, not a guarantee the link is on the page.
	 *
	 * @return string[]
	 */
	public static function link_back_hub_shortcodes(): array {
		return array_values( array_filter( array_map(
			'strval',
			(array) apply_filters(
				'mavo_hub_manager_link_back_hub_shortcodes',
				[ 'mavo_hub_strip', 'geo_related' ]
			)
		) ) );
	}

	/**
	 * The hub types one slugless link-back shortcode points at.
	 *
	 * @param array $atts Parsed shortcode attributes.
	 * @return string[] Any of 'geo', 'theme'.
	 */
	public static function shortcode_hub_types( array $atts ): array {
		$text = isset( $atts['text'] ) ? (string) $atts['text'] : '';

		// Sentence mode: the {geo:…} / {theme:…} markers decide, not `hub`.
		if ( '' !== trim( $text ) ) {
			$found = [];

			if ( preg_match_all( '/\{(geo|theme)\s*:/i', $text, $matches ) ) {
				foreach ( $matches[1] as $marker ) {
					$found[ strtolower( (string) $marker ) ] = true;
				}
			}

			return array_keys( $found );
		}

		$hub = isset( $atts['hub'] ) ? strtolower( trim( (string) $atts['hub'] ) ) : '';

		if ( MHM_Model::is_valid_type( $hub ) ) {
			return [ $hub ];
		}

		return ( '' === $hub || 'both' === $hub ) ? MHM_Model::types() : [];
	}

	/* ------------------------------------------------------- link matching */

	/**
	 * Comparable keys for one href, without touching the database.
	 *
	 * A link and a post match when they share any key:
	 *
	 *   path:/paris-en-famille/   the normalised site path
	 *   slug:paris-en-famille     the last path segment, so a dated permalink
	 *                             (/2024/05/slug/) matches a plain one
	 *   id:1234                   ?p=/?page_id=/?post= links
	 *
	 * External, fragment-only, mailto/tel/javascript links produce no keys.
	 *
	 * @return string[]
	 */
	public static function url_keys( string $url ): array {
		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $url || str_starts_with( $url, '#' ) ) {
			return [];
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( $scheme && ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return [];
		}

		$absolute = MHM_Scanner::to_internal_absolute_url( $url );
		if ( '' === $absolute ) {
			return [];
		}

		$parts = wp_parse_url( $absolute );
		if ( ! is_array( $parts ) ) {
			return [];
		}

		$keys = [];
		$path = isset( $parts['path'] ) ? strtolower( rawurldecode( (string) $parts['path'] ) ) : '';
		$path = trim( $path, '/' );

		if ( '' !== $path ) {
			$keys[] = 'path:/' . $path . '/';

			$segments = array_values( array_filter( explode( '/', $path ) ) );
			$slug     = (string) end( $segments );

			// A trailing /page/2/ or /amp/ still points at the same object.
			if ( in_array( $slug, [ 'amp', 'feed' ], true ) || ctype_digit( $slug ) ) {
				array_pop( $segments );
				$slug = (string) end( $segments );
			}

			if ( '' !== $slug ) {
				$keys[] = 'slug:' . $slug;
			}
		}

		if ( ! empty( $parts['query'] ) ) {
			$query = [];
			parse_str( (string) $parts['query'], $query );

			foreach ( [ 'p', 'page_id', 'post' ] as $arg ) {
				if ( ! empty( $query[ $arg ] ) && ctype_digit( (string) $query[ $arg ] ) ) {
					$keys[] = 'id:' . (int) $query[ $arg ];
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * The keys that identify one post as a link target.
	 *
	 * @return string[]
	 */
	public static function post_keys( int $post_id ): array {
		$post_id = absint( $post_id );

		if ( isset( self::$post_keys_cache[ $post_id ] ) ) {
			return self::$post_keys_cache[ $post_id ];
		}

		$post = MHM_Model::get_eligible_post( $post_id );
		if ( ! $post ) {
			return self::$post_keys_cache[ $post_id ] = [];
		}

		$keys = [ 'id:' . $post_id ];

		$permalink = (string) get_permalink( $post_id );
		if ( '' !== $permalink ) {
			$keys = array_merge( $keys, self::url_keys( $permalink ) );
		}

		if ( '' !== (string) $post->post_name ) {
			$keys[] = 'slug:' . strtolower( (string) $post->post_name );
		}

		return self::$post_keys_cache[ $post_id ] = array_values( array_unique( $keys ) );
	}

	/**
	 * Every link key this post points at, split by how it links.
	 *
	 * @return array{anchor:string[],shortcode:string[]}
	 */
	public static function outbound_link_keys( int $post_id ): array {
		$post_id = absint( $post_id );

		if ( isset( self::$link_keys_cache[ $post_id ] ) ) {
			return self::$link_keys_cache[ $post_id ];
		}

		$post    = MHM_Model::get_eligible_post( $post_id );
		$content = $post ? (string) $post->post_content : '';

		$anchor = [];
		foreach ( MHM_Scanner::extract_links( $content ) as $url ) {
			foreach ( self::url_keys( $url ) as $key ) {
				$anchor[ $key ] = true;
			}
		}

		$shortcode = [];
		foreach ( self::shortcode_link_keys( $content, $post_id ) as $key ) {
			$shortcode[ $key ] = true;
		}

		return self::$link_keys_cache[ $post_id ] = [
			'anchor'    => array_keys( $anchor ),
			'shortcode' => array_keys( $shortcode ),
		];
	}

	/** Drop the per-post caches so a long batch does not hold every post. */
	public static function flush_caches(): void {
		self::$link_keys_cache = [];
		self::$post_keys_cache = [];
	}

	/**
	 * Link keys referenced by link-back shortcodes in stored content.
	 *
	 * @param string $content Stored post content.
	 * @param int    $post_id The post the content belongs to. Needed only to
	 *                        resolve slugless shortcodes against its own hubs.
	 * @return string[]
	 */
	public static function shortcode_link_keys( string $content, int $post_id = 0 ): array {
		if ( '' === trim( $content ) ) {
			return [];
		}

		$post_id   = absint( $post_id );
		$implicit  = self::link_back_hub_shortcodes();
		$keys      = [];

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

			$resolves_hubs = $post_id && in_array( $tag, $implicit, true );

			foreach ( $matches[1] as $raw_atts ) {
				$atts  = shortcode_parse_atts( $raw_atts );
				$atts  = is_array( $atts ) ? $atts : [];
				$value = ( '' !== $attribute && isset( $atts[ $attribute ] ) ) ? trim( (string) $atts[ $attribute ] ) : '';

				if ( '' === $value ) {
					if ( $resolves_hubs ) {
						foreach ( self::shortcode_hub_types( $atts ) as $type ) {
							$hub_id = MHM_Model::get_primary_hub( $post_id, $type );

							if ( $hub_id ) {
								$keys[ 'id:' . (int) $hub_id ] = true;
							}
						}
					}

					continue;
				}

				foreach ( self::url_keys( self::shortcode_value_to_url( $value ) ) as $key ) {
					$keys[ $key ] = true;
				}
			}
		}

		return array_keys( $keys );
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
	 * Pure PHP — the child's content and the hub's permalink are all it needs.
	 *
	 * @return array{linked:bool,via:string,ancestor:int}
	 */
	public static function link_back_status( int $child_id, int $hub_id, string $type ): array {
		$child_id = absint( $child_id );
		$hub_id   = absint( $hub_id );

		$result = [ 'linked' => false, 'via' => self::LINK_NONE, 'ancestor' => 0 ];

		if ( ! $child_id || ! $hub_id || $child_id === $hub_id ) {
			return $result;
		}

		$links    = self::outbound_link_keys( $child_id );
		$hub_keys = self::post_keys( $hub_id );

		if ( ! $hub_keys ) {
			return $result; // The hub no longer exists; the errors tab owns that case.
		}

		if ( array_intersect( $hub_keys, $links['anchor'] ) ) {
			return [ 'linked' => true, 'via' => self::LINK_ANCHOR, 'ancestor' => 0 ];
		}

		if ( array_intersect( $hub_keys, $links['shortcode'] ) ) {
			return [ 'linked' => true, 'via' => self::LINK_SHORTCODE, 'ancestor' => 0 ];
		}

		$reachable = array_merge( $links['anchor'], $links['shortcode'] );
		if ( ! $reachable ) {
			return $result;
		}

		foreach ( MHM_Model::get_hub_ancestors( $hub_id, $type ) as $ancestor ) {
			if ( array_intersect( self::post_keys( (int) $ancestor ), $reachable ) ) {
				$result['via']      = self::LINK_ANCESTOR;
				$result['ancestor'] = (int) $ancestor;
				break;
			}
		}

		return $result;
	}

	/* ------------------------------------------------------- query helpers */

	/**
	 * Shared query scaffolding.
	 *
	 * `no_found_rows` is deliberate: the reports page with prev/next links
	 * instead of a total, because counting every matching row is exactly the
	 * part that does not scale.
	 */
	private static function base_args( array $args ): array {
		$post_type = isset( $args['post_type'] ) ? (string) $args['post_type'] : 'any';
		$status    = isset( $args['status'] ) ? (string) $args['status'] : 'publish';

		$query = [
			'post_type'              => in_array( $post_type, MHM_Model::POST_TYPES, true ) ? [ $post_type ] : MHM_Model::POST_TYPES,
			'post_status'            => in_array( $status, [ 'publish', 'draft', 'pending', 'future', 'private' ], true ) ? $status : 'any',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		];

		// Polylang filters admin queries by the admin language unless told
		// otherwise; '' means every language.
		$query['lang'] = isset( $args['lang'] ) ? (string) $args['lang'] : '';

		return $query;
	}

	/**
	 * Ordering. 'views' inner-joins the counter — which means posts that have
	 * no counter at all are not in that list; 'date' is the way to see those.
	 */
	private static function order_args( string $sort ): array {
		if ( 'views' === $sort ) {
			return [
				'clause'  => [ 'key' => self::views_meta_key(), 'compare' => 'EXISTS', 'type' => 'NUMERIC' ],
				'orderby' => [ 'mhm_views' => 'DESC' ],
			];
		}

		return [ 'clause' => null, 'orderby' => [ 'date' => 'DESC' ] ];
	}

	/** Warm the post and meta caches for one page of results in two queries. */
	private static function prime( array $post_ids ): void {
		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );

		if ( ! $post_ids ) {
			return;
		}

		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $post_ids, false, false );
		}
		if ( function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $post_ids );
		}
	}

	/* --------------------------------------------------- 1. posts with no hub */

	/**
	 * Posts/pages with no primary hub.
	 *
	 * @param array $args mode: geo|theme|both, sort: views|date, lang,
	 *                    post_type, status, paged, per_page, include_hubs.
	 * @return array{rows:int[],paged:int,per_page:int,has_more:bool,mode:string,sort:string}
	 */
	public static function missing_hub( array $args = [] ): array {
		$mode = isset( $args['mode'] ) && in_array( $args['mode'], [ 'geo', 'theme', 'both' ], true )
			? (string) $args['mode']
			: 'geo';
		$sort = isset( $args['sort'] ) && 'date' === $args['sort'] ? 'date' : 'views';

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, absint( $args['per_page'] ) ) ) : self::DEFAULT_PER_PAGE;
		$paged    = isset( $args['paged'] ) ? max( 1, absint( $args['paged'] ) ) : 1;

		$meta_query = [ 'relation' => 'AND' ];
		$order      = self::order_args( $sort );

		if ( $order['clause'] ) {
			$meta_query['mhm_views'] = $order['clause'];
		}

		// One NOT EXISTS per key, AND'ed. A stored empty or 0 value would slip
		// through here; the Relationship errors tab is where those show up.
		foreach ( ( 'both' === $mode ? MHM_Model::types() : [ $mode ] ) as $type ) {
			$meta_query[] = [ 'key' => MHM_Model::meta_key_for_type( $type ), 'compare' => 'NOT EXISTS' ];
		}

		// Hubs are hidden by default: a top-level hub legitimately has no parent.
		if ( empty( $args['include_hubs'] ) ) {
			$meta_query[] = [ 'key' => MHM_Model::META_HUB_TYPE, 'compare' => 'NOT EXISTS' ];
		}

		$query_args = array_merge(
			self::base_args( $args ),
			[
				// One extra row answers "is there a next page?" without counting.
				'posts_per_page' => $per_page + 1,
				'offset'         => ( $paged - 1 ) * $per_page,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery -- on-demand audit screen.
				'orderby'        => $order['orderby'],
			]
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = (string) $args['search'];
		}

		$rows     = array_map( 'absint', (array) ( new WP_Query( $query_args ) )->posts );
		$has_more = count( $rows ) > $per_page;
		$rows     = array_slice( $rows, 0, $per_page );

		self::prime( $rows );

		return [
			'rows'     => $rows,
			'paged'    => $paged,
			'per_page' => $per_page,
			'has_more' => $has_more,
			'mode'     => $mode,
			'sort'     => $sort,
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
	 * @param array $args type: geo|theme, sort, lang, post_type, status, offset, batch.
	 * @return array{rows:array,offset:int,batch:int,scanned:int,linked:int,ancestor_only:int,has_more:bool,type:string,sort:string}
	 */
	public static function no_link_back( array $args = [] ): array {
		$type = isset( $args['type'] ) && MHM_Model::is_valid_type( (string) $args['type'] ) ? (string) $args['type'] : 'geo';
		$sort = isset( $args['sort'] ) && 'date' === $args['sort'] ? 'date' : 'views';

		$batch  = isset( $args['batch'] ) ? max( 1, min( 500, absint( $args['batch'] ) ) ) : self::DEFAULT_BATCH;
		$offset = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) ) : 0;

		$order      = self::order_args( $sort );
		$meta_query = [
			'relation' => 'AND',
			[ 'key' => MHM_Model::meta_key_for_type( $type ), 'compare' => 'EXISTS' ],
		];

		if ( $order['clause'] ) {
			$meta_query['mhm_views'] = $order['clause'];
		}

		$query_args = array_merge(
			self::base_args( $args ),
			[
				'posts_per_page' => $batch + 1,
				'offset'         => $offset,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery -- on-demand audit screen.
				'orderby'        => $order['orderby'],
			]
		);

		$children = array_map( 'absint', (array) ( new WP_Query( $query_args ) )->posts );
		$has_more = count( $children ) > $batch;
		$children = array_slice( $children, 0, $batch );

		// Two queries for the whole batch instead of two per row.
		self::prime( $children );

		$rows          = [];
		$linked        = 0;
		$ancestor_only = 0;

		foreach ( $children as $child_id ) {
			$hub_id = MHM_Model::get_primary_hub( $child_id, $type );

			if ( null === $hub_id || $hub_id === $child_id ) {
				continue;
			}

			$status = self::link_back_status( $child_id, $hub_id, $type );

			if ( $status['linked'] ) {
				$linked++;
				continue;
			}

			if ( self::LINK_ANCESTOR === $status['via'] ) {
				$ancestor_only++;
			}

			$rows[] = [
				'child'    => $child_id,
				'type'     => $type,
				'hub'      => $hub_id,
				'via'      => $status['via'],
				'ancestor' => $status['ancestor'],
				'views'    => self::get_views( $child_id ),
				'missing'  => ! MHM_Model::get_eligible_post( $hub_id ),
			];
		}

		self::flush_caches();

		return [
			'rows'          => $rows,
			'offset'        => $offset,
			'batch'         => $batch,
			'scanned'       => count( $children ),
			'linked'        => $linked,
			'ancestor_only' => $ancestor_only,
			'has_more'      => $has_more,
			'type'          => $type,
			'sort'          => $sort,
		];
	}

	/* --------------------------------------------------- 3. hub health report */

	/**
	 * Direct child counts for every hub at once, as hub ID => count.
	 *
	 * One grouped query replaces one query per hub. Returns null when there is
	 * no $wpdb to ask — the caller then counts hub by hub.
	 */
	public static function child_count_map( string $type ): ?array {
		global $wpdb;

		$key = MHM_Model::meta_key_for_type( $type );

		if ( ! $key || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return null;
		}

		$placeholders = implode( ', ', array_fill( 0, count( MHM_Model::POST_TYPES ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and generated placeholders.
				"SELECT pm.meta_value AS hub, COUNT(*) AS total
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND p.post_type IN ( {$placeholders} )
				 GROUP BY pm.meta_value",
				// phpcs:enable
				array_merge( [ $key ], MHM_Model::POST_TYPES )
			)
		);

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ absint( $row->hub ) ] = (int) $row->total;
		}

		return $map;
	}

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

		// Hubs and their ancestors are read repeatedly below; prime once.
		self::prime( $hubs );

		$with_stale = ! empty( $args['stale'] );
		$counts     = [];
		foreach ( MHM_Model::types() as $one ) {
			$counts[ $one ] = self::child_count_map( $one );
		}

		$rows    = [];
		$titles  = [];
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

			$children = null === $counts[ $type ]
				? MHM_Model::count_hub_children( $hub_id, $type )
				: (int) ( $counts[ $type ][ $hub_id ] ?? 0 );

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
				'children'   => $children,
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

			if ( ! $children ) {
				$summary['no_children']++;
			}
			if ( null === $parent ) {
				$summary['top_level']++;
			}
			if ( $issues ) {
				$summary['with_issues']++;
			}

			$titles[ $hub_id ] = (string) get_the_title( $hub_id );
			$rows[]            = $row;
		}

		// Most broken first, then emptiest, then deepest — the rows an editor
		// actually needs to look at float to the top.
		usort(
			$rows,
			static function ( $a, $b ) use ( $titles ) {
				$cmp = count( $b['issues'] ) <=> count( $a['issues'] );
				$cmp = $cmp ?: ( (int) $b['stale'] <=> (int) $a['stale'] );
				$cmp = $cmp ?: ( $a['children'] <=> $b['children'] );

				return $cmp ?: strcasecmp( $titles[ $a['hub'] ] ?? '', $titles[ $b['hub'] ] ?? '' );
			}
		);

		self::flush_caches();

		return [ 'rows' => $rows, 'summary' => $summary ];
	}
}
