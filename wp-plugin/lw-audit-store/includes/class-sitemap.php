<?php
/**
 * LW_Sitemap — Visual Sitemap Generator.
 *
 * PHP port of builds/visual-sitemap/netlify/functions/sitemap.js. Same response
 * shape ({ preview, fullReport }) so the React frontend stays identical
 * regardless of which backend serves the request.
 *
 * Flow:
 *   generate( $url )
 *     → BFS crawl up to MAX_PAGES, max MAX_DEPTH hops, tracking parent + title
 *     → If only the root is reachable, try sitemap fallback (Cloudflare-blocked)
 *     → build_sitemap_structures() → flat nodes + directed edges + nested tree
 *     → format_output() → { preview, fullReport }
 *
 * Reuse model:
 *   This class extends LW_Audit_Crawler purely to share the proven URL / fetch /
 *   parse / sitemap-fetch helpers (normalise, fetch_pages_parallel, parse_page,
 *   detect_wordpress, fetch_sitemap_urls, …) rather than duplicate ~185 lines —
 *   the JS side does the same via shared/crawler-core.js. The crawl here differs
 *   from LW_Audit_Crawler::crawl() (parent tracking + title + tree construction),
 *   so it has its own crawl methods; everything below the network/parse layer is
 *   inherited.
 *
 * Ports two upstream fixes from sitemap.js:
 *   B1 — sitemap fallback injects a synthetic root when the sitemap omits the
 *        homepage (most Yoast WP sitemaps do) so the tree always has an anchor.
 *   B2 — BFS loop guard tightened to MAX_TIME_S - FETCH_TIMEOUT so a batch can
 *        never start with less headroom than one worst-case fetch.
 *
 * @package LW_Audit_Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LW_Sitemap extends LW_Audit_Crawler {

	/** Sitemap-fallback page cap (matches sitemap.js `.slice(0, 50)`). */
	const SITEMAP_FALLBACK_LIMIT = 50;

	/**
	 * Public entry point.
	 *
	 * @param string $raw_url
	 * @return array|WP_Error { preview, fullReport } on success.
	 */
	public static function generate( $raw_url ) {
		$raw_url = is_string( $raw_url ) ? trim( $raw_url ) : '';
		if ( '' === $raw_url ) {
			return new WP_Error( 'lw_no_url', 'Missing url parameter', array( 'status' => 400 ) );
		}

		if ( ! preg_match( '#^https?://#i', $raw_url ) ) {
			$raw_url = 'https://' . $raw_url;
		}

		$parts = wp_parse_url( $raw_url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'lw_bad_url', 'Invalid URL', array( 'status' => 400 ) );
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'lw_bad_scheme', 'URL must be http or https', array( 'status' => 400 ) );
		}

		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . intval( $parts['port'] );
		}
		$start_url = $origin . '/';

		$start_time   = microtime( true );
		$crawl_result = self::sitemap_crawl( $start_url, $origin, $start_time );
		$graph        = $crawl_result['visited'];
		$meta         = $crawl_result['meta'];

		// Sitemap fallback for Cloudflare/bot-blocked sites. Skip if < 15s
		// remains (matches sitemap.js:408) — sitemap fetch + page crawls need
		// at least that, and running it after a near-full BFS would blow past
		// the REST handler's set_time_limit.
		if ( count( $graph ) <= 1 && ( microtime( true ) - $start_time ) < ( self::MAX_TIME_S - 15 ) ) {
			$sitemap = self::sitemap_via_sitemap( $origin, $start_time );
			if ( $sitemap && count( $sitemap['visited'] ) > 1 ) {
				$graph = $sitemap['visited'];
				$meta  = $sitemap['meta'];
			}
		}

		if ( count( $graph ) === 0 ) {
			return new WP_Error(
				'lw_blocked',
				'Could not crawl this site. It may be blocking crawlers or require JavaScript to render.',
				array( 'status' => 200 )
			);
		}

		return self::format_output( $graph, $meta );
	}

	// ─── Sitemap-only URL helpers ──────────────────────────────────────────

	/** URL slug → human-readable label. Port of crawler-core.js slugToLabel. */
	protected static function slug_to_label( $url ) {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( '/' === $path || '' === $path ) {
			return 'Home';
		}
		$path  = rtrim( $path, '/' );
		$segs  = array_values( array_filter( explode( '/', $path ), function ( $s ) { return '' !== $s; } ) );
		$last  = ! empty( $segs ) ? end( $segs ) : 'Page';
		$last  = preg_replace( '/[-_]/', ' ', $last );        // dashes/underscores → spaces
		$last  = preg_replace( '/\.[^.]+$/', '', $last );     // strip file extension
		$last  = preg_replace_callback( '/\b\w/u', function ( $m ) { return strtoupper( $m[0] ); }, $last );
		$last  = trim( $last );
		return '' !== $last ? $last : 'Page';
	}

	/** Pathname of a URL (always non-empty; defaults to "/"). Port of pathOf. */
	protected static function path_of( $url ) {
		$parts = wp_parse_url( $url );
		return ( isset( $parts['path'] ) && '' !== $parts['path'] ) ? $parts['path'] : '/';
	}

	// ─── BFS crawl (parent + title tracking) ───────────────────────────────

	/**
	 * BFS crawl tracking parent + title for tree construction. Returns:
	 *   { visited: map(url => {depth, parent, title, noindex, linksOut, isWordPress}),
	 *     meta:    {hitCrawlCap, hitTimeCap, anyWordPress, rootUrl} }
	 */
	protected static function sitemap_crawl( $start_url, $origin, $start_time ) {
		$visited       = array();
		$queue         = array( array( 'url' => $start_url, 'depth' => 0, 'parent' => null ) );
		$in_queue      = array( $start_url => true );
		$any_wp        = false;
		$hit_crawl_cap = false;
		$hit_time_cap  = false;

		// B2 fix: require one worst-case fetch of headroom before starting a batch.
		while ( count( $queue ) > 0
				&& count( $visited ) < self::MAX_PAGES
				&& ( microtime( true ) - $start_time ) < ( self::MAX_TIME_S - self::FETCH_TIMEOUT ) ) {

			$batch = array_splice( $queue, 0, self::CONCURRENCY );
			$urls  = array();
			foreach ( $batch as $item ) {
				if ( ! isset( $visited[ $item['url'] ] ) ) {
					$urls[] = $item['url'];
				}
			}
			if ( empty( $urls ) ) {
				continue;
			}

			$results = self::fetch_pages_parallel( $urls );

			foreach ( $batch as $item ) {
				$url    = $item['url'];
				$depth  = $item['depth'];
				$parent = $item['parent'];
				if ( isset( $visited[ $url ] ) ) {
					continue;
				}
				if ( count( $visited ) >= self::MAX_PAGES ) {
					$hit_crawl_cap = true;
					break;
				}
				if ( ( microtime( true ) - $start_time ) > self::MAX_TIME_S ) {
					$hit_time_cap = true;
					break;
				}

				$res = isset( $results[ $url ] ) ? $results[ $url ] : null;
				if ( ! $res ) {
					$visited[ $url ] = array(
						'depth'       => $depth,
						'parent'      => $parent,
						'title'       => self::slug_to_label( $url ),
						'noindex'     => false,
						'linksOut'    => array(),
						'fetchFailed' => true,
						'isWordPress' => false,
					);
					continue;
				}

				$norm_final = self::normalise( $res['final_url'], $origin . '/' );
				if ( ! $norm_final ) {
					$norm_final = $url;
				}
				if ( ! self::origin_matches( self::origin_of( $norm_final ), $origin ) ) {
					$visited[ $url ] = array(
						'depth'               => $depth,
						'parent'              => $parent,
						'title'               => self::slug_to_label( $url ),
						'noindex'             => false,
						'linksOut'            => array(),
						'redirectedOffDomain' => true,
						'isWordPress'         => false,
					);
					continue;
				}

				$parsed = self::parse_page( $res['html'], $norm_final, $origin );
				if ( $parsed['isWordPress'] ) {
					$any_wp = true;
				}
				$title        = '' !== $parsed['title'] ? $parsed['title'] : self::slug_to_label( $norm_final );
				$unique_links = self::dedupe_links( $parsed['links'] );

				$visited[ $url ] = array(
					'depth'       => $depth,
					'parent'      => $parent,
					'title'       => $title,
					'noindex'     => $parsed['noindex'],
					'linksOut'    => $unique_links,
					'isWordPress' => $parsed['isWordPress'],
				);

				if ( $depth < self::MAX_DEPTH ) {
					foreach ( $unique_links as $l ) {
						$to = $l['to'];
						if ( ! isset( $in_queue[ $to ] ) && ! isset( $visited[ $to ] ) ) {
							$in_queue[ $to ] = true;
							$queue[]         = array( 'url' => $to, 'depth' => $depth + 1, 'parent' => $url );
						}
					}
				}
			}
		}

		if ( ! $hit_crawl_cap && count( $queue ) > 0 ) {
			$hit_crawl_cap = true;
		}
		if ( ( microtime( true ) - $start_time ) > self::MAX_TIME_S ) {
			$hit_time_cap = true;
		}

		return array(
			'visited' => $visited,
			'meta'    => array(
				'hitCrawlCap'  => $hit_crawl_cap,
				'hitTimeCap'   => $hit_time_cap,
				'anyWordPress' => $any_wp,
				'rootUrl'      => $start_url,
			),
		);
	}

	// ─── Sitemap fallback ──────────────────────────────────────────────────

	/**
	 * Sitemap-based fallback. Adds title extraction + synthetic-root injection
	 * (B1) on top of the inherited sitemap-URL fetch.
	 */
	protected static function sitemap_via_sitemap( $origin, $start_time ) {
		$sitemap_urls = self::fetch_sitemap_urls( $origin );
		if ( empty( $sitemap_urls ) ) {
			return null;
		}

		$internal = array();
		foreach ( $sitemap_urls as $u ) {
			$norm = self::normalise( $u, $origin . '/' );
			if ( $norm && self::origin_matches( self::origin_of( $norm ), $origin ) ) {
				$internal[] = $norm;
			}
		}
		$internal = array_slice( array_values( array_unique( $internal ) ), 0, self::SITEMAP_FALLBACK_LIMIT );
		if ( empty( $internal ) ) {
			return null;
		}

		$root_url = self::normalise( $origin . '/', $origin . '/' );
		if ( ! $root_url ) {
			$root_url = $origin . '/';
		}

		$visited    = array();
		$batch_size = 5;
		for ( $i = 0; $i < count( $internal ); $i += $batch_size ) {
			if ( ( microtime( true ) - $start_time ) >= self::SITEMAP_TIME_S ) {
				break;
			}
			$batch   = array_slice( $internal, $i, $batch_size );
			$results = self::fetch_pages_parallel( $batch );
			foreach ( $batch as $url ) {
				if ( isset( $visited[ $url ] ) ) {
					continue;
				}
				$depth  = ( $url === $root_url ) ? 0 : 1;
				$parent = ( $url === $root_url ) ? null : $root_url;
				$res    = isset( $results[ $url ] ) ? $results[ $url ] : null;
				if ( ! $res ) {
					$visited[ $url ] = array(
						'depth'       => $depth,
						'parent'      => $parent,
						'title'       => self::slug_to_label( $url ),
						'noindex'     => false,
						'linksOut'    => array(),
						'fetchFailed' => true,
						'isWordPress' => false,
					);
					continue;
				}
				$parsed = self::parse_page( $res['html'], $url, $origin );
				$title  = '' !== $parsed['title'] ? $parsed['title'] : self::slug_to_label( $url );
				$visited[ $url ] = array(
					'depth'       => $depth,
					'parent'      => $parent,
					'title'       => $title,
					'noindex'     => $parsed['noindex'],
					'linksOut'    => self::dedupe_links( $parsed['links'] ),
					'isWordPress' => $parsed['isWordPress'],
				);
			}
		}

		// B1 fix: many sitemaps (Yoast WP) omit the homepage from <loc> entries.
		// Without a root node the tree has no anchor — inject a synthetic root.
		if ( ! isset( $visited[ $root_url ] ) ) {
			$visited[ $root_url ] = array(
				'depth'         => 0,
				'parent'        => null,
				'title'         => self::slug_to_label( $root_url ),
				'noindex'       => false,
				'linksOut'      => array(),
				'isWordPress'   => false,
				'syntheticRoot' => true,
			);
		}

		$any_wp = false;
		foreach ( $visited as $v ) {
			if ( ! empty( $v['isWordPress'] ) ) {
				$any_wp = true;
				break;
			}
		}

		return array(
			'visited' => $visited,
			'meta'    => array(
				'hitCrawlCap'      => count( $internal ) > count( $visited ),
				'hitTimeCap'       => ( microtime( true ) - $start_time ) >= ( self::MAX_TIME_S - 5 ),
				'anyWordPress'     => $any_wp,
				'rootUrl'          => $root_url,
				'usedSitemap'      => true,
				'sitemapPageCount' => count( $internal ),
			),
		);
	}

	// ─── Build sitemap structures ──────────────────────────────────────────

	/**
	 * Build flat nodes, directed edges, and the nested tree.
	 *
	 * nodes — one per crawled page (failed/off-domain/synthetic excluded)
	 * edges — one per unique directed internal link { source, target }
	 * tree  — nested, rooted at the homepage; orphans (no traceable parent)
	 *         attach to the root.
	 *
	 * @return array { nodes, edges, tree, depthBreakdown }
	 */
	protected static function build_sitemap_structures( array $graph, array $crawl_meta ) {
		$root_url = isset( $crawl_meta['rootUrl'] ) ? $crawl_meta['rootUrl'] : '';

		// Inbound-link counts (only links whose target is in the graph).
		$inbound_count = array();
		foreach ( $graph as $url => $data ) {
			if ( ! isset( $inbound_count[ $url ] ) ) {
				$inbound_count[ $url ] = 0;
			}
		}
		foreach ( $graph as $url => $data ) {
			$links_out = isset( $data['linksOut'] ) ? $data['linksOut'] : array();
			foreach ( $links_out as $l ) {
				if ( isset( $graph[ $l['to'] ] ) ) {
					$inbound_count[ $l['to'] ] = ( isset( $inbound_count[ $l['to'] ] ) ? $inbound_count[ $l['to'] ] : 0 ) + 1;
				}
			}
		}

		// Flat nodes.
		$nodes = array();
		foreach ( $graph as $url => $data ) {
			if ( ! empty( $data['fetchFailed'] ) || ! empty( $data['redirectedOffDomain'] ) || ! empty( $data['syntheticRoot'] ) ) {
				continue;
			}
			$links_out_in_graph = 0;
			foreach ( ( isset( $data['linksOut'] ) ? $data['linksOut'] : array() ) as $l ) {
				if ( isset( $graph[ $l['to'] ] ) ) {
					$links_out_in_graph++;
				}
			}
			$nodes[] = array(
				'id'       => $url,
				'url'      => $url,
				'title'    => ! empty( $data['title'] ) ? $data['title'] : self::slug_to_label( $url ),
				'depth'    => (int) $data['depth'],
				'path'     => self::path_of( $url ),
				'noindex'  => ! empty( $data['noindex'] ),
				'linksIn'  => isset( $inbound_count[ $url ] ) ? $inbound_count[ $url ] : 0,
				'linksOut' => $links_out_in_graph,
			);
		}

		// Directed edges (deduped). syntheticRoot has empty linksOut → no edges.
		$edges    = array();
		$edge_set = array();
		foreach ( $graph as $url => $data ) {
			if ( ! empty( $data['fetchFailed'] ) || ! empty( $data['redirectedOffDomain'] ) ) {
				continue;
			}
			foreach ( ( isset( $data['linksOut'] ) ? $data['linksOut'] : array() ) as $l ) {
				if ( ! isset( $graph[ $l['to'] ] ) ) {
					continue;
				}
				$key = $url . "\xE2\x86\x92" . $l['to']; // url→to
				if ( ! isset( $edge_set[ $key ] ) ) {
					$edge_set[ $key ] = true;
					$edges[]          = array( 'source' => $url, 'target' => $l['to'] );
				}
			}
		}

		// Children map from parent tracking.
		$children_map = array();
		foreach ( $graph as $url => $data ) {
			if ( ! empty( $data['fetchFailed'] ) || ! empty( $data['redirectedOffDomain'] ) ) {
				continue;
			}
			$parent = isset( $data['parent'] ) ? $data['parent'] : null;
			if ( $parent && isset( $graph[ $parent ] ) ) {
				$children_map[ $parent ][] = $url;
			} elseif ( $url !== $root_url ) {
				// Orphan — attach to root.
				if ( ! isset( $children_map[ $root_url ] ) ) {
					$children_map[ $root_url ] = array();
				}
				if ( ! in_array( $url, $children_map[ $root_url ], true ) ) {
					$children_map[ $root_url ][] = $url;
				}
			}
		}

		$tree_visited = array();
		$tree         = isset( $graph[ $root_url ] )
			? self::build_tree_node( $root_url, $graph, $children_map, $inbound_count, $tree_visited )
			: null;

		// Depth breakdown (over nodes).
		$depth_breakdown = array();
		foreach ( $nodes as $n ) {
			$d = (int) $n['depth'];
			$depth_breakdown[ $d ] = ( isset( $depth_breakdown[ $d ] ) ? $depth_breakdown[ $d ] : 0 ) + 1;
		}
		ksort( $depth_breakdown );

		return array(
			'nodes'          => $nodes,
			'edges'          => $edges,
			'tree'           => $tree,
			'depthBreakdown' => $depth_breakdown,
		);
	}

	/**
	 * Recursively build a tree node. $visited is shared across the whole build
	 * (passed by reference) so each page appears once and cycles are broken.
	 */
	protected static function build_tree_node( $url, array &$graph, array &$children_map, array &$inbound_count, array &$visited ) {
		if ( isset( $visited[ $url ] ) ) {
			return null;
		}
		$visited[ $url ] = true;

		$data = isset( $graph[ $url ] ) ? $graph[ $url ] : null;
		if ( ! $data || ! empty( $data['fetchFailed'] ) || ! empty( $data['redirectedOffDomain'] ) ) {
			return null;
		}

		$children = array();
		foreach ( ( isset( $children_map[ $url ] ) ? $children_map[ $url ] : array() ) as $child_url ) {
			$node = self::build_tree_node( $child_url, $graph, $children_map, $inbound_count, $visited );
			if ( null !== $node ) {
				$children[] = $node;
			}
		}
		usort( $children, function ( $a, $b ) {
			$t = strcmp( $a['title'], $b['title'] );
			return 0 !== $t ? $t : strcmp( $a['url'], $b['url'] );
		} );

		$links_out_in_graph = 0;
		foreach ( ( isset( $data['linksOut'] ) ? $data['linksOut'] : array() ) as $l ) {
			if ( isset( $graph[ $l['to'] ] ) ) {
				$links_out_in_graph++;
			}
		}

		return array(
			'id'       => $url,
			'url'      => $url,
			'title'    => ! empty( $data['title'] ) ? $data['title'] : self::slug_to_label( $url ),
			'path'     => self::path_of( $url ),
			'depth'    => (int) $data['depth'],
			'noindex'  => ! empty( $data['noindex'] ),
			'linksIn'  => isset( $inbound_count[ $url ] ) ? $inbound_count[ $url ] : 0,
			'linksOut' => $links_out_in_graph,
			'children' => $children,
		);
	}

	// ─── Format output ─────────────────────────────────────────────────────

	/**
	 * Assemble { preview, fullReport } in the same shape as sitemap.js so the
	 * React frontend does not branch on backend.
	 */
	protected static function format_output( array $graph, array $crawl_meta ) {
		$page_count      = count( $graph );
		$is_single_page  = $page_count <= 1;
		$is_partial_scan = ! empty( $crawl_meta['hitCrawlCap'] ) || ! empty( $crawl_meta['hitTimeCap'] );
		$is_wordpress    = ! empty( $crawl_meta['anyWordPress'] );
		$used_sitemap    = ! empty( $crawl_meta['usedSitemap'] );

		$structures      = self::build_sitemap_structures( $graph, $crawl_meta );
		$nodes           = $structures['nodes'];
		$edges           = $structures['edges'];
		$tree            = $structures['tree'];
		// Cast to object so wp_json_encode emits a JS-style object
		// ({"0":1,"1":2}) rather than a JSON array ([1,2]). PHP normalises
		// numeric-string keys back to ints, so an (object) cast at the
		// boundary is the only reliable way to match the JS output type.
		$depth_breakdown = (object) $structures['depthBreakdown'];

		// Top-level pages: depth <= 1, sorted by linksIn desc, top 8.
		$top_candidates = array();
		foreach ( $nodes as $n ) {
			if ( $n['depth'] <= 1 ) {
				$top_candidates[] = $n;
			}
		}
		// Sort by linksIn desc; tie-break on url asc so ordering is deterministic
		// on PHP < 8.0 (usort was not stable before 8.0; LW hosts may run 7.4).
		usort( $top_candidates, function ( $a, $b ) {
			if ( $b['linksIn'] !== $a['linksIn'] ) {
				return $b['linksIn'] - $a['linksIn'];
			}
			return strcmp( $a['url'], $b['url'] );
		} );
		$top_pages = array();
		foreach ( array_slice( $top_candidates, 0, 8 ) as $n ) {
			$top_pages[] = array(
				'url'     => $n['url'],
				'title'   => $n['title'],
				'path'    => $n['path'],
				'depth'   => $n['depth'],
				'linksIn' => $n['linksIn'],
			);
		}

		$warnings = array();
		if ( $is_single_page ) {
			$warnings[] = array( 'type' => 'blocked', 'message' => 'We could only access 1 page. The site may be blocking crawlers.' );
		} elseif ( $is_partial_scan ) {
			$warnings[] = array( 'type' => 'partial', 'message' => "Scanned {$page_count} pages. Your site may have more — this is a sample." );
		}
		if ( ! $is_wordpress ) {
			$warnings[] = array( 'type' => 'not-wordpress', 'message' => "This doesn't appear to be a WordPress site. Results may be less accurate." );
		}
		if ( $used_sitemap ) {
			$warnings[] = array( 'type' => 'sitemap-mode', 'message' => 'Built from your sitemap (crawler was blocked). Structure may not reflect exact navigation depth.' );
		}

		$max_depth = 0;
		foreach ( $nodes as $n ) {
			if ( $n['depth'] > $max_depth ) {
				$max_depth = $n['depth'];
			}
		}

		$meta = array(
			'pageCount'      => $page_count,
			'maxDepth'       => $max_depth,
			'depthBreakdown' => $depth_breakdown,
			'isWordPress'    => $is_wordpress,
			'isPartialScan'  => $is_partial_scan,
			'isSinglePage'   => $is_single_page,
			'usedSitemap'    => $used_sitemap,
			'warnings'       => $warnings,
		);

		// Shallow tree teaser: root + up to 5 depth-1 children, grandchildren dropped.
		$shallow_tree = null;
		if ( $tree ) {
			$shallow_tree              = $tree;
			$shallow_children          = array();
			foreach ( array_slice( isset( $tree['children'] ) ? $tree['children'] : array(), 0, 5 ) as $c ) {
				$c['children']      = array();
				$shallow_children[] = $c;
			}
			$shallow_tree['children'] = $shallow_children;
		}

		$preview = array(
			'meta'           => $meta,
			'topPages'       => $top_pages,
			'depthBreakdown' => $depth_breakdown,
			'shallowTree'    => $shallow_tree,
		);

		$full_report = array(
			'meta'  => $meta,
			'tree'  => $tree,
			'nodes' => $nodes,
			'edges' => $edges,
		);

		return array(
			'preview'    => $preview,
			'fullReport' => $full_report,
		);
	}
}
