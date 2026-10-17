<?php
/**
 * Server-side protection: scrapers and AI bots, fake search engines, per-endpoint limits,
 * temporary IP blocking, a bot trap and robots.txt.
 *
 * Applies only to requests that reach PHP. Pages served from a page cache
 * (Nginx FastCGI, WP Rocket, etc.) do not pass through here: see the server rules in the settings.
 *
 * @package ScrapeLock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bots, limits and blocks.
 */
final class PNCP_Bots {

	/**
	 * Verifiable search engines: User-Agent patterns, reverse DNS domains and official IP list.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $crawlers = array(
		'google' => array(
			'ua'   => array( 'googlebot', 'google-inspectiontool', 'storebot-google', 'adsbot-google', 'mediapartners-google', 'googleother', 'feedfetcher-google', 'google-read-aloud', 'apis-google', 'google-site-verification', 'google-safety' ),
			'dns'  => array( '.googlebot.com', '.google.com' ),
			'list' => 'google',
		),
		'bing'   => array(
			'ua'   => array( 'bingbot', 'adidxbot', 'bingpreview', 'msnbot' ),
			'dns'  => array( '.search.msn.com' ),
			'list' => 'bing',
		),
		'apple'  => array(
			'ua'   => array( 'applebot' ),
			'dns'  => array( '.applebot.apple.com' ),
			'list' => '',
		),
		'yandex' => array(
			'ua'   => array( 'yandexbot', 'yandeximages', 'yandexmobilebot', 'yandexmetrika' ),
			'dns'  => array( '.yandex.ru', '.yandex.net', '.yandex.com' ),
			'list' => '',
		),
	);

	/**
	 * Whether the last crawler verification could not reach a verdict (DNS budget used up or
	 * reverse DNS failed). Such a request is refused, but it is not counted as a violation.
	 *
	 * @var bool
	 */
	private static $verify_inconclusive = false;

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'trap_guard' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'page_guard' ), 0 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_guard' ), 0, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'trap_style' ) );
		add_action( 'wp_footer', array( __CLASS__, 'trap_link' ), 99 );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'speculation_exclude' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 99, 2 );
		// Blocked User-Agents do not get pages from WP Rocket's cache, so that they reach this point.
		add_filter( 'rocket_cache_reject_ua', array( __CLASS__, 'rocket_reject_ua' ) );
	}

	/**
	 * The visitor's IP (for compatibility with code that called 1.4).
	 *
	 * @return string
	 */
	public static function client_ip() {
		return PNCP_Ip::client_ip();
	}

	/**
	 * The request User-Agent.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	/**
	 * Page views and search.
	 */
	public static function page_guard() {
		if ( is_robots() || is_favicon() ) {
			return;
		}
		$verdict = self::check( is_search() ? 'search' : 'page' );
		if ( $verdict ) {
			self::die_html( $verdict['code'] );
		}
	}

	/**
	 * REST API (/wp-json/): only for read requests from visitors.
	 *
	 * @param mixed           $result  Result (null = continue).
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function rest_guard( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}
		// Read-only: cart, checkout, forms and payment webhooks (POST) are not checked.
		if ( ! in_array( strtoupper( (string) $request->get_method() ), array( 'GET', 'HEAD' ), true ) ) {
			return $result;
		}
		// WooCommerce's Store API (cart, mini-cart) is called from every page: it does not count toward the limit.
		$count   = 0 !== strpos( (string) $request->get_route(), '/wc/store' );
		$verdict = self::check( 'rest', $count );
		if ( ! $verdict ) {
			return $result;
		}
		// The 403 / 429 must not end up in a REST cache (e.g. LiteSpeed Cache).
		self::no_cache();
		do_action( 'litespeed_control_set_nocache', 'scrapelock' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache hook.
		if ( 429 === $verdict['code'] ) {
			$server->send_header( 'Retry-After', '60' );
		}
		return new WP_Error(
			429 === $verdict['code'] ? 'pncp_rate_limited' : 'pncp_forbidden',
			429 === $verdict['code'] ? __( 'Too many requests. Please try again in a minute.', 'scrapelock' ) : __( 'Access denied.', 'scrapelock' ),
			array( 'status' => $verdict['code'] )
		);
	}

	/**
	 * The main check.
	 *
	 * Bots, fake search engines and blocks apply to every method (a page can also be served via
	 * POST). Limits count only views (GET/HEAD, not prefetch), so that forms and checkout do not count.
	 *
	 * @param string $context page | search | rest | graphql.
	 * @param bool   $count   Whether to count toward the limit.
	 * @return array{code:int, reason:string}|null Null if the request passes.
	 */
	public static function check( $context, $count = true ) {
		$s = PNCP_Options::get();
		// No exemption for wp_doing_ajax(): WooCommerce defines DOING_AJAX on wc-ajax, so every bot would
		// get through (confirmed with real WooCommerce). admin-ajax.php does not reach this point anyway.
		if ( empty( $s['enabled'] ) || wp_doing_cron() ) {
			return null;
		}
		// Only the roles in the "Who is exempt" setting are exempt, not every logged-in user: a
		// WooCommerce customer account can be created by anyone. On REST / GraphQL, those who
		// write content are also exempt, because the block editor makes dozens of requests per minute.
		if ( PNCP_Options::user_is_exempt( $s ) || ( in_array( $context, array( 'rest', 'graphql' ), true ) && current_user_can( 'edit_posts' ) ) ) {
			return null;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'graphql' !== $context && ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || self::is_prefetch() ) ) {
			$count = false;
		}
		// WooCommerce AJAX (cart, checkout): does not count toward the page limit, but bots, fake crawlers and
		// blocks are checked as usual. Only for actions registered by WooCommerce (or an extension of it).
		if ( self::is_wc_ajax() || ( 'page' === $context && self::is_shop_transaction_page() ) ) {
			$count = false;
		}
		// Private URL (XML feed) with a valid token: the service may use any User-Agent.
		if ( PNCP_Private_Urls::granted() ) {
			return null;
		}

		$ua = self::user_agent();
		$ip = PNCP_Ip::client_ip();

		if ( '' === $ip || PNCP_Ip::is_local( $ip ) ) {
			return null;
		}
		// Always-allowed IPs: only if the IP cannot be spoofed (not "trust every proxy").
		if ( PNCP_Ip::reliable() && PNCP_Ip::in_any( $ip, PNCP_Options::parse_list( $s['allow_ips'] ) ) ) {
			return null;
		}

		/**
		 * Filter to let a request skip all checks.
		 *
		 * @param bool   $skip    Whether the check will be skipped.
		 * @param string $context page | search | rest | graphql.
		 * @param string $ip      IP.
		 */
		if ( apply_filters( 'pncp_skip_guard', false, $context, $ip ) ) {
			return null;
		}

		// Verification first: a real search engine gets through even if someone managed to
		// get its IP blocked.
		$family = self::claimed_crawler( $ua );
		if ( '' !== $family && self::is_verified_crawler( $family, $ip ) ) {
			return null; // Verified search engines: never blocked, never limited.
		}

		if ( PNCP_Log::is_banned( $ip ) ) {
			return self::verdict( 403, 'banned', $ip );
		}

		if ( '' !== $family && ! empty( $s['block_fake_crawlers'] ) ) {
			// No violation when the check could not finish: otherwise anyone could use up the DNS budget
			// with fake "Applebot" requests and get the real Applebot / YandexBot banned.
			if ( ! self::$verify_inconclusive ) {
				PNCP_Log::strike( $ip, 'fake_crawler' );
			}
			return self::verdict( 403, 'fake_crawler', $ip );
		}

		$allowed_ua = '' !== $ua && ( self::allow_list_match( $ua, $ip, $s ) || self::ua_in_list( $ua, PNCP_Options::allowed_ai_search() ) );

		if ( ! empty( $s['block_bots'] ) && ! $allowed_ua ) {
			if ( '' === trim( $ua ) && ! empty( $s['block_empty_ua'] ) ) {
				return self::verdict( 403, 'empty_ua', $ip );
			}
			if ( '' !== $ua && self::ua_in_list( $ua, PNCP_Options::parse_list( $s['bot_list'] ) ) ) {
				return self::verdict( 403, 'bot_ua', $ip );
			}
		}

		// Every browser sends Accept-Language and "Mozilla/" in the User-Agent; most scripts do not.
		// Not on wc-ajax: some payment gateways / extensions call them server-side, without Accept-Language.
		if ( ! empty( $s['require_browser_headers'] ) && ! $allowed_ua && in_array( $context, array( 'page', 'search' ), true ) && ! self::is_wc_ajax() && ! self::looks_like_browser( $ua ) ) {
			return self::verdict( 403, 'no_browser', $ip );
		}

		$limits = array(
			'page'    => array( 'rate_limit', 'rate_page' ),
			'search'  => array( 'rate_limit_search', 'rate_search' ),
			'rest'    => array( 'rate_limit_rest', 'rate_rest' ),
			'graphql' => array( 'rate_limit_rest', 'rate_rest' ),
		);
		if ( $count && isset( $limits[ $context ] ) ) {
			$limit = (int) $s[ $limits[ $context ][0] ];
			if ( $limit > 0 ) {
				$count = self::hit( $ip, $limits[ $context ][1] );
				if ( $count > $limit ) {
					// One "violation" per minute in which the limit was exceeded, not one per request.
					if ( $count === $limit + 1 ) {
						PNCP_Log::strike( $ip, $limits[ $context ][1] );
					}
					return self::verdict( 429, $limits[ $context ][1], $ip );
				}
			}
		}

		return null;
	}

	/**
	 * Logs and returns the decision.
	 *
	 * @param int    $code   HTTP status code.
	 * @param string $reason Reason.
	 * @param string $ip     IP.
	 * @return array{code:int, reason:string}
	 */
	private static function verdict( $code, $reason, $ip ) {
		PNCP_Log::add( $reason, $code, $ip );
		return array(
			'code'   => $code,
			'reason' => $reason,
		);
	}

	/**
	 * Is it a real wc-ajax request? WooCommerce must be active and the action registered
	 * (`wc_ajax_{action}`), otherwise a bare ?wc-ajax=x in the URL would be enough.
	 *
	 * @return bool
	 */
	public static function is_wc_ajax() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only the action name.
		$action = isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : '';
		return '' !== $action && class_exists( 'WooCommerce' ) && has_action( 'wc_ajax_' . $action );
	}

	/**
	 * WooCommerce transaction page (cart, checkout, "Thank you", "My account"). Does not count
	 * toward the page limit: a customer making a purchase opens 4–5 such pages within a minute.
	 * Bots and blocks are checked as usual.
	 *
	 * @return bool
	 */
	public static function is_shop_transaction_page() {
		if ( ! function_exists( 'is_cart' ) || ! did_action( 'wp' ) ) {
			return false;
		}
		// Not plain is_wc_endpoint_url(): it is true for any URL with ?orders=1 etc., so it would disable the limit everywhere.
		// The "Thank you" page and account pages are covered by is_checkout() / is_account_page().
		return is_cart() || is_checkout() || is_account_page();
	}

	/**
	 * Prefetch / prerender (browser, extension or speculative loading): not a view.
	 *
	 * @return bool
	 */
	public static function is_prefetch() {
		foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ', 'HTTP_X_PURPOSE' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ), 'prefetch' ) ) {
				return true;
			}
		}
		return ! empty( $_SERVER['HTTP_SEC_PURPOSE'] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_PURPOSE'] ) ), 'prerender' );
	}

	/**
	 * Does it look like a browser?
	 *
	 * @param string $ua User-Agent.
	 * @return bool
	 */
	private static function looks_like_browser( $ua ) {
		$lang = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
		return '' !== trim( $lang ) && 0 === strpos( $ua, 'Mozilla/' );
	}

	/**
	 * Does the User-Agent match any item in the list? (substring, case-insensitive)
	 *
	 * @param string   $ua   User-Agent.
	 * @param string[] $needles List.
	 * @return bool
	 */
	public static function ua_in_list( $ua, $needles ) {
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && false !== stripos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does the request match an "always allowed" entry? An entry tied to IPs ("Skroutz@203.0.113.0/24")
	 * counts only from those IPs, and only when the IP cannot be spoofed (not "trust every proxy").
	 *
	 * @param string               $ua User-Agent.
	 * @param string               $ip IP.
	 * @param array<string, mixed> $s  Settings.
	 * @return bool
	 */
	public static function allow_list_match( $ua, $ip, $s ) {
		foreach ( PNCP_Options::parse_list( $s['allow_list'] ) as $entry ) {
			$entry = PNCP_Options::split_allow_entry( $entry );
			if ( '' === $entry['ua'] || false === stripos( $ua, $entry['ua'] ) ) {
				continue;
			}
			if ( ! $entry['pinned'] || ( PNCP_Ip::reliable() && PNCP_Ip::in_any( $ip, $entry['ips'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Which search engine the User-Agent claims to be ('' = none).
	 *
	 * @param string $ua User-Agent.
	 * @return string
	 */
	public static function claimed_crawler( $ua ) {
		foreach ( self::$crawlers as $family => $def ) {
			if ( self::ua_in_list( $ua, $def['ua'] ) ) {
				return $family;
			}
		}
		return '';
	}

	/**
	 * Is it a real search engine? First the official IP list (Google, Bing), then reverse DNS
	 * and forward DNS, which must return the same IP. Positive result cached 24 hours, negative 1 hour,
	 * failed reverse DNS 5 minutes. Without a verdict (DNS budget used up, reverse DNS failed) it returns
	 * false and the request is refused, but not counted as a violation.
	 *
	 * @param string $family google | bing | apple | yandex.
	 * @param string $ip     IP.
	 * @return bool
	 */
	public static function is_verified_crawler( $family, $ip ) {
		self::$verify_inconclusive = false;
		if ( ! isset( self::$crawlers[ $family ] ) || '' === $ip ) {
			return false;
		}
		$def = self::$crawlers[ $family ];
		if ( '' !== $def['list'] && PNCP_Ip::in_any( $ip, PNCP_Ip::list_of( $def['list'] ) ) ) {
			return true;
		}

		$key    = 'pncp_se_' . md5( $family . '|' . $ip );
		$cached = PNCP_Log::cache_get( $key );
		if ( false !== $cached ) {
			self::$verify_inconclusive = 'unknown' === $cached;
			return 'yes' === $cached;
		}

		// DNS blocks the PHP worker: up to 20 checks per minute site-wide. Beyond that, treated as
		// unverified (Google and Bing are already verified via the IP lists, without DNS).
		$budget = 'pncp_dns_' . (int) floor( time() / MINUTE_IN_SECONDS );
		$used   = (int) PNCP_Log::cache_get( $budget );
		if ( $used >= (int) apply_filters( 'pncp_dns_lookups_per_minute', 20 ) ) {
			self::$verify_inconclusive = true;
			return false;
		}
		PNCP_Log::cache_set( $budget, $used + 1, 2 * MINUTE_IN_SECONDS );

		$verified = false;
		$host     = (string) apply_filters( 'pncp_reverse_dns', null, $ip );
		if ( '' === $host ) {
			$host = (string) gethostbyaddr( $ip );
		}
		if ( '' === $host || $host === $ip ) {
			// No PTR record or DNS failure: perhaps temporary, so it is retried in 5 minutes.
			self::$verify_inconclusive = true;
			PNCP_Log::cache_set( $key, 'unknown', 5 * MINUTE_IN_SECONDS );
			return false;
		}
		$host_lc = strtolower( rtrim( $host, '.' ) );
		foreach ( $def['dns'] as $domain ) {
			if ( substr( $host_lc, -strlen( $domain ) ) === $domain ) {
				$verified = in_array( $ip, self::forward_ips( $host_lc ), true );
				break;
			}
		}

		PNCP_Log::cache_set( $key, $verified ? 'yes' : 'no', $verified ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		return $verified;
	}

	/**
	 * Is the IP in Google's or Bing's official lists? (such IPs are never blocked)
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	public static function is_official_crawler_ip( $ip ) {
		return PNCP_Ip::in_any( $ip, PNCP_Ip::list_of( 'google' ) ) || PNCP_Ip::in_any( $ip, PNCP_Ip::list_of( 'bing' ) );
	}

	/**
	 * The IPv4/IPv6 addresses of a hostname.
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private static function forward_ips( $host ) {
		$pre = apply_filters( 'pncp_forward_dns', null, $host );
		if ( is_array( $pre ) ) {
			return $pre;
		}
		$ips     = array();
		$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failure = unverified.
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$ips[] = $record['ip'];
				}
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = inet_ntop( inet_pton( $record['ipv6'] ) );
				}
			}
		}
		return $ips;
	}

	/**
	 * Counts the IP's requests in the current minute, per type (IPv6: per /64, see PNCP_Ip::limit_key()).
	 * Object cache if available, otherwise a transient.
	 *
	 * @param string $ip     IP.
	 * @param string $bucket Type (rate_page, rate_search, rate_rest).
	 * @return int
	 */
	private static function hit( $ip, $bucket ) {
		$key = 'pncp_rl_' . md5( $bucket . '|' . PNCP_Ip::limit_key( $ip ) ) . '_' . (int) floor( time() / MINUTE_IN_SECONDS );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'pncp', 2 * MINUTE_IN_SECONDS );
			return (int) wp_cache_incr( $key, 1, 'pncp' );
		}
		$count = self::db_incr( $key, 2 * MINUTE_IN_SECONDS );
		if ( null !== $count ) {
			return $count;
		}
		// Database without ON DUPLICATE KEY (e.g. SQLite): plain transient, not atomic.
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 2 * MINUTE_IN_SECONDS );
		return $count;
	}

	/**
	 * Atomic counter in the database, for sites without an object cache. With concurrent requests, the "read +1
	 * write" of transients loses counts (confirmed: limit 50, up to 65 got through). Here the increment
	 * happens inside MySQL and the new value is read with LAST_INSERT_ID() on the same connection.
	 * It is stored as a regular transient (with timeout), so WordPress cleans it up like all transients.
	 *
	 * @param string $key Transient key.
	 * @param int    $ttl Seconds.
	 * @return int|null Null if the database does not support it.
	 */
	private static function db_incr( $key, $ttl ) {
		global $wpdb;
		if ( ! ( $wpdb instanceof wpdb ) || ! ( $wpdb->dbh instanceof mysqli ) ) {
			return null;
		}
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Atomic counter: not possible with the options API.
		$rows = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off')
				ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(option_value + 1)",
				'_transient_' . $key
			)
		);
		if ( false === $rows ) {
			$wpdb->suppress_errors( $suppress );
			return null;
		}
		$insert_id = (int) $wpdb->insert_id; // mysqli_insert_id() of the same (write) connection: = option_value + 1.
		if ( 1 === (int) $rows ) {
			// New row: the expiry, for transient cleanup.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
					'_transient_timeout_' . $key,
					(string) ( time() + (int) $ttl )
				)
			);
			$count = 1;
		} else {
			// No separate SELECT LAST_INSERT_ID(): with HyperDB / read-write split it would go to a replica.
			$count = $insert_id;
			if ( $count < 2 ) {
				$wpdb->suppress_errors( $suppress );
				return null; // Unexpected: fall back to the transient.
			}
		}
		// phpcs:enable
		$wpdb->suppress_errors( $suppress );
		return $count;
	}

	/**
	 * The response is not stored in any cache: headers for browsers / CDN, DONOTCACHEPAGE for PHP
	 * cache plugins (WP Super Cache, W3TC, WP Rocket, etc.) and X-LiteSpeed-Cache-Control for LiteSpeed.
	 * Otherwise a 403 for a bot would later be served to visitors, or a token feed to everyone.
	 */
	public static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Cache plugins constant.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/**
	 * Ends the request with 403 or 429, without it being cached.
	 *
	 * @param int $code HTTP status code.
	 */
	public static function die_html( $code ) {
		self::no_cache();
		if ( 429 === $code ) {
			header( 'Retry-After: 60' );
			$message = __( 'Too many requests. Please try again in a minute.', 'scrapelock' );
		} else {
			$message = __( 'Access denied.', 'scrapelock' );
		}
		wp_die( esc_html( $message ), esc_html( (string) $code ), array( 'response' => (int) $code ) );
	}

	/* ---------- Bot trap ---------- */

	/**
	 * The trap path: fixed for the site, different on every site.
	 *
	 * @return string e.g. /cp-a1b2c3d4e5/
	 */
	public static function trap_path() {
		return '/cp-' . substr( hash_hmac( 'sha256', 'pncp-trap', wp_salt( 'nonce' ) ), 0, 10 ) . '/';
	}

	/**
	 * Whether the trap link is printed on this page.
	 *
	 * @return bool
	 */
	private static function trap_shown() {
		$s = PNCP_Options::get();
		return ! empty( $s['enabled'] ) && ! empty( $s['trap_enabled'] ) && ! PNCP_Options::user_is_exempt( $s );
	}

	/**
	 * Hides the trap link with CSS through wp_add_inline_style() (no extra file, no inline style attribute).
	 */
	public static function trap_style() {
		if ( ! self::trap_shown() ) {
			return;
		}
		wp_register_style( 'pncp-trap', false, array(), PNCP_VERSION );
		wp_enqueue_style( 'pncp-trap' );
		wp_add_inline_style( 'pncp-trap', '.pncp-hp{position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important}' );
	}

	/**
	 * Invisible link at the end of every page: humans do not see it (CSS of trap_style()), screen readers
	 * ignore it (aria-hidden, tabindex=-1), search engines ignore it (robots.txt, nofollow).
	 */
	public static function trap_link() {
		if ( ! self::trap_shown() ) {
			return;
		}
		printf(
			'<a href="%s" rel="nofollow noindex" tabindex="-1" aria-hidden="true" class="pncp-hp">%s</a>',
			esc_url( home_url( self::trap_path() ) ),
			esc_html__( 'Archive', 'scrapelock' )
		);
	}

	/**
	 * WordPress's speculative loading never preloads the trap.
	 *
	 * @param string[] $paths Paths.
	 * @return string[]
	 */
	public static function speculation_exclude( $paths ) {
		$paths   = (array) $paths;
		$paths[] = self::trap_path() . '*';
		return $paths;
	}

	/**
	 * Whoever opens the trap gets blocked.
	 *
	 * A third-party site cannot get your visitors blocked (e.g. with <img src="…trap">):
	 * browsers send Sec-Fetch-Site/Sec-Fetch-Dest, and blocking happens only for navigation
	 * from the site itself or for requests without these headers (bots). Prefetch does not count.
	 */
	public static function trap_guard() {
		$s = PNCP_Options::get();
		if ( empty( $s['enabled'] ) || empty( $s['trap_enabled'] ) ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = PNCP_Private_Urls::relative_path( rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) ) );
		if ( 0 !== stripos( trailingslashit( $path ), self::trap_path() ) ) {
			return;
		}

		self::no_cache();
		header( 'X-Robots-Tag: noindex, nofollow' );

		$ip = PNCP_Ip::client_ip();
		if ( self::trap_should_ban( $ip ) ) {
			PNCP_Log::add( 'trap', 403, $ip );
			// "Block after 0 violations" = never block: log only.
			if ( (int) $s['ban_strikes'] > 0 ) {
				PNCP_Log::ban( $ip, (int) $s['ban_minutes'], 'trap' );
			}
		}

		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Forbidden';
		exit;
	}

	/**
	 * Search engines without verification (they publish no IP / DNS lists we check). If they did not see
	 * the Disallow (e.g. a physical robots.txt or a stale copy), they are not blocked by the trap.
	 *
	 * @return string[]
	 */
	public static function trap_exempt_agents() {
		return (array) apply_filters( 'pncp_trap_exempt_agents', array( 'DuckDuckBot', 'Baiduspider', 'SeznamBot', 'PetalBot', 'Sogou', 'Yeti', 'Qwantbot', 'MojeekBot', 'Ecosia' ) );
	}

	/**
	 * Should the IP that opened the trap be blocked?
	 *
	 * Never: logged-in users, the server, always-allowed IPs, Google / Bing IPs, search engines,
	 * IPs that may be spoofed (trust every proxy), prefetch, requests from a third-party site or
	 * that are not navigation, and human clicks (Sec-Fetch-User, e.g. a link in a comment).
	 * Browser without Sec-Fetch (old Safari / Firefox): blocked only on the second hit within 10 minutes.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	private static function trap_should_ban( $ip ) {
		$s = PNCP_Options::get();
		if ( '' === $ip || PNCP_Options::user_is_exempt( $s ) || PNCP_Ip::is_local( $ip ) || ! PNCP_Ip::reliable() || self::is_official_crawler_ip( $ip ) ) {
			return false;
		}
		if ( PNCP_Ip::in_any( $ip, PNCP_Options::parse_list( $s['allow_ips'] ) ) ) {
			return false;
		}
		$ua     = self::user_agent();
		$family = self::claimed_crawler( $ua );
		if ( ( '' !== $family && ( self::is_verified_crawler( $family, $ip ) || self::$verify_inconclusive ) ) || self::ua_in_list( $ua, self::trap_exempt_agents() ) ) {
			return false; // Verified, or not verifiable right now: a real search engine is never banned.
		}
		if ( self::is_prefetch() ) {
			return false;
		}
		$header = static function ( $name ) {
			return isset( $_SERVER[ $name ] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) ) ) : '';
		};
		$site   = $header( 'HTTP_SEC_FETCH_SITE' );
		$dest   = $header( 'HTTP_SEC_FETCH_DEST' );
		if ( '' !== $site && ! in_array( $site, array( 'same-origin', 'none' ), true ) ) {
			return false; // From another site (cross-site / same-site): no block.
		}
		if ( '' !== $dest && 'document' !== $dest ) {
			return false; // Image, iframe, script, etc.: not navigation.
		}
		if ( '?1' === $header( 'HTTP_SEC_FETCH_USER' ) ) {
			return false; // Human click on a link (e.g. someone posted the address in a comment).
		}
		if ( '' === $site && 0 === strpos( $ua, 'Mozilla/' ) ) {
			// Browser without Sec-Fetch: perhaps an old browser that loaded another site's <img>. Second time = bot.
			$key  = 'pncp_tr_' . md5( $ip );
			$seen = (int) PNCP_Log::cache_get( $key );
			PNCP_Log::cache_set( $key, $seen + 1, 10 * MINUTE_IN_SECONDS );
			return $seen >= 1;
		}
		return true;
	}

	/* ---------- robots.txt and WP Rocket ---------- */

	/**
	 * The AI crawlers listed in robots.txt.
	 *
	 * @return string[]
	 */
	public static function robots_agents() {
		return (array) apply_filters(
			'pncp_robots_ai_agents',
			array( 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'CCBot', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai', 'PerplexityBot', 'Perplexity-User', 'Bytespider', 'Google-Extended', 'Google-CloudVertexBot', 'Applebot-Extended', 'meta-externalagent', 'meta-externalfetcher', 'FacebookBot', 'Amazonbot', 'Diffbot', 'cohere-ai', 'cohere-training-data-crawler', 'AI2Bot', 'Ai2Bot-Dolma', 'YouBot', 'DuckAssistBot', 'MistralAI-User', 'PanguBot', 'Timpibot', 'omgili', 'Webzio-Extended', 'ImagesiftBot', 'img2dataset', 'TikTokSpider' )
		);
	}

	/**
	 * Disallow for AI crawlers and for the trap in the virtual robots.txt. Does not affect search engines.
	 *
	 * @param string $output    The robots.txt.
	 * @param bool   $is_public Whether the site allows indexing.
	 * @return string
	 */
	public static function robots_txt( $output, $is_public ) {
		$s = PNCP_Options::get();
		if ( empty( $s['enabled'] ) || ! $is_public ) {
			return $output;
		}

		$block = '';
		if ( ! empty( $s['trap_enabled'] ) ) {
			$block .= "\n# ScrapeLock\nUser-agent: *\nDisallow: " . wp_parse_url( home_url( self::trap_path() ), PHP_URL_PATH ) . "\n";
		}
		if ( ! empty( $s['robots_ai_block'] ) ) {
			$block .= "\n# ScrapeLock: AI crawlers\n";
			$search = array_map( 'strtolower', PNCP_Options::allowed_ai_search() );
			foreach ( self::robots_agents() as $agent ) {
				if ( in_array( strtolower( $agent ), $search, true ) ) {
					continue; // AI search allowed: no Disallow is added.
				}
				$block .= 'User-agent: ' . $agent . "\n";
			}
			$block .= "Disallow: /\n";
		}
		return '' === $block ? $output : rtrim( $output ) . "\n" . $block;
	}

	/**
	 * Tells WP Rocket not to serve cache to blocked User-Agents.
	 *
	 * @param string[] $uas User-Agents.
	 * @return string[]
	 */
	public static function rocket_reject_ua( $uas ) {
		$s = PNCP_Options::get();
		if ( empty( $s['enabled'] ) || empty( $s['block_bots'] ) ) {
			return $uas;
		}
		$uas    = (array) $uas;
		$search = array_map( 'strtolower', PNCP_Options::allowed_ai_search() );
		foreach ( PNCP_Options::parse_list( $s['bot_list'] ) as $needle ) {
			if ( in_array( strtolower( $needle ), $search, true ) ) {
				continue;
			}
			$uas[] = preg_quote( $needle, '#' );
		}
		return $uas;
	}
}
