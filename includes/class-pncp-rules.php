<?php
/**
 * Generator of web server and Cloudflare rules, tailored to this specific site.
 *
 * The plugin protects only what reaches PHP. Pages from page cache, static files (images, .xml)
 * and the folder with the clean originals need rules on the server or the CDN. The plugin keeps no Cloudflare
 * API token: it outputs the rules and you apply them yourself.
 *
 * @package ScrapeLock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nginx, Apache and Cloudflare rules.
 */
final class PNCP_Rules {

	/**
	 * Platforms that load images from your site (hotlinking allowed).
	 *
	 * @return string[]
	 */
	public static function hotlink_allowed() {
		return (array) apply_filters(
			'pncp_hotlink_allowed',
			array( 'google.', 'googleusercontent.', 'bing.com', 'skroutz.gr', 'bestprice.gr', 'facebook.com', 'instagram.com', 'pinterest.' )
		);
	}

	/**
	 * The site domain.
	 *
	 * @return string
	 */
	private static function host() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * The uploads path within the site (e.g. /wp-content/uploads).
	 *
	 * @return string
	 */
	private static function uploads_path() {
		$path = (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH );
		return '' === $path ? '/wp-content/uploads' : untrailingslashit( $path );
	}

	/**
	 * The User-Agents of the bot list.
	 *
	 * @return string[]
	 */
	private static function bots() {
		return PNCP_Options::parse_list( PNCP_Options::get()['bot_list'] );
	}

	/**
	 * The always-allowed User-Agents.
	 *
	 * @return string[]
	 */
	private static function allowed() {
		$out = array();
		foreach ( PNCP_Options::parse_list( PNCP_Options::get()['allow_list'] ) as $entry ) {
			$entry = PNCP_Options::split_allow_entry( $entry );
			// Entries tied to IPs are checked by WordPress: server rules cannot combine User-Agent and IP here.
			if ( ! $entry['pinned'] && '' !== $entry['ua'] ) {
				$out[] = $entry['ua'];
			}
		}
		return array_merge( $out, PNCP_Options::allowed_ai_search() );
	}

	/**
	 * The private paths as regex (for Nginx / Apache).
	 *
	 * @return string[]
	 */
	private static function private_regexes() {
		$out = array();
		foreach ( PNCP_Options::parse_list( PNCP_Options::get()['private_paths'] ) as $pattern ) {
			$path = (string) wp_parse_url( $pattern, PHP_URL_PATH );
			if ( '' === $path ) {
				continue;
			}
			$full  = (string) wp_parse_url( home_url( PNCP_Private_Urls::relative_path( $path ) ), PHP_URL_PATH );
			$out[] = '^' . str_replace( '\*', '.*', preg_quote( $full, '#' ) ) . '$';
		}
		return $out;
	}

	/**
	 * Regex for Nginx/Apache from a word list.
	 *
	 * @param string[] $words Words.
	 * @return string
	 */
	private static function alternation( $words ) {
		$quoted = array();
		foreach ( $words as $word ) {
			$quoted[] = str_replace( array( ' ', '"' ), array( '\\s', '' ), preg_quote( $word, '#' ) );
		}
		return '(' . implode( '|', $quoted ) . ')';
	}

	/**
	 * Nginx rules: both parts together (for copying into a notes file).
	 *
	 * @return string
	 */
	public static function nginx() {
		return '# ScrapeLock ' . PNCP_VERSION . ' — ' . self::host() . "\n# 1) In http { } (e.g. /etc/nginx/conf.d/scrapelock.conf):\n"
			. self::nginx_http() . "\n# 2) In the server { } of " . self::host() . ":\n" . self::nginx_server();
	}

	/**
	 * Nginx, part 1: inside http { } (map for the bots, limit zone).
	 *
	 * @return string
	 */
	public static function nginx_http() {
		$s     = PNCP_Options::get();
		$lines = array();
		if ( $s['block_bots'] && self::bots() ) {
			$lines[] = 'map $http_user_agent $pncp_bad_bot {';
			$lines[] = '    default 0;';
			if ( self::allowed() ) {
				$lines[] = '    "~*' . self::alternation( self::allowed() ) . '" 0;'; // First: it matches first.
			}
			$lines[] = '    "~*' . self::alternation( self::bots() ) . '" 1;';
			if ( $s['block_empty_ua'] ) {
				$lines[] = '    "" 1;';
			}
			$lines[] = '}';
		}
		if ( self::private_regexes() ) {
			// Token for the static feeds: in the URL (cp_token) or in the header. Put your own in place of YOUR_TOKEN.
			$lines[] = 'map "$arg_cp_token:$http_x_scrapelock_token" $pncp_token_ok {';
			$lines[] = '    default 0;';
			$lines[] = '    "~^YOUR_TOKEN:" 1;';
			$lines[] = '    "~:YOUR_TOKEN$" 1;';
			$lines[] = '}';
		}
		$rate    = (int) $s['rate_limit'] > 0 ? (int) $s['rate_limit'] : 60;
		$lines[] = 'limit_req_zone $binary_remote_addr zone=pncp_pages:10m rate=' . $rate . 'r/m;';
		$lines[] = 'limit_req_status 429;';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Nginx, part 2: inside the site's server { }.
	 *
	 * @return string
	 */
	public static function nginx_server() {
		$s       = PNCP_Options::get();
		$host    = self::host();
		$uploads = self::uploads_path();
		$backup  = (string) get_option( 'pncp_backup_dir' );
		$lines   = array();
		if ( $s['block_bots'] && self::bots() ) {
			$lines[] = '# Blocks bots BEFORE the page cache (FastCGI cache, WP Rocket, WP Super Cache).';
			$lines[] = 'if ($pncp_bad_bot) { return 403; }';
			$lines[] = '';
		}
		$lines[] = '# Page limit per IP (also for cached pages): put this line ONLY in the location that serves pages';
		$lines[] = '# (PHP / page cache), NOT where CSS, JS, images and fonts are served: one page loads dozens of them.';
		$lines[] = '# Behind Cloudflare: set real_ip_header CF-Connecting-IP first (see the plugin FAQ).';
		$lines[] = '#   limit_req zone=pncp_pages burst=20 nodelay;';
		$lines[] = '';
		if ( '' !== $backup ) {
			$lines[] = '# Clean originals of watermarked images: never public.';
			$lines[] = 'location ^~ ' . $uploads . '/' . $backup . '/ { deny all; return 404; }';
			$lines[] = '';
		}
		$lines[] = '# Hotlink: images load only from your site and from search engines / price comparison platforms.';
		$lines[] = '# (If a location for images already exists, e.g. for expires, put the two lines inside it.)';
		$lines[] = 'location ~* ^' . preg_quote( $uploads, '#' ) . '/.*\.(jpe?g|png|webp|gif|avif)$ {';
		// The domain as regex: as a plain name it would clash with server_names ("conflicting parameter" in nginx -t).
		$lines[] = '    valid_referers none blocked server_names ~^([^/]*\\.)?' . self::nginx_referer( $host ) . '(:|/|$) ~' . implode( ' ~', array_map( array( __CLASS__, 'nginx_referer' ), self::hotlink_allowed() ) ) . ';';
		$lines[] = '    if ($invalid_referer) { return 403; }';
		$lines[] = '}';
		$regexes = self::private_regexes();
		if ( $regexes ) {
			$lines[] = '';
			$lines[] = '# Feeds: static files only with the token (map $pncp_token_ok in http { }). Feeds generated by PHP';
			$lines[] = '# go to WordPress, which checks the token, Bearer, allowed IPs and administrator.';
			$lines[] = '# (Only return inside the ifs: otherwise the Nginx "if is evil" problem would lose try_files.)';
			foreach ( $regexes as $regex ) {
				$lines[] = 'location ~* ' . $regex . ' {';
				$lines[] = '    error_page 418 = @pncp_static_feed;';
				$lines[] = '    if (-f $request_filename) { return 418; }';
				$lines[] = '    try_files $uri /index.php?$args;';
				$lines[] = '}';
			}
			$lines[] = 'location @pncp_static_feed {';
			$lines[] = '    if ($pncp_token_ok = 0) { return 403; }';
			$lines[] = '    add_header Cache-Control "private, no-store" always;';
			$lines[] = '    add_header Referrer-Policy "no-referrer" always;';
			$lines[] = '    try_files $uri =404;';
			$lines[] = '}';
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Referer regex for Nginx.
	 *
	 * @param string $domain Domain.
	 * @return string
	 */
	public static function nginx_referer( $domain ) {
		return str_replace( '.', '\.', rtrim( $domain, '.' ) );
	}

	/**
	 * Apache rules (.htaccess, above the WordPress rules).
	 *
	 * @return string
	 */
	public static function apache() {
		$s       = PNCP_Options::get();
		$host    = self::host();
		$uploads = ltrim( self::uploads_path(), '/' );
		$backup  = (string) get_option( 'pncp_backup_dir' );
		$lines   = array();

		$lines[] = '# BEGIN ScrapeLock ' . PNCP_VERSION;
		$lines[] = '<IfModule mod_rewrite.c>';
		$lines[] = 'RewriteEngine On';
		if ( $s['block_bots'] && self::bots() ) {
			$lines[] = '# Bots in the list: 403 before the page cache.';
			if ( self::allowed() ) {
				$lines[] = 'RewriteCond %{HTTP_USER_AGENT} !' . self::alternation( self::allowed() ) . ' [NC]';
			}
			$lines[] = 'RewriteCond %{HTTP_USER_AGENT} ' . self::alternation( self::bots() ) . ' [NC]';
			$lines[] = 'RewriteRule .* - [F,L]';
			if ( $s['block_empty_ua'] ) {
				$lines[] = 'RewriteCond %{HTTP_USER_AGENT} ^-?$';
				$lines[] = 'RewriteRule .* - [F,L]';
			}
		}
		if ( '' !== $backup ) {
			$lines[] = '# Clean originals: never public (there is also an .htaccess inside the folder).';
			$lines[] = 'RewriteRule ^' . preg_quote( $uploads . '/' . $backup, '#' ) . '/ - [F,L]';
		}
		$lines[] = '# Image hotlinking.';
		$lines[] = 'RewriteCond %{HTTP_REFERER} !^$';
		$lines[] = 'RewriteCond %{HTTP_REFERER} !^https?://([^/]+\.)?' . preg_quote( $host, '#' ) . '/ [NC]';
		foreach ( self::hotlink_allowed() as $domain ) {
			$lines[] = 'RewriteCond %{HTTP_REFERER} !' . preg_quote( $domain, '#' ) . ' [NC]';
		}
		$lines[] = 'RewriteRule ^' . preg_quote( $uploads, '#' ) . '/.*\.(jpe?g|png|webp|gif|avif)$ - [F,NC,L]';
		foreach ( self::private_regexes() as $regex ) {
			$lines[] = '# Feed that is a static file: only with the token (put yours in place of YOUR_TOKEN).';
			$lines[] = '# PHP feeds are checked by WordPress (token, Bearer, allowed IPs, administrator).';
			$lines[] = 'RewriteCond %{REQUEST_FILENAME} -f';
			$lines[] = 'RewriteCond %{QUERY_STRING} !(^|&)cp_token=YOUR_TOKEN(&|$)';
			$lines[] = 'RewriteCond %{HTTP:X-ScrapeLock-Token} !^YOUR_TOKEN$';
			$lines[] = 'RewriteRule ' . str_replace( '^/', '^', $regex ) . ' - [F,L]';
		}
		$lines[] = '# Authorization: Bearer reaches PHP with PHP-FPM / CGI too.';
		$lines[] = 'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]';
		$lines[] = '</IfModule>';
		$lines[] = '# END ScrapeLock';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Exclusion of the always-allowed User-Agents in the Cloudflare expression.
	 *
	 * @return string
	 */
	private static function cf_allowed() {
		$parts = array();
		foreach ( self::allowed() as $ua ) {
			$parts[] = 'lower(http.user_agent) contains "' . strtolower( str_replace( '"', '', $ua ) ) . '"';
		}
		return $parts ? ' and not (' . implode( ' or ', $parts ) . ')' : '';
	}

	/**
	 * Expressions for Cloudflare (Security → WAF → Custom rules / Rate limiting rules).
	 *
	 * @return array<string, array{title:string, action:string, expression:string}>
	 */
	public static function cloudflare() {
		$s       = PNCP_Options::get();
		$host    = self::host();
		$uploads = self::uploads_path();
		$rules   = array();

		$bots = self::bots();
		if ( $s['block_bots'] && $bots ) {
			$parts = array();
			foreach ( $bots as $bot ) {
				$parts[] = 'lower(http.user_agent) contains "' . strtolower( str_replace( '"', '', $bot ) ) . '"';
			}
			if ( $s['block_empty_ua'] ) {
				$parts[] = 'http.user_agent eq ""';
			}
			$rules['bots'] = array(
				'title'      => __( 'Scrapers and AI bots', 'scrapelock' ),
				'action'     => 'Block',
				'expression' => '(http.host eq "' . $host . '") and not cf.client.bot and (' . implode( ' or ', $parts ) . ')' . self::cf_allowed(),
			);
		}

		$rules['fake'] = array(
			'title'      => __( 'Fake Googlebot / Bingbot (not a verified Cloudflare bot)', 'scrapelock' ),
			'action'     => 'Block',
			'expression' => '(http.host eq "' . $host . '") and not cf.client.bot and (lower(http.user_agent) contains "googlebot" or lower(http.user_agent) contains "bingbot" or lower(http.user_agent) contains "applebot")',
		);

		$referers = array( 'http.referer eq ""', 'http.referer contains "' . $host . '"' );
		foreach ( self::hotlink_allowed() as $domain ) {
			$referers[] = 'http.referer contains "' . $domain . '"';
		}
		$rules['hotlink'] = array(
			'title'      => __( 'Image hotlinking', 'scrapelock' ),
			'action'     => 'Block',
			'expression' => '(http.host eq "' . $host . '") and starts_with(http.request.uri.path, "' . $uploads . '/") and http.request.uri.path.extension in {"jpg" "jpeg" "png" "webp" "gif" "avif"} and not (' . implode( ' or ', $referers ) . ')',
		);

		$backup = (string) get_option( 'pncp_backup_dir' );
		if ( '' !== $backup ) {
			$rules['originals'] = array(
				'title'      => __( 'Clean originals folder', 'scrapelock' ),
				'action'     => 'Block',
				'expression' => '(http.host eq "' . $host . '") and starts_with(http.request.uri.path, "' . $uploads . '/' . $backup . '/")',
			);
		}

		$rate          = (int) $s['rate_limit'] > 0 ? (int) $s['rate_limit'] : 60;
		$rules['rate'] = array(
			'title'      => sprintf(
				/* translators: %d: pages per minute */
				__( 'Rate limiting: %d requests per minute per IP (Rate limiting rules, characteristic: IP)', 'scrapelock' ),
				$rate
			),
			'action'     => __( 'Block · period 1 minute (on the free plan: 10 sec, with limit / 6)', 'scrapelock' ),
			// Pages only: not files (CSS, JS, images, fonts), not wp-admin / wp-includes / wp-content,
			// not cart AJAX (wc-ajax, Store API) and not logged-in users.
			'expression' => '(http.host eq "' . $host . '") and not cf.client.bot and http.request.uri.path.extension in {"" "php" "html" "xml"} and not starts_with(http.request.uri.path, "/wp-admin") and not starts_with(http.request.uri.path, "/wp-includes/") and not starts_with(http.request.uri.path, "/wp-content/") and not starts_with(http.request.uri.path, "/wp-json/wc/store") and not http.request.uri.query contains "wc-ajax=" and not http.cookie contains "wordpress_logged_in_"',
		);
		$rules['ai']   = array(
			'title'      => __( 'AI crawlers declared by Cloudflare (category “AI Crawler”)', 'scrapelock' ),
			'action'     => 'Block',
			'expression' => '(http.host eq "' . $host . '") and cf.verified_bot_category in {"AI Crawler" "AI Assistant" "AI Search"}',
		);
		return $rules;
	}
}
