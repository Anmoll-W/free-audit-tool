<?php
/**
 * LW_Audit_Crawler — internal-link health crawler.
 *
 * PHP port of netlify/functions/crawl.js. Same response shape so the React
 * frontend stays identical regardless of which backend serves the scan.
 *
 * Flow:
 *   scan( $url )
 *     → BFS crawl up to MAX_PAGES, max MAX_DEPTH hops from root
 *     → Build link graph (visited url → { depth, linksOut[], noindex })
 *     → If only 1 page found, try sitemap fallback (Cloudflare-blocked sites)
 *     → analyse() → score + metrics + findings + warnings
 *     → return { preview, fullReport }
 *
 * PHP-specific choices:
 *   - WpOrg\Requests\Requests::request_multiple() for parallel HTTP. Bundled
 *     with WP core 6.2+, no Composer step. Sequential wp_remote_get fallback
 *     for older WP.
 *   - DOMDocument for HTML parsing. Bundled, no dependency. libxml errors
 *     suppressed because real-world HTML is messy.
 *   - microtime(true) for ms-resolution timing.
 *   - set_time_limit(60) + wp_raise_memory_limit('admin') in REST handler,
 *     not here — this class assumes the caller has set up the env.
 *
 * Time budget tuning vs JS version:
 *   JS  : MAX_PAGES=100, MAX_TIME_MS=55000 (Netlify's 60s cap)
 *   PHP : MAX_PAGES=75,  MAX_TIME_MS=50000 (WP REST timeout varies; 50s
 *         leaves buffer on most managed hosts)
 *
 * @package LW_Audit_Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LW_Audit_Crawler {

	const MAX_PAGES     = 75;
	const MAX_DEPTH     = 4;
	const MAX_TIME_S    = 50;     // total crawl budget
	const FETCH_TIMEOUT = 8;      // per-page fetch timeout (seconds)
	const CONCURRENCY   = 3;      // parallel fetches per BFS batch
	const SITEMAP_TIME_S = 40;    // sitemap fallback budget
	const USER_AGENT    = 'Mozilla/5.0 (compatible; LinkWhisperBot/1.0; +https://linkwhisper.com/bot)';

	/**
	 * Anchor texts treated as "generic" for the link-quality score deduction.
	 * Match against lowercased, whitespace-collapsed anchor (first 30 chars).
	 */
	private static $generic_anchors = array(
		'click here', 'here', 'read more', 'read this', 'this', 'link',
		'more', 'learn more', 'continue', 'full article', 'source',
		'view', 'see more', 'find out more', 'click', 'go here',
	);

	/**
	 * Public entry point.
	 *
	 * @param string $raw_url
	 * @return array|WP_Error  { preview, fullReport } on success.
	 */
	public static function scan( $raw_url ) {
		$raw_url = is_string( $raw_url ) ? trim( $raw_url ) : '';
		if ( '' === $raw_url ) {
			return new WP_Error( 'lw_no_url', 'Missing url parameter', array( 'status' => 400 ) );
		}

		// Add scheme if missing (matches JS handler behavior).
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
		$crawl_result = self::crawl( $start_url, $origin, $start_time );
		$graph        = $crawl_result['visited'];
		$meta         = $crawl_result['meta'];

		// Sitemap fallback for Cloudflare/bot-blocked sites.
		if ( count( $graph ) <= 1 ) {
			$sitemap = self::crawl_via_sitemap( $origin, $start_time );
			if ( $sitemap && count( $sitemap['visited'] ) > 1 ) {
				$graph = $sitemap['visited'];
				$meta  = $sitemap['meta'];
			}
		}

		if ( count( $graph ) === 0 ) {
			// Caller surfaces this as a 200 with `error` field (matches JS handler).
			return new WP_Error(
				'lw_blocked',
				'Could not crawl this site. It may be blocking crawlers or require JavaScript to render.',
				array( 'status' => 200 )
			);
		}

		return self::analyse( $graph, $meta );
	}

	// ─── URL helpers ──────────────────────────────────────────────────────

	/**
	 * Normalise a URL: resolve against base, strip fragment, drop trailing
	 * slash (unless root), enforce http/https. Returns null on failure.
	 */
	private static function normalise( $raw, $base ) {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}

		// Skip non-http schemes (mailto:, tel:, javascript:, ftp:, data:).
		if ( preg_match( '/^[a-z][a-z0-9+.\-]*:/i', $raw ) && ! preg_match( '#^https?://#i', $raw ) ) {
			return null;
		}

		$abs = self::resolve_url( $raw, $base );
		if ( null === $abs ) {
			return null;
		}

		$parts = wp_parse_url( $abs );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		$rebuilt = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$rebuilt .= ':' . intval( $parts['port'] );
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( strlen( $path ) > 1 && substr( $path, -1 ) === '/' ) {
			$path = rtrim( $path, '/' );
		}
		$rebuilt .= $path;

		if ( ! empty( $parts['query'] ) ) {
			$rebuilt .= '?' . $parts['query'];
		}
		// Fragment intentionally dropped.

		return $rebuilt;
	}

	/**
	 * Minimal RFC 3986 reference resolver. Handles the cases we see in HTML:
	 * absolute (http://x), protocol-relative (//x), root-relative (/x),
	 * path-relative (x/y or ./x or ../x).
	 */
	private static function resolve_url( $rel, $base ) {
		// Absolute http(s).
		if ( preg_match( '#^https?://#i', $rel ) ) {
			return $rel;
		}

		$b = wp_parse_url( $base );
		if ( empty( $b['scheme'] ) || empty( $b['host'] ) ) {
			return null;
		}
		$scheme = strtolower( $b['scheme'] );
		$host   = strtolower( $b['host'] );
		$port   = ! empty( $b['port'] ) ? ':' . intval( $b['port'] ) : '';
		$origin = $scheme . '://' . $host . $port;

		// Protocol-relative.
		if ( strpos( $rel, '//' ) === 0 ) {
			return $scheme . ':' . $rel;
		}

		// Root-relative.
		if ( '' !== $rel && '/' === $rel[0] ) {
			return $origin . $rel;
		}

		// Path-relative — append to base directory and resolve ./ ../.
		$base_path = isset( $b['path'] ) ? $b['path'] : '/';
		$slash_pos = strrpos( $base_path, '/' );
		$dir       = false === $slash_pos ? '/' : substr( $base_path, 0, $slash_pos + 1 );
		if ( '' === $dir ) {
			$dir = '/';
		}

		$combined = $dir . $rel;
		// Resolve ./ and ../ segments.
		$segments = explode( '/', $combined );
		$out      = array();
		foreach ( $segments as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				array_pop( $out );
				continue;
			}
			$out[] = $seg;
		}
		$resolved_path = '/' . implode( '/', $out );
		// Preserve trailing slash if original combined ended in /.
		if ( substr( $combined, -1 ) === '/' && '/' !== $resolved_path ) {
			$resolved_path .= '/';
		}

		return $origin . $resolved_path;
	}

	private static function origin_of( $url ) {
		$p = wp_parse_url( $url );
		if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return null;
		}
		$o = strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] );
		if ( ! empty( $p['port'] ) ) {
			$o .= ':' . intval( $p['port'] );
		}
		return $o;
	}

	private static function is_internal( $href, $origin ) {
		$norm = self::normalise( $href, $origin . '/' );
		if ( ! $norm ) {
			return false;
		}
		return self::origin_of( $norm ) === $origin;
	}

	// ─── HTTP fetch ────────────────────────────────────────────────────────

	/**
	 * Fetch many URLs in parallel via the Requests library bundled with WP.
	 * Returns map: url → { html, final_url } | null on per-page failure.
	 */
	private static function fetch_pages_parallel( array $urls ) {
		if ( empty( $urls ) ) {
			return array();
		}

		$requests = array();
		foreach ( $urls as $url ) {
			$requests[ $url ] = array(
				'url'     => $url,
				'type'    => 'GET',
				'headers' => array(
					'User-Agent'      => self::USER_AGENT,
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.5',
					'Accept-Encoding' => 'gzip, deflate',
					'Cache-Control'   => 'no-cache',
				),
			);
		}

		$options = array(
			'timeout'          => self::FETCH_TIMEOUT,
			'connect_timeout'  => 5,
			'follow_redirects' => true,
			'redirects'        => 5,
			'verify'           => true,
		);

		$class = self::requests_class();
		if ( ! $class ) {
			return self::fetch_pages_sequential( $urls );
		}

		try {
			$responses = call_user_func( array( $class, 'request_multiple' ), $requests, $options );
		} catch ( \Exception $e ) {
			return self::fetch_pages_sequential( $urls );
		}

		$out = array();
		foreach ( $responses as $url => $response ) {
			// Per-request exception is returned as the response value.
			if ( $response instanceof \Exception ) {
				$out[ $url ] = null;
				continue;
			}
			if ( ! is_object( $response ) || empty( $response->success ) ) {
				$out[ $url ] = null;
				continue;
			}

			$ct = '';
			if ( isset( $response->headers ) ) {
				// Headers in modern Requests is a Headers object with offsetGet.
				if ( method_exists( $response->headers, 'offsetGet' ) || $response->headers instanceof \ArrayAccess ) {
					$ct = (string) $response->headers['content-type'];
				} elseif ( is_array( $response->headers ) && isset( $response->headers['content-type'] ) ) {
					$ct = (string) $response->headers['content-type'];
				}
			}
			if ( '' !== $ct && stripos( $ct, 'html' ) === false ) {
				$out[ $url ] = null;
				continue;
			}

			$out[ $url ] = array(
				'html'      => (string) $response->body,
				'final_url' => isset( $response->url ) ? (string) $response->url : $url,
			);
		}
		return $out;
	}

	/**
	 * Fallback for hosts where Requests::request_multiple is unavailable.
	 * Sequential — slow, but functionally correct.
	 */
	private static function fetch_pages_sequential( array $urls ) {
		$args = array(
			'timeout'    => self::FETCH_TIMEOUT,
			'redirection' => 5,
			'user-agent' => self::USER_AGENT,
			'headers'    => array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.5',
			),
		);
		$out = array();
		foreach ( $urls as $url ) {
			$r = wp_remote_get( $url, $args );
			if ( is_wp_error( $r ) ) {
				$out[ $url ] = null;
				continue;
			}
			$code = wp_remote_retrieve_response_code( $r );
			if ( $code < 200 || $code >= 400 ) {
				$out[ $url ] = null;
				continue;
			}
			$ct = wp_remote_retrieve_header( $r, 'content-type' );
			if ( '' !== $ct && stripos( $ct, 'html' ) === false ) {
				$out[ $url ] = null;
				continue;
			}
			$out[ $url ] = array(
				'html'      => (string) wp_remote_retrieve_body( $r ),
				'final_url' => $url, // wp_remote_get doesn't surface final redirect URL cleanly.
			);
		}
		return $out;
	}

	private static function requests_class() {
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			return '\WpOrg\Requests\Requests';
		}
		if ( class_exists( 'Requests' ) ) {
			return 'Requests';
		}
		return null;
	}

	// ─── HTML parsing ─────────────────────────────────────────────────────

	/**
	 * Extract internal anchors + noindex flag + WP-platform signal.
	 * @return array { noindex: bool, links: [{to, anchor}], isWordPress: bool }
	 */
	private static function parse_page( $html, $page_url, $origin ) {
		$is_wp = self::detect_wordpress( $html );

		$dom = new DOMDocument();
		$prev_internal_errors = libxml_use_internal_errors( true );

		// Force UTF-8 — DOMDocument's charset detection is unreliable with messy HTML.
		$prefixed = '<?xml encoding="utf-8" ?>' . $html;
		$dom->loadHTML( $prefixed, LIBXML_NOERROR | LIBXML_NOWARNING );

		$noindex = false;
		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
			$name = strtolower( (string) $meta->getAttribute( 'name' ) );
			if ( 'robots' !== $name ) {
				continue;
			}
			$content = strtolower( (string) $meta->getAttribute( 'content' ) );
			if ( strpos( $content, 'noindex' ) !== false ) {
				$noindex = true;
				break;
			}
		}

		$links = array();
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = (string) $a->getAttribute( 'href' );
			if ( '' === $href ) {
				continue;
			}
			$anchor_raw = (string) $a->textContent;
			$anchor     = strtolower( trim( preg_replace( '/\s+/u', ' ', $anchor_raw ) ) );
			$norm       = self::normalise( $href, $page_url );
			if ( ! $norm ) {
				continue;
			}
			if ( ! self::is_internal( $norm, $origin ) ) {
				continue;
			}
			$links[] = array( 'to' => $norm, 'anchor' => $anchor );
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $prev_internal_errors );

		return array(
			'noindex'     => $noindex,
			'links'       => $links,
			'isWordPress' => $is_wp,
		);
	}

	private static function detect_wordpress( $html ) {
		$signals = array(
			'wp-content',
			'wp-json',
			'wp-login',
			'wp-includes',
			'wordpress',
			'/xmlrpc.php',
			'wp-emoji',
			'generator" content="WordPress',
		);
		$lower = strtolower( $html );
		foreach ( $signals as $s ) {
			if ( strpos( $lower, $s ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private static function dedupe_links( array $links ) {
		$seen = array();
		$out  = array();
		foreach ( $links as $l ) {
			if ( isset( $seen[ $l['to'] ] ) ) {
				continue;
			}
			$seen[ $l['to'] ] = true;
			$out[] = $l;
		}
		return $out;
	}

	// ─── BFS crawl ────────────────────────────────────────────────────────

	/**
	 * BFS crawl with bounded concurrency. Returns:
	 *   { visited: map(url => {depth, noindex, linksOut, isWordPress}),
	 *     meta:    {hitCrawlCap, hitTimeCap, anyWordPress, queueRemaining, rootUrl} }
	 */
	private static function crawl( $start_url, $origin, $start_time ) {
		$visited      = array();
		$queue        = array( array( 'url' => $start_url, 'depth' => 0 ) );
		$in_queue     = array( $start_url => true );
		$any_wp       = false;
		$hit_crawl_cap = false;
		$hit_time_cap  = false;

		while ( count( $queue ) > 0
				&& count( $visited ) < self::MAX_PAGES
				&& ( microtime( true ) - $start_time ) < self::MAX_TIME_S ) {

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
				$url   = $item['url'];
				$depth = $item['depth'];
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
				if ( self::origin_of( $norm_final ) !== $origin ) {
					$visited[ $url ] = array(
						'depth'                => $depth,
						'noindex'              => false,
						'linksOut'             => array(),
						'redirectedOffDomain'  => true,
						'isWordPress'          => false,
					);
					continue;
				}

				$parsed = self::parse_page( $res['html'], $norm_final, $origin );
				if ( $parsed['isWordPress'] ) {
					$any_wp = true;
				}
				$unique_links = self::dedupe_links( $parsed['links'] );

				$visited[ $url ] = array(
					'depth'       => $depth,
					'noindex'     => $parsed['noindex'],
					'linksOut'    => $unique_links,
					'isWordPress' => $parsed['isWordPress'],
				);

				if ( $depth < self::MAX_DEPTH ) {
					foreach ( $unique_links as $l ) {
						$to = $l['to'];
						if ( ! isset( $in_queue[ $to ] ) && ! isset( $visited[ $to ] ) ) {
							$in_queue[ $to ] = true;
							$queue[] = array( 'url' => $to, 'depth' => $depth + 1 );
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
				'hitCrawlCap'    => $hit_crawl_cap,
				'hitTimeCap'     => $hit_time_cap,
				'anyWordPress'   => $any_wp,
				'queueRemaining' => count( $queue ),
				'rootUrl'        => $start_url,
			),
		);
	}

	// ─── Sitemap fallback ─────────────────────────────────────────────────

	private static function extract_sitemap_urls( $xml ) {
		$urls = array();
		if ( preg_match_all( '#<loc>\s*(https?://[^\s<]+)\s*</loc>#i', $xml, $m ) ) {
			foreach ( $m[1] as $u ) {
				$urls[] = trim( $u );
			}
		}
		return $urls;
	}

	/**
	 * Try to enumerate URLs from common sitemap locations. Walks one level
	 * of sitemap-index nesting. Caps at MAX_PAGES.
	 */
	private static function fetch_sitemap_urls( $origin ) {
		$candidates = array(
			$origin . '/sitemap.xml',
			$origin . '/sitemap_index.xml',
			$origin . '/wp-sitemap.xml',
		);

		$args = array(
			'timeout'     => 10,
			'redirection' => 5,
			'user-agent'  => self::USER_AGENT,
		);

		foreach ( $candidates as $sm_url ) {
			$r = wp_remote_get( $sm_url, $args );
			if ( is_wp_error( $r ) ) {
				continue;
			}
			$code = wp_remote_retrieve_response_code( $r );
			if ( $code < 200 || $code >= 400 ) {
				continue;
			}
			$ct = wp_remote_retrieve_header( $r, 'content-type' );
			if ( '' !== $ct && stripos( $ct, 'xml' ) === false && stripos( $ct, 'text' ) === false ) {
				continue;
			}
			$xml = (string) wp_remote_retrieve_body( $r );
			$urls = self::extract_sitemap_urls( $xml );
			if ( empty( $urls ) ) {
				continue;
			}

			$is_index = ( false !== stripos( $xml, '<sitemapindex' ) ) || ( false !== stripos( $xml, '<sitemap>' ) );
			if ( $is_index ) {
				$sub_sitemaps = array();
				foreach ( $urls as $u ) {
					if ( stripos( $u, 'sitemap' ) !== false ) {
						$sub_sitemaps[] = $u;
						if ( count( $sub_sitemaps ) >= 2 ) {
							break;
						}
					}
				}
				$all_urls = array();
				foreach ( $sub_sitemaps as $sub ) {
					$sr = wp_remote_get( $sub, array_merge( $args, array( 'timeout' => 8 ) ) );
					if ( is_wp_error( $sr ) ) {
						continue;
					}
					$sub_code = wp_remote_retrieve_response_code( $sr );
					if ( $sub_code < 200 || $sub_code >= 400 ) {
						continue;
					}
					$sub_xml = (string) wp_remote_retrieve_body( $sr );
					$all_urls = array_merge( $all_urls, self::extract_sitemap_urls( $sub_xml ) );
					if ( count( $all_urls ) >= self::MAX_PAGES ) {
						break;
					}
				}
				return array_slice( $all_urls, 0, self::MAX_PAGES );
			}

			return array_slice( $urls, 0, self::MAX_PAGES );
		}
		return array();
	}

	private static function crawl_via_sitemap( $origin, $start_time ) {
		$sitemap_urls = self::fetch_sitemap_urls( $origin );
		if ( empty( $sitemap_urls ) ) {
			return null;
		}

		$internal = array();
		foreach ( $sitemap_urls as $u ) {
			$norm = self::normalise( $u, $origin . '/' );
			if ( $norm && self::origin_of( $norm ) === $origin ) {
				$internal[] = $norm;
			}
		}
		// Cap at 50 for time budget (matches JS version).
		$internal = array_slice( array_values( array_unique( $internal ) ), 0, 50 );
		if ( empty( $internal ) ) {
			return null;
		}

		$visited  = array();
		$root_url = self::normalise( $origin . '/', $origin . '/' );
		if ( ! $root_url ) {
			$root_url = $origin . '/';
		}

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
				$depth = ( $url === $root_url ) ? 0 : 1;
				$res = isset( $results[ $url ] ) ? $results[ $url ] : null;
				if ( ! $res ) {
					$visited[ $url ] = array(
						'depth'       => $depth,
						'noindex'     => false,
						'linksOut'    => array(),
						'fetchFailed' => true,
						'isWordPress' => false,
					);
					continue;
				}
				$parsed = self::parse_page( $res['html'], $url, $origin );
				$visited[ $url ] = array(
					'depth'       => $depth,
					'noindex'     => $parsed['noindex'],
					'linksOut'    => self::dedupe_links( $parsed['links'] ),
					'isWordPress' => $parsed['isWordPress'],
				);
			}
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
				'hitCrawlCap'    => count( $internal ) > count( $visited ),
				'hitTimeCap'     => ( microtime( true ) - $start_time ) >= ( self::MAX_TIME_S - 5 ),
				'anyWordPress'   => $any_wp,
				'queueRemaining' => 0,
				'rootUrl'        => $root_url,
				'usedSitemap'    => true,
				'sitemapPageCount' => count( $internal ),
			),
		);
	}

	// ─── Scoring + analysis ────────────────────────────────────────────────

	/**
	 * Build inbound map, score, bucket findings. Returns the same
	 * { preview, fullReport } shape as the Netlify function so the React
	 * frontend doesn't have to branch on backend.
	 */
	private static function analyse( array $graph, array $crawl_meta ) {
		// inbound[ url ] = [ { from, anchor }, ... ]
		$inbound = array();
		foreach ( $graph as $page_url => $data ) {
			if ( ! isset( $inbound[ $page_url ] ) ) {
				$inbound[ $page_url ] = array();
			}
			$links_out = isset( $data['linksOut'] ) && is_array( $data['linksOut'] ) ? $data['linksOut'] : array();
			foreach ( $links_out as $l ) {
				if ( ! isset( $inbound[ $l['to'] ] ) ) {
					$inbound[ $l['to'] ] = array();
				}
				$inbound[ $l['to'] ][] = array( 'from' => $page_url, 'anchor' => $l['anchor'] );
			}
		}

		$page_count = count( $graph );
		$root_url   = isset( $crawl_meta['rootUrl'] ) ? $crawl_meta['rootUrl'] : ( $page_count > 0 ? array_keys( $graph )[0] : '' );

		$pages           = array();
		$total_anchors   = 0;
		$generic_anchors = 0;
		$generics        = array_flip( self::$generic_anchors );

		foreach ( $graph as $url => $data ) {
			if ( ! empty( $data['fetchFailed'] ) || ! empty( $data['redirectedOffDomain'] ) ) {
				continue;
			}

			$ins  = array();
			foreach ( ( isset( $inbound[ $url ] ) ? $inbound[ $url ] : array() ) as $l ) {
				if ( isset( $graph[ $l['from'] ] ) ) {
					$ins[] = $l;
				}
			}
			$outs = array();
			$links_out = isset( $data['linksOut'] ) ? $data['linksOut'] : array();
			foreach ( $links_out as $l ) {
				if ( isset( $graph[ $l['to'] ] ) ) {
					$outs[] = $l;
				}
			}

			$is_orphan      = ( count( $ins ) === 0 ) && ( $url !== $root_url );
			$is_dead_end    = count( $outs ) === 0;
			$is_low_density = count( $outs ) > 0 && count( $outs ) < 2 && ! $is_dead_end;
			$is_deep        = $data['depth'] >= self::MAX_DEPTH;

			foreach ( array_merge( $ins, $outs ) as $l ) {
				$total_anchors++;
				$key = substr( (string) $l['anchor'], 0, 30 );
				if ( isset( $generics[ $key ] ) ) {
					$generic_anchors++;
				}
			}

			$issues = array();
			if ( $is_orphan ) {
				$issues[] = 'orphan';
			}
			if ( $is_dead_end && $url !== $root_url ) {
				$issues[] = 'dead-end';
			}
			if ( $is_low_density ) {
				$issues[] = 'low-density';
			}
			if ( $is_deep ) {
				$issues[] = 'deep';
			}

			$pages[] = array(
				'url'           => $url,
				'depth'         => (int) $data['depth'],
				'noindex'       => (bool) $data['noindex'],
				'linksInCount'  => count( $ins ),
				'linksOutCount' => count( $outs ),
				'isOrphan'      => $is_orphan,
				'isDeadEnd'     => $is_dead_end,
				'isLowDensity'  => $is_low_density,
				'isDeepPage'    => $is_deep,
				'issues'        => $issues,
			);
		}

		$orphan_count    = 0;
		$dead_end_count  = 0;
		$low_dense_count = 0;
		$deep_page_count = 0;
		$total_outs      = 0;
		foreach ( $pages as $p ) {
			if ( $p['isOrphan'] ) {
				$orphan_count++;
			}
			if ( $p['isDeadEnd'] && ! $p['isOrphan'] ) {
				$dead_end_count++;
			}
			if ( $p['isLowDensity'] ) {
				$low_dense_count++;
			}
			if ( $p['isDeepPage'] ) {
				$deep_page_count++;
			}
			$total_outs += $p['linksOutCount'];
		}

		$generic_pct       = $total_anchors > 0 ? ( $generic_anchors / $total_anchors ) * 100 : 0;
		$avg_links_per_page = $page_count > 0 ? round( ( $total_outs / $page_count ) * 10 ) / 10 : 0;

		// Same scoring formula as JS.
		$score  = 100;
		$score -= min( 30, $orphan_count    * 3 );
		$score -= min( 20, $dead_end_count  * 2 );
		$score -= min( 15, $low_dense_count * 1 );
		$score -= min( 10, $deep_page_count * 1 );
		$generic_deduction = $generic_pct > 20 ? min( 15, ( $generic_pct - 20 ) * 0.5 ) : 0;
		$score -= $generic_deduction;
		$score = max( 0, (int) round( $score ) );

		$is_single_page    = $page_count <= 1;
		$is_wordpress      = ! empty( $crawl_meta['anyWordPress'] );
		$hit_crawl_cap     = ! empty( $crawl_meta['hitCrawlCap'] );
		$hit_time_cap      = ! empty( $crawl_meta['hitTimeCap'] );
		$is_partial_scan   = $hit_crawl_cap || $hit_time_cap;

		// Bucket — match documented ranges exactly: 0-64 critical, 65-84 needs-work, 85+ healthy.
		$bucket = $score >= 85 ? 'healthy' : ( $score >= 65 ? 'needs-work' : 'critical' );
		$bucket_label = array(
			'healthy'    => 'Healthy',
			'needs-work' => 'Needs Work',
			'critical'   => 'Critical',
		);
		$bucket_message_map = array(
			'healthy'    => 'Your internal linking is solid. A few tweaks could still add value.',
			'needs-work' => 'Some issues are dragging down your SEO value. Fix these for quick wins.',
			'critical'   => 'Significant internal linking gaps. These are costing you organic traffic.',
		);
		$bucket_message = $is_single_page
			? 'We could only access 1 page. The site may be blocking crawlers. Results are unreliable.'
			: $bucket_message_map[ $bucket ];

		$metrics = array(
			'pagesCrawled'     => $page_count,
			'orphanPages'      => $orphan_count,
			'deadEndPages'     => $dead_end_count,
			'lowDensity'       => $low_dense_count,
			'deepPages'        => $deep_page_count,
			'genericAnchorPct' => (int) round( $generic_pct ),
			'avgLinksPerPage'  => $avg_links_per_page,
		);

		$findings = array();
		if ( ! $is_single_page ) {
			if ( $orphan_count > 0 ) {
				$findings[] = array(
					'type'   => 'orphan',
					'label'  => $orphan_count . ' orphaned page' . ( $orphan_count !== 1 ? 's' : '' ) . ' found',
					'detail' => 'No other page links to these — search engines struggle to find and rank them.',
				);
			}
			if ( $dead_end_count > 0 ) {
				$findings[] = array(
					'type'   => 'dead-end',
					'label'  => $dead_end_count . ' dead-end page' . ( $dead_end_count !== 1 ? 's' : '' ),
					'detail' => "Pages that don't link out anywhere — link equity stops here instead of flowing.",
				);
			}
			if ( $low_dense_count > 0 ) {
				$findings[] = array(
					'type'   => 'low-density',
					'label'  => $low_dense_count . ' page' . ( $low_dense_count !== 1 ? 's' : '' ) . ' with thin linking',
					'detail' => 'Fewer than 2 internal links out — not enough link equity distribution.',
				);
			}
			if ( $deep_page_count > 0 ) {
				$findings[] = array(
					'type'   => 'deep',
					'label'  => $deep_page_count . ' hard-to-reach page' . ( $deep_page_count !== 1 ? 's' : '' ),
					'detail' => 'These require 4+ clicks from your homepage — Google may deprioritise them.',
				);
			}
			if ( $generic_pct > 20 ) {
				$findings[] = array(
					'type'   => 'anchor',
					'label'  => (int) round( $generic_pct ) . '% generic anchor text',
					'detail' => 'Links using "click here", "read more" etc. miss the chance to signal relevance.',
				);
			}
		}

		$warnings = array();
		if ( $is_single_page ) {
			$warnings[] = array(
				'type'    => 'blocked',
				'message' => 'We could only access 1 page. The site may be blocking crawlers. Results are unreliable.',
			);
		}
		if ( $is_partial_scan && ! $is_single_page ) {
			$warnings[] = array(
				'type'    => 'partial',
				'message' => "Scanned {$page_count} pages. Your site may have more — score is based on a sample.",
			);
		}
		if ( ! $is_wordpress ) {
			$warnings[] = array(
				'type'    => 'not-wordpress',
				'message' => "This doesn't appear to be a WordPress site. The tool is optimised for WordPress — results for other platforms may be less accurate.",
			);
		}

		$preview_score        = $is_single_page ? null : $score;
		$preview_bucket       = $is_single_page ? 'unreliable' : $bucket;
		$preview_bucket_label = $is_single_page ? 'Unreliable' : $bucket_label[ $bucket ];

		// Sort pages by issue count desc, then URL asc.
		usort( $pages, function ( $a, $b ) {
			$diff = count( $b['issues'] ) - count( $a['issues'] );
			if ( 0 !== $diff ) {
				return $diff;
			}
			return strcmp( $a['url'], $b['url'] );
		} );

		$full_pages = array();
		foreach ( $pages as $p ) {
			$full_pages[] = array(
				'url'      => $p['url'],
				'depth'    => $p['depth'],
				'linksIn'  => $p['linksInCount'],
				'linksOut' => $p['linksOutCount'],
				'issues'   => $p['issues'],
				'noindex'  => $p['noindex'],
			);
		}

		$preview = array(
			'score'             => $preview_score,
			'bucket'            => $preview_bucket,
			'bucketLabel'       => $preview_bucket_label,
			'bucketMessage'     => $bucket_message,
			'metrics'           => $metrics,
			'topFindings'       => array_slice( $findings, 0, 3 ),
			'warnings'          => $warnings,
			'isSinglePageCrawl' => $is_single_page,
			'isPartialScan'     => $is_partial_scan,
			'isWordPress'       => $is_wordpress,
		);

		$full_report = array(
			'score'         => $preview_score,
			'bucket'        => $preview_bucket,
			'bucketLabel'   => $preview_bucket_label,
			'bucketMessage' => $bucket_message,
			'metrics'       => $metrics,
			'findings'      => $findings,
			'warnings'      => $warnings,
			'pages'         => $full_pages,
		);

		return array(
			'preview'    => $preview,
			'fullReport' => $full_report,
		);
	}
}
