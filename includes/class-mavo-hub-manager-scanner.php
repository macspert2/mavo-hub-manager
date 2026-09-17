<?php
/**
 * Internal-link scanner.
 *
 * Reads the stored post_content of one hub and classifies the posts/pages it
 * links to. The scan is a discovery signal only: it never writes anything, and
 * the source of truth stays the child's own primary-hub meta.
 *
 * Limitation, by design: shortcodes are NOT rendered. Links that only exist in
 * shortcode output are invisible here — use the manual child editor for those.
 * Rendering shortcodes in admin can have side effects and pollute global $post,
 * and the scanner must stay deterministic.
 */

defined( 'ABSPATH' ) || exit;

class MHM_Scanner {

	/* Classification states. */
	public const UNASSIGNED            = 'UNASSIGNED';
	public const ALREADY_ASSIGNED_HERE = 'ALREADY_ASSIGNED_HERE';
	public const CONFLICT              = 'CONFLICT';
	public const CROSS_LANGUAGE        = 'CROSS_LANGUAGE';
	public const INVALID_TARGET        = 'INVALID_TARGET';
	public const SELF                  = 'SELF';

	/**
	 * Scan one hub.
	 *
	 * @return array{
	 *   hub:int, type:string, rows:array, counts:array,
	 *   linked_ids:int[], anchors:int, resolved:int
	 * }|WP_Error
	 */
	public static function scan( int $hub_id ) {
		$hub_id = absint( $hub_id );
		$hub    = MHM_Model::get_eligible_post( $hub_id );
		$type   = MHM_Model::get_hub_type( $hub_id );

		if ( ! $hub || null === $type ) {
			return new WP_Error( 'mhm_not_a_hub', __( 'The selected post is not a hub.', 'mavo-hub-manager' ) );
		}

		$urls    = self::extract_links( (string) $hub->post_content );
		$targets = [];

		foreach ( $urls as $url ) {
			$target_id = self::resolve_url( $url );
			if ( $target_id ) {
				$targets[ $target_id ] = true; // Deduplicate target IDs.
			}
		}

		$rows = [];
		foreach ( array_keys( $targets ) as $target_id ) {
			$rows[] = self::classify( (int) $target_id, $hub_id, $type );
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				$order = [
					self::UNASSIGNED            => 0,
					self::CONFLICT              => 1,
					self::CROSS_LANGUAGE        => 2,
					self::ALREADY_ASSIGNED_HERE => 3,
					self::INVALID_TARGET        => 4,
					self::SELF                  => 5,
				];
				$cmp = ( $order[ $a['state'] ] ?? 9 ) <=> ( $order[ $b['state'] ] ?? 9 );

				return $cmp ?: strcasecmp( (string) $a['title'], (string) $b['title'] );
			}
		);

		$counts = array_fill_keys(
			[ self::UNASSIGNED, self::ALREADY_ASSIGNED_HERE, self::CONFLICT, self::CROSS_LANGUAGE, self::INVALID_TARGET, self::SELF ],
			0
		);
		foreach ( $rows as $row ) {
			$counts[ $row['state'] ]++;
		}

		return [
			'hub'        => $hub_id,
			'type'       => $type,
			'rows'       => $rows,
			'counts'     => $counts,
			'linked_ids' => array_map( 'absint', array_keys( $targets ) ),
			'anchors'    => count( $urls ),
			'resolved'   => count( $targets ),
		];
	}

	/**
	 * One linked target's state relative to the selected hub.
	 *
	 * @return array
	 */
	public static function classify( int $target_id, int $hub_id, string $type ): array {
		$target = MHM_Model::get_eligible_post( $target_id );

		$row = [
			'id'           => $target_id,
			'title'        => $target ? get_the_title( $target ) : sprintf( '#%d', $target_id ),
			'post_type'    => $target ? $target->post_type : '',
			'status'       => $target ? $target->post_status : '',
			'lang'         => MHM_Model::get_language( $target_id ),
			'state'        => self::INVALID_TARGET,
			'current_hub'  => null,
			'reason'       => '',
			'preselect'    => false,
			'assignable'   => false,
		];

		if ( $target_id === $hub_id ) {
			$row['state']  = self::SELF;
			$row['reason'] = __( 'The hub links to itself.', 'mavo-hub-manager' );

			return $row;
		}

		if ( ! $target ) {
			$row['reason'] = __( 'Not an existing post or page.', 'mavo-hub-manager' );

			return $row;
		}

		$current = MHM_Model::get_primary_hub( $target_id, $type );

		if ( $current === $hub_id ) {
			$row['state']       = self::ALREADY_ASSIGNED_HERE;
			$row['current_hub'] = self::hub_summary( $current );

			return $row;
		}

		// A relationship that could never be written is a diagnostic, not a
		// candidate — mostly cycles, since hub type and existence are known good.
		$valid = MHM_Model::validate_relationship( $target_id, $hub_id, $type );
		if ( is_wp_error( $valid ) ) {
			$row['state']       = self::INVALID_TARGET;
			$row['reason']      = $valid->get_error_message();
			$row['current_hub'] = $current ? self::hub_summary( $current ) : null;

			return $row;
		}

		if ( null !== $current ) {
			$row['state']       = self::CONFLICT;
			$row['current_hub'] = self::hub_summary( $current );
			$row['reason']      = __( 'Already has a different primary hub of this type.', 'mavo-hub-manager' );
			$row['assignable']  = true; // Only through the explicit "move" action.

			return $row;
		}

		if ( ! MHM_Model::is_same_language( $target_id, $hub_id ) ) {
			$row['state']      = self::CROSS_LANGUAGE;
			$row['reason']     = __( 'The link crosses Polylang languages; never assigned automatically.', 'mavo-hub-manager' );
			$row['assignable'] = true; // Only through an explicit manual assignment.

			return $row;
		}

		$row['state']      = self::UNASSIGNED;
		$row['assignable'] = true;
		$row['preselect']  = 'publish' === $target->post_status;

		if ( ! $row['preselect'] ) {
			$row['reason'] = __( 'Not published: assign it explicitly if you want it.', 'mavo-hub-manager' );
		}

		return $row;
	}

	/** Small display record for a hub referenced from a row. */
	public static function hub_summary( ?int $hub_id ): ?array {
		$hub_id = absint( (int) $hub_id );
		if ( ! $hub_id ) {
			return null;
		}

		$post = MHM_Model::get_eligible_post( $hub_id );

		return [
			'id'    => $hub_id,
			'title' => $post ? get_the_title( $post ) : sprintf( __( '(missing #%d)', 'mavo-hub-manager' ), $hub_id ),
			'lang'  => MHM_Model::get_language( $hub_id ),
			'type'  => MHM_Model::get_hub_type( $hub_id ),
		];
	}

	/* ------------------------------------------------------------ anchors */

	/**
	 * Every href on an <a> in the stored content, in document order.
	 * Prefers WP_HTML_Tag_Processor, falls back to DOMDocument, then regex.
	 *
	 * @return string[]
	 */
	public static function extract_links( string $content ): array {
		if ( '' === trim( $content ) ) {
			return [];
		}

		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$links     = [];
			$processor = new WP_HTML_Tag_Processor( $content );

			while ( $processor->next_tag( [ 'tag_name' => 'A' ] ) ) {
				$href = $processor->get_attribute( 'href' );
				if ( is_string( $href ) && '' !== trim( $href ) ) {
					$links[] = $href;
				}
			}

			return $links;
		}

		if ( class_exists( 'DOMDocument' ) ) {
			$dom      = new DOMDocument();
			$previous = libxml_use_internal_errors( true );

			$loaded = $dom->loadHTML(
				'<?xml encoding="utf-8" ?><div>' . $content . '</div>',
				defined( 'LIBXML_NOWARNING' ) ? LIBXML_NOWARNING | LIBXML_NOERROR : 0
			);

			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( $loaded ) {
				$links = [];
				foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
					$href = $anchor->getAttribute( 'href' );
					if ( '' !== trim( $href ) ) {
						$links[] = $href;
					}
				}

				return $links;
			}
		}

		// Last resort only.
		preg_match_all( '#<a\b[^>]*\bhref\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $content, $matches );

		return array_map(
			static fn( $raw ) => trim( $raw, "\"' " ),
			$matches[1] ?? []
		);
	}

	/* ---------------------------------------------------------- resolution */

	/**
	 * Resolve one href to an eligible post/page ID, or 0.
	 *
	 * Ignores external hosts, fragment-only links, mailto/tel/javascript, and
	 * anything that does not resolve to a `post` or `page`.
	 */
	public static function resolve_url( string $url ): int {
		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $url || str_starts_with( $url, '#' ) ) {
			return 0;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( $scheme && ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return 0; // mailto:, tel:, javascript:, data:, …
		}

		$absolute = self::to_internal_absolute_url( $url );
		if ( '' === $absolute ) {
			return 0;
		}

		// Strip the fragment before resolving; keep the query (?p=, ?page_id=).
		$absolute = strtok( $absolute, '#' );

		$post_id = absint( url_to_postid( $absolute ) );

		if ( ! $post_id ) {
			$post_id = self::resolve_by_path( $absolute );
		}

		return MHM_Model::get_eligible_post( $post_id ) ? $post_id : 0;
	}

	/**
	 * Make an href absolute against the site, and return '' when it points
	 * somewhere other than this site. mamanvoyage.com and www.mamanvoyage.com
	 * are the same site.
	 */
	public static function to_internal_absolute_url( string $url ): string {
		$home  = home_url( '/' );
		$parts = wp_parse_url( $url );

		if ( false === $parts ) {
			return '';
		}

		// Protocol-relative.
		if ( str_starts_with( $url, '//' ) ) {
			$url   = ( is_ssl() ? 'https:' : 'http:' ) . $url;
			$parts = wp_parse_url( $url );
		}

		$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

		if ( '' === $host ) {
			// Relative URL: resolve against the site root.
			$path = $parts['path'] ?? '';
			if ( '' === $path && empty( $parts['query'] ) ) {
				return '';
			}

			$relative = ( str_starts_with( $path, '/' ) ? $path : '/' . $path )
				. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

			return untrailingslashit( $home ) . $relative;
		}

		if ( ! self::is_internal_host( $host ) ) {
			return '';
		}

		return $url;
	}

	/** Is this host the site itself, ignoring a leading www.? */
	public static function is_internal_host( string $host ): bool {
		$host      = strtolower( ltrim( $host, '.' ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		$bare = static fn( string $h ): string => preg_replace( '/^www\./', '', $h );

		$hosts = [ $bare( $site_host ) ];

		/**
		 * Extra hostnames that should count as this site (staging, legacy domains).
		 *
		 * @param string[] $hosts Bare hostnames, without a leading www.
		 */
		$hosts = (array) apply_filters( 'mavo_hub_manager_internal_hosts', $hosts );
		$hosts = array_map( static fn( $h ) => $bare( strtolower( (string) $h ) ), $hosts );

		return in_array( $bare( $host ), $hosts, true );
	}

	/**
	 * Fallback for permalinks url_to_postid() cannot parse (some page paths on
	 * unusual permalink structures).
	 */
	private static function resolve_by_path( string $absolute_url ): int {
		$path = (string) wp_parse_url( $absolute_url, PHP_URL_PATH );
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return 0;
		}

		$page = get_page_by_path( $path, OBJECT, MHM_Model::POST_TYPES );
		if ( $page instanceof WP_Post ) {
			return (int) $page->ID;
		}

		// Dated permalinks: the last segment is usually the post slug.
		$segments = explode( '/', $path );
		$slug     = (string) end( $segments );

		if ( '' === $slug ) {
			return 0;
		}

		$found = get_posts(
			[
				'name'                   => $slug,
				'post_type'              => MHM_Model::POST_TYPES,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'lang'                   => '',
			]
		);

		return $found ? absint( $found[0] ) : 0;
	}

	/* ---------------------------------------------- reverse / stale grouping */

	/**
	 * Compare the stored children of a hub with the targets it currently links
	 * to. Nothing is written or removed: "assigned but no longer linked" is a
	 * warning the admin resolves by hand.
	 *
	 * @return array{linked_assigned:int[],linked_not_assigned:int[],assigned_not_linked:int[]}
	 */
	public static function reverse_report( int $hub_id, string $type, array $linked_ids ): array {
		// Editorial view: a draft child is still assigned to this hub, and the
		// admin comparing stored children against linked ones needs to see it.
		$stored     = MHM_Model::get_hub_children( $hub_id, $type, [ 'post_status' => MHM_Model::EDITORIAL_STATUSES ] );
		$linked_ids = array_map( 'absint', $linked_ids );

		return [
			'linked_assigned'     => array_values( array_intersect( $linked_ids, $stored ) ),
			'linked_not_assigned' => array_values( array_diff( $linked_ids, $stored ) ),
			'assigned_not_linked' => array_values( array_diff( $stored, $linked_ids ) ),
		];
	}
}
