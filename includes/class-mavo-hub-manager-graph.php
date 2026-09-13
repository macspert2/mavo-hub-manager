<?php
/**
 * Draws one post's hub relations as inline SVG.
 *
 * The layout is deliberately crossing-free rather than clever. Everything is
 * laid out from one ordered bottom row:
 *
 *   [geo cousins] [geo siblings] [THE POST] [theme siblings] [theme cousins]
 *
 * Each parent is then centred over its own children, which puts the geographic
 * hub left of the post and the thematic hub right of it, their ancestors
 * stacked straight above them, and the hubs' sibling hubs out over the cousins
 * they own. No edge ever has to cross another.
 *
 * Coordinates are computed in PHP so the page needs no layout library; the only
 * script involved shows the hover card, and the graph is readable without it
 * (every node is a link, and its card is real markup referenced by
 * aria-describedby).
 *
 * Nodes carry a title and nothing else. Everything else — ID, type, status,
 * language, hub type, both primary hubs, views — is in the card.
 */

defined( 'ABSPATH' ) || exit;

class MHM_Graph {

	private const NODE_W = 150;
	private const NODE_H = 36;
	private const GAP_X  = 14;
	private const ROW_H  = 92;
	private const PAD    = 24;

	/** Slots of the bottom row, in drawing order. */
	private static array $slots = [];

	/** Positioned nodes and the lines between them. */
	private static array $nodes = [];
	private static array $edges = [];

	/**
	 * Render the whole figure for one graph model.
	 *
	 * @param array    $graph    From MHM_Audit::relation_graph().
	 * @param callable $node_url Given a post ID, the URL to re-centre on it.
	 */
	public static function render( array $graph, callable $node_url ): void {
		self::$slots = [];
		self::$nodes = [];
		self::$edges = [];

		$geo   = $graph['types']['geo'] ?? [];
		$theme = $graph['types']['theme'] ?? [];

		if ( empty( $geo['hub'] ) && empty( $theme['hub'] ) ) {
			echo '<p class="mhm-muted">' . esc_html__( 'This post has no valid primary hub of either type, so there is nothing to draw yet.', 'mavo-hub-manager' ) . '</p>';

			return;
		}

		// The bottom row, left to right. Empty groups still take a slot so the
		// parent above them has somewhere to sit.
		self::side_slots( $geo, 'geo', true );
		$post_slot = self::add_slot( (int) $graph['post'], 'post', '' );
		self::side_slots( $theme, 'theme', false );

		$depth  = max( count( $geo['ancestors'] ?? [] ), count( $theme['ancestors'] ?? [] ) );
		$hub_y  = self::row_y( $depth );
		$kid_y  = self::row_y( $depth + 1 );
		$width  = self::PAD * 2 + count( self::$slots ) * ( self::NODE_W + self::GAP_X ) - self::GAP_X;
		$height = $kid_y + self::NODE_H + self::PAD;

		// The post first: both hubs hang their subtree off it.
		self::place( (int) $graph['post'], self::slot_x( $post_slot ), $kid_y, 'focus', '' );

		self::place_side( $graph, $geo, 'geo', $depth, $hub_y, $kid_y );
		self::place_side( $graph, $theme, 'theme', $depth, $hub_y, $kid_y );

		self::draw( $graph, $width, $height, $node_url );
	}

	/* ------------------------------------------------------------- slotting */

	/**
	 * Bottom-row slots for one side.
	 *
	 * @param bool $outward_first True for the left side, where the cousins sit
	 *                            furthest out and the siblings nearest the post.
	 */
	private static function side_slots( array $side, string $type, bool $outward_first ): void {
		if ( empty( $side['hub'] ) ) {
			return; // Nothing on this side: no slots, no gap beside the post.
		}

		$groups = [];

		foreach ( (array) ( $side['aunts'] ?? [] ) as $index => $aunt ) {
			$groups[] = [ 'key' => 'aunt-' . $index, 'kind' => 'cousin', 'ids' => (array) $aunt['children'], 'more' => ! empty( $aunt['more'] ) ];
		}

		$siblings = [ 'key' => 'hub', 'kind' => 'sibling', 'ids' => (array) ( $side['siblings'] ?? [] ), 'more' => ! empty( $side['siblings_more'] ) ];

		$groups = $outward_first ? array_merge( $groups, [ $siblings ] ) : array_merge( [ $siblings ], array_reverse( $groups ) );

		foreach ( $groups as $group ) {
			$slots = [];

			foreach ( $group['ids'] as $id ) {
				$slots[] = self::add_slot( (int) $id, $group['kind'], $type );
			}

			if ( $group['more'] ) {
				$slots[] = self::add_slot( 0, 'more', $type );
			}

			if ( ! $slots ) {
				$slots[] = self::add_slot( 0, 'empty', $type ); // Keeps the parent positioned.
			}

			// Remember where each group sits, so its parent can be centred.
			foreach ( $slots as $slot ) {
				self::$slots[ $slot ]['group'] = $type . ':' . $group['key'];
			}
		}
	}

	private static function add_slot( int $id, string $kind, string $type ): int {
		self::$slots[] = [ 'id' => $id, 'kind' => $kind, 'type' => $type, 'group' => '' ];

		return count( self::$slots ) - 1;
	}

	private static function slot_x( int $index ): float {
		return self::PAD + $index * ( self::NODE_W + self::GAP_X );
	}

	/** The centre x of every slot belonging to one group. */
	private static function group_x( string $group ): float {
		$indexes = [];

		foreach ( self::$slots as $index => $slot ) {
			if ( $slot['group'] === $group ) {
				$indexes[] = $index;
			}
		}

		if ( ! $indexes ) {
			return self::PAD;
		}

		return ( self::slot_x( (int) min( $indexes ) ) + self::slot_x( (int) max( $indexes ) ) ) / 2;
	}

	private static function row_y( int $row ): float {
		return self::PAD + $row * self::ROW_H;
	}

	/* -------------------------------------------------------------- placing */

	/** One side's hub, its ancestors, its siblings, its aunts and cousins. */
	private static function place_side( array $graph, array $side, string $type, int $depth, float $hub_y, float $kid_y ): void {
		$hub = isset( $side['hub'] ) ? (int) $side['hub'] : 0;

		if ( ! $hub ) {
			return;
		}

		$hub_x = self::group_x( $type . ':hub' );

		self::place( $hub, $hub_x, $hub_y, 'hub', $type );
		self::edge( (int) $graph['post'], $hub, $type );

		// Siblings and the "more" pill hang under the hub.
		foreach ( self::$slots as $index => $slot ) {
			if ( $type . ':hub' !== $slot['group'] || 'empty' === $slot['kind'] ) {
				continue;
			}

			if ( 'more' === $slot['kind'] ) {
				self::place_pill( self::slot_x( $index ), $kid_y, $type, $hub );
				continue;
			}

			self::place( (int) $slot['id'], self::slot_x( $index ), $kid_y, 'sibling', $type );
			self::edge( (int) $slot['id'], $hub, $type );
		}

		// Ancestors: nearest one directly above the hub, then upwards.
		$previous = $hub;
		foreach ( (array) $side['ancestors'] as $step => $ancestor ) {
			$row = $depth - 1 - $step;

			self::place( (int) $ancestor, $hub_x, self::row_y( max( 0, $row ) ), 'ancestor', $type );
			self::edge( $previous, (int) $ancestor, $type );

			$previous = (int) $ancestor;
		}

		// The hub's own sibling hubs, out over the cousins they own.
		$grandparent = isset( $side['ancestors'][0] ) ? (int) $side['ancestors'][0] : 0;

		foreach ( (array) ( $side['aunts'] ?? [] ) as $index => $aunt ) {
			$group  = $type . ':aunt-' . $index;
			$aunt_x = self::group_x( $group );
			$aunt_id = (int) $aunt['hub'];

			self::place( $aunt_id, $aunt_x, $hub_y, 'aunt', $type );

			if ( $grandparent ) {
				self::edge( $aunt_id, $grandparent, $type );
			}

			foreach ( self::$slots as $slot_index => $slot ) {
				if ( $group !== $slot['group'] || 'empty' === $slot['kind'] ) {
					continue;
				}

				if ( 'more' === $slot['kind'] ) {
					self::place_pill( self::slot_x( $slot_index ), $kid_y, $type, $aunt_id );
					continue;
				}

				self::place( (int) $slot['id'], self::slot_x( $slot_index ), $kid_y, 'cousin', $type );
				self::edge( (int) $slot['id'], $aunt_id, $type );
			}
		}
	}

	private static function place( int $id, float $x, float $y, string $role, string $type ): void {
		if ( isset( self::$nodes[ $id ] ) ) {
			return; // A post that is both, say, an ancestor and an aunt is drawn once.
		}

		self::$nodes[ $id ] = [ 'id' => $id, 'x' => $x, 'y' => $y, 'role' => $role, 'type' => $type ];
	}

	private static function place_pill( float $x, float $y, string $type, int $parent ): void {
		$key = 'pill-' . count( self::$nodes );

		self::$nodes[ $key ] = [ 'id' => 0, 'x' => $x, 'y' => $y, 'role' => 'more', 'type' => $type ];
		self::$edges[]       = [ 'from' => $key, 'to' => $parent, 'type' => $type ];
	}

	private static function edge( $from, $to, string $type ): void {
		self::$edges[] = [ 'from' => $from, 'to' => $to, 'type' => $type ];
	}

	/* -------------------------------------------------------------- drawing */

	private static function draw( array $graph, float $width, float $height, callable $node_url ): void {
		printf(
			'<div class="mhm-graph-scroll"><svg class="mhm-graph" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" role="img" aria-label="%3$s">',
			(int) ceil( $width ),
			(int) ceil( $height ),
			esc_attr__( 'Hub relations of this post', 'mavo-hub-manager' )
		);

		foreach ( self::$edges as $edge ) {
			$from = self::$nodes[ $edge['from'] ] ?? null;
			$to   = self::$nodes[ $edge['to'] ] ?? null;

			if ( ! $from || ! $to ) {
				continue;
			}

			// stroke= and the fill=/text-anchor= below are presentation
			// attributes: any stylesheet rule outranks them, so they change
			// nothing when the CSS is there and keep the figure readable when
			// it is not (an SVG line has no stroke by default, and a rect is
			// black).
			printf(
				'<line class="mhm-edge mhm-edge--%s" x1="%s" y1="%s" x2="%s" y2="%s" stroke="#c3c4c7" stroke-width="1.5" />',
				esc_attr( $edge['type'] ?: 'none' ),
				esc_attr( (string) round( $from['x'] + self::NODE_W / 2, 1 ) ),
				esc_attr( (string) round( $from['y'], 1 ) ),
				esc_attr( (string) round( $to['x'] + self::NODE_W / 2, 1 ) ),
				esc_attr( (string) round( $to['y'] + self::NODE_H, 1 ) )
			);
		}

		foreach ( self::$nodes as $node ) {
			self::draw_node( $graph, $node, $node_url );
		}

		echo '</svg></div>';

		self::draw_cards( $graph );
	}

	private static function draw_node( array $graph, array $node, callable $node_url ): void {
		$id    = (int) $node['id'];
		$info  = $graph['nodes'][ $id ] ?? null;
		$class = 'mhm-node mhm-node--' . $node['role'] . ( $node['type'] ? ' mhm-node--' . $node['type'] : '' );

		if ( 'more' === $node['role'] ) {
			printf(
				'<g class="%s"><rect x="%s" y="%s" width="%d" height="%d" rx="6" fill="#f0f0f1" stroke="#a7aaad" />'
					. '<text x="%s" y="%s" text-anchor="middle" dominant-baseline="central" font-size="12">%s</text></g>',
				esc_attr( $class ),
				esc_attr( (string) $node['x'] ),
				esc_attr( (string) $node['y'] ),
				self::NODE_W,
				self::NODE_H,
				esc_attr( (string) ( $node['x'] + self::NODE_W / 2 ) ),
				esc_attr( (string) ( $node['y'] + self::NODE_H / 2 ) ),
				esc_html__( '+ more', 'mavo-hub-manager' )
			);

			return;
		}

		if ( ! $info ) {
			return;
		}

		$label = self::label( (string) $info['title'] );
		$tip   = 'mhm-tip-' . $id;

		$open  = sprintf(
			'<a class="%s" href="%s" data-mhm-node data-mhm-tip="%s" aria-describedby="%s"%s>',
			esc_attr( $class ),
			esc_url( (string) $node_url( $id ) ),
			esc_attr( $tip ),
			esc_attr( $tip ),
			'focus' === $node['role'] ? ' aria-current="true"' : ''
		);

		echo $open; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.

		printf(
			'<rect x="%s" y="%s" width="%d" height="%d" rx="6" fill="%s" stroke="#a7aaad" stroke-width="1.5" />',
			esc_attr( (string) $node['x'] ),
			esc_attr( (string) $node['y'] ),
			self::NODE_W,
			self::NODE_H,
			esc_attr( 'focus' === $node['role'] ? '#1d2327' : '#ffffff' )
		);

		printf(
			'<text x="%s" y="%s" text-anchor="middle" dominant-baseline="central" font-size="12" fill="%s">%s</text>',
			esc_attr( (string) ( $node['x'] + self::NODE_W / 2 ) ),
			esc_attr( (string) ( $node['y'] + self::NODE_H / 2 ) ),
			esc_attr( 'focus' === $node['role'] ? '#ffffff' : '#1d2327' ),
			esc_html( ( 'focus' === $node['role'] ? '▶ ' : '' ) . $label )
		);

		// Hub type as a letter as well as a colour: colour is never the only cue.
		if ( ! empty( $info['hub_type'] ) ) {
			printf(
				'<text class="mhm-node__badge" x="%s" y="%s" text-anchor="middle" font-size="10" font-weight="700">%s</text>',
				esc_attr( (string) ( $node['x'] + self::NODE_W - 9 ) ),
				esc_attr( (string) ( $node['y'] + 12 ) ),
				esc_html( 'geo' === $info['hub_type'] ? 'G' : 'T' )
			);
		}

		echo '</a>';
	}

	/** The hover/focus cards, real markup so assistive tech reads them too. */
	private static function draw_cards( array $graph ): void {
		echo '<div class="mhm-tips" hidden>';

		foreach ( $graph['nodes'] as $id => $info ) {
			printf( '<div id="mhm-tip-%d" role="tooltip">', (int) $id );

			printf( '<p class="mhm-tip-title">%s</p>', esc_html( (string) $info['title'] ) );

			if ( ! empty( $info['missing'] ) ) {
				echo '<p class="mhm-error-text">' . esc_html__( 'This post no longer exists.', 'mavo-hub-manager' ) . '</p></div>';
				continue;
			}

			echo '<dl>';
			self::card_row( __( 'ID', 'mavo-hub-manager' ), '#' . (int) $id );
			self::card_row( __( 'Type / status', 'mavo-hub-manager' ), $info['post_type'] . ' · ' . $info['status'] );

			if ( MHM_Model::has_polylang() ) {
				self::card_row( __( 'Language', 'mavo-hub-manager' ), $info['lang'] ?: __( 'none', 'mavo-hub-manager' ) );
			}

			self::card_row(
				__( 'Hub type', 'mavo-hub-manager' ),
				$info['hub_type'] ? MHM_Model::type_label( (string) $info['hub_type'] ) : __( 'not a hub', 'mavo-hub-manager' )
			);
			self::card_row( __( 'Primary geographic hub', 'mavo-hub-manager' ), self::hub_name( $info['geo_hub'] ) );
			self::card_row( __( 'Primary thematic hub', 'mavo-hub-manager' ), self::hub_name( $info['theme_hub'] ) );

			if ( null !== $info['views'] ) {
				self::card_row( __( 'Views', 'mavo-hub-manager' ), number_format_i18n( (int) $info['views'] ) );
			}

			echo '</dl>';

			printf(
				'<p class="mhm-tip-links"><a href="%s">%s</a> · <a href="%s">%s</a> · <span class="mhm-muted">%s</span></p>',
				esc_url( (string) get_edit_post_link( (int) $id ) ),
				esc_html__( 'Edit', 'mavo-hub-manager' ),
				esc_url( (string) get_permalink( (int) $id ) ),
				esc_html__( 'View', 'mavo-hub-manager' ),
				esc_html__( 'click the node to centre the graph here', 'mavo-hub-manager' )
			);

			echo '</div>';
		}

		echo '</div>';
	}

	private static function card_row( string $label, string $value ): void {
		printf( '<dt>%s</dt><dd>%s</dd>', esc_html( $label ), esc_html( $value ) );
	}

	private static function hub_name( $hub_id ): string {
		$hub_id = absint( (int) $hub_id );

		if ( ! $hub_id ) {
			return '—';
		}

		$title = get_the_title( $hub_id );

		return sprintf( '%s (#%d)', $title ?: __( 'missing', 'mavo-hub-manager' ), $hub_id );
	}

	/** Node labels stay short; the card carries the rest. */
	private static function label( string $title ): string {
		$title = trim( wp_strip_all_tags( $title ) );

		if ( '' === $title ) {
			return '—';
		}

		return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $title, 0, 20, '…' ) : substr( $title, 0, 20 );
	}
}
