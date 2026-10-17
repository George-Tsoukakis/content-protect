<?php
/**
 * ScrapeLock — δοκιμές HTTP σε πραγματικό WordPress.
 *
 *   WP_DIR=/path/to/wordpress BASE=http://127.0.0.1:8099 php t_http.php
 *
 * Προϋποθέσεις: το plugin ενεργό, το tests/lib/mu-test-harness.php στα mu-plugins, και ο server
 * (π.χ. php -S 127.0.0.1:8099 -t $WP_DIR router.php). Η βάση του WordPress αλλάζει: μόνο για δοκιμές.
 */

$wp_dir = getenv( 'WP_DIR' );
$base   = rtrim( (string) getenv( 'BASE' ), '/' );
if ( ! $wp_dir || ! $base ) {
	fwrite( STDERR, "WP_DIR και BASE απαιτούνται\n" );
	exit( 2 );
}
define( 'WP_USE_THEMES', false );
require $wp_dir . '/wp-load.php';

$fails = 0;
function t( $label, $ok, $extra = '' ) {
	global $fails;
	if ( ! $ok ) {
		++$fails;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $extra && ! $ok ? "  [$extra]" : '' ) . "\n";
}

/** Αίτημα με curl: επιστρέφει [code, headers, body]. */
function req( $path, $headers = array(), $method = 'GET' ) {
	global $base;
	$ch = curl_init( $base . $path );
	$h  = array();
	foreach ( $headers as $k => $v ) {
		$h[] = $k . ': ' . $v;
	}
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_HTTPHEADER     => $h,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_TIMEOUT        => 30,
		)
	);
	$out  = (string) curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$hs   = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );
	return array( $code, strtolower( substr( $out, 0, $hs ) ), substr( $out, $hs ) );
}

function browser( $ip, $extra = array() ) {
	return array_merge(
		array(
			'X-Test-IP'       => $ip,
			'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36',
			'Accept-Language' => 'el-GR,el;q=0.9',
		),
		$extra
	);
}

function settings( $changes ) {
	$s = array_merge( PNCP_Options::stored(), $changes );
	update_option( PNCP_OPTION, $s );
	PNCP_Options::flush();
	// The limit counters reset every minute: a limit test must not straddle a minute boundary.
	foreach ( array( 'rate_limit', 'rate_limit_search', 'rate_limit_rest' ) as $key ) {
		if ( ! empty( $changes[ $key ] ) ) {
			same_minute();
			break;
		}
	}
}

/** Waits for the next minute if fewer than 15 seconds are left in the current one. */
function same_minute() {
	$left = 60 - time() % 60;
	if ( $left < 15 ) {
		sleep( $left + 1 );
	}
}

function fresh() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%pncp\_%' OR option_name LIKE '\_transient\_timeout\_%pncp\_%'" );
	PNCP_Log::unban_all();
	PNCP_Log::clear();
	wp_cache_flush(); PNCP_Log::reset_cache();
}

function log_reasons() {
	global $wpdb;
	return $wpdb->get_col( "SELECT reason FROM {$wpdb->prefix}pncp_log ORDER BY id" );
}

update_option( 'pncp_emergency_until', 0 );
settings(
	array(
		'enabled'                 => 1,
		'rate_limit'              => 0,
		'rate_limit_search'       => 0,
		'rate_limit_rest'         => 0,
		'require_browser_headers' => 0,
		'ban_strikes'             => 3,
		'ban_minutes'             => 60,
		'trust_any_proxy'         => 0,
		'ip_header'               => 'remote_addr',
		'private_paths'           => '/skroutz.xml',
		'allow_ips'               => '',
	)
);
fresh();
// Αφετηρία: το token της 1.4 (όπως μετά την αναβάθμιση), ώστε το script να τρέχει ξανά όσες φορές χρειαστεί.
PNCP_Private_Urls::import_plain( 'Legacy1234567890Token' );
delete_option( PNCP_Private_Urls::STATS_OPTION );
$post = get_posts( array( 'numberposts' => 1, 'post_type' => 'post' ) )[0];
$url  = wp_parse_url( get_permalink( $post ), PHP_URL_PATH );

/* ---------- Κανονικός επισκέπτης ---------- */
list( $code, $hdr, $body ) = req( $url, browser( '203.0.113.10' ) );
t( 'browser: 200', 200 === $code, (string) $code );
t( 'browser: X-Frame-Options SAMEORIGIN', false !== strpos( $hdr, 'x-frame-options: sameorigin' ) );
t( "browser: CSP frame-ancestors 'self'", false !== strpos( $hdr, "content-security-policy: frame-ancestors 'self'" ) );
t( 'browser: tdm-reservation header', false !== strpos( $hdr, 'tdm-reservation: 1' ) );
t( 'browser: meta tdm-reservation', false !== strpos( $body, '<meta name="tdm-reservation" content="1">' ) );
t( 'browser: robots noai', (bool) preg_match( '/<meta name=.robots.[^>]*noai/', $body ) );
$head = (string) strstr( $body, '</head>', true );
t( 'browser: protect.js μέσα στο <head>', false !== strpos( $head, 'assets/js/protect.js' ) );
$first_src = preg_match( '/<script[^>]*\bsrc=[\'"]([^\'"]+)/', $head, $m ) ? $m[1] : '';
t( 'browser: protect.js είναι το πρώτο εξωτερικό script', false !== strpos( $first_src, 'protect.js' ), $first_src );
t( 'browser: data-cfasync=false (Rocket Loader)', (bool) preg_match( '/<script[^>]*data-cfasync="false"[^>]*protect\.js/', $head ) || (bool) preg_match( '/<script[^>]*protect\.js[^>]*data-cfasync="false"/', $head ) );
t( 'browser: παγίδα στη σελίδα με aria-hidden', false !== strpos( $body, PNCP_Bots::trap_path() ) && false !== strpos( $body, 'aria-hidden="true"' ) );
t( 'browser: η παγίδα κρύβεται με CSS στο <head>, χωρίς inline style', false !== strpos( $head, '.pncp-hp{position:absolute' ) && ! preg_match( '/class="pncp-hp"[^>]*style=/', $body ) );
t( 'browser: τίποτα στο αρχείο', array() === log_reasons(), implode( ',', log_reasons() ) );

/* ---------- Bots ---------- */
list( $code ) = req( $url, array( 'X-Test-IP' => '198.51.100.1', 'User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)' ) );
t( 'GPTBot: 403', 403 === $code, (string) $code );
list( $code ) = req( $url, array( 'X-Test-IP' => '198.51.100.2', 'User-Agent' => 'Mozilla/5.0 HeadlessChrome/141.0' ) );
t( 'HeadlessChrome (νέο στη λίστα): 403', 403 === $code, (string) $code );
list( $code ) = req( $url, array( 'X-Test-IP' => '198.51.100.3', 'User-Agent' => '' ) );
t( 'χωρίς User-Agent: 403', 403 === $code, (string) $code );
list( $code ) = req( $url, array( 'X-Test-IP' => '198.51.100.4', 'User-Agent' => 'facebookexternalhit/1.1' ) );
t( 'facebookexternalhit (πάντα επιτρεπτό): 200', 200 === $code, (string) $code );
t( 'αρχείο: bot_ua, bot_ua, empty_ua', array( 'bot_ua', 'bot_ua', 'empty_ua' ) === log_reasons(), implode( ',', log_reasons() ) );

/* ---------- 2.3: προεπιλογές νέων εγκαταστάσεων, «πάντα επιτρεπτοί» δεμένοι σε IP ---------- */
$d = PNCP_Options::defaults();
t( 'νέα εγκατάσταση: όρια ενεργά (60 / 20 / 60), DevTools ανενεργό', 60 === $d['rate_limit'] && 20 === $d['rate_limit_search'] && 60 === $d['rate_limit_rest'] && 0 === $d['block_devtools'] );
$p = PNCP_Options::profiles();
t( 'προφίλ: DevTools μόνο σε Αυστηρό / Μέγιστο', ! isset( $p['balanced']['block_devtools'] ) && 1 === $p['strict']['block_devtools'] && 1 === $p['maximum']['block_devtools'] );
fresh();
settings( array( 'require_browser_headers' => 1, 'allow_list' => "PriceBot@198.51.100.50, 198.51.100.60/31\nSkroutz\nBadEntry@not-an-ip" ) );
$pb = array( 'User-Agent' => 'PriceBot/1.0' );
list( $code ) = req( $url, $pb + array( 'X-Test-IP' => '198.51.100.50' ) );
t( 'PriceBot@IP από τη σωστή IP: 200', 200 === $code, (string) $code );
list( $code ) = req( $url, $pb + array( 'X-Test-IP' => '198.51.100.61' ) );
t( 'PriceBot@IP από IP μέσα στο CIDR: 200', 200 === $code, (string) $code );
list( $code ) = req( $url, $pb + array( 'X-Test-IP' => '198.51.100.70' ) );
t( 'PriceBot@IP από άλλη IP: 403 (Μόνο browsers)', 403 === $code, (string) $code );
list( $code ) = req( $url, array( 'User-Agent' => 'Skroutz/2.0', 'X-Test-IP' => '198.51.100.71' ) );
t( 'Skroutz χωρίς @: από οποιαδήποτε IP, όπως πριν', 200 === $code, (string) $code );
list( $code ) = req( $url, array( 'User-Agent' => 'BadEntry/1.0', 'X-Test-IP' => '198.51.100.72' ) );
t( 'γραμμή με @ χωρίς έγκυρη IP: δεν γίνεται ποτέ δεκτή', 403 === $code, (string) $code );
t( 'κανόνες server: οι γραμμές με @ δεν μπαίνουν στη λίστα επιτρεπτών', false === strpos( PNCP_Rules::nginx() . PNCP_Rules::apache() . wp_json_encode( PNCP_Rules::cloudflare() ), 'PriceBot' ) && false !== strpos( PNCP_Rules::nginx(), 'Skroutz' ) );
settings( array( 'require_browser_headers' => 0, 'allow_list' => PNCP_Options::default_allow_list() ) );
fresh();

/* ---------- Ψεύτικο / πραγματικό Googlebot ---------- */
$gb = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
list( $code ) = req( $url, array( 'X-Test-IP' => '45.1.2.3', 'User-Agent' => $gb, 'X-Test-RDNS' => 'scraper.example.net' ) );
t( 'ψεύτικο Googlebot: 403', 403 === $code, (string) $code );
list( $code ) = req( $url, array( 'X-Test-IP' => '45.1.2.4', 'User-Agent' => $gb, 'X-Test-RDNS' => 'evil.googlebot.com.example.net' ) );
t( 'ψεύτικο Googlebot (rDNS που απλώς περιέχει googlebot.com): 403', 403 === $code, (string) $code );
list( $code ) = req( $url, array( 'X-Test-IP' => '66.249.66.1', 'User-Agent' => $gb, 'X-Test-RDNS' => 'crawl-66-249-66-1.googlebot.com', 'X-Test-FDNS' => '66.249.66.1' ) );
t( 'πραγματικό Googlebot (rDNS + forward DNS): 200', 200 === $code, (string) $code );
PNCP_Ip::store_list( 'google', array( '66.249.64.0/27' ) );
settings( array( 'rate_limit' => 1 ) );
$ok = true;
for ( $i = 0; $i < 4; $i++ ) {
	list( $code ) = req( $url, array( 'X-Test-IP' => '66.249.64.5', 'User-Agent' => $gb ) );
	$ok = $ok && 200 === $code;
}
t( 'Googlebot από επίσημη λίστα IP: ποτέ όριο (4 σελίδες με όριο 1)', $ok );
settings( array( 'rate_limit' => 0 ) );

/* ---------- Όριο, παραβάσεις, αποκλεισμός ---------- */
fresh();
settings( array( 'rate_limit' => 2, 'ban_strikes' => 1, 'ban_minutes' => 30 ) );
$codes = array();
for ( $i = 0; $i < 4; $i++ ) {
	$codes[] = req( $url, browser( '203.0.113.20' ) )[0];
}
t( 'όριο 2/λεπτό με 1 παράβαση: 200,200,429,403', array( 200, 200, 429, 403 ) === $codes, implode( ',', $codes ) );
wp_cache_flush(); PNCP_Log::reset_cache();
t( 'η IP μπήκε στους αποκλεισμούς', isset( PNCP_Log::active_bans()['203.0.113.20'] ) );
list( $code ) = req( $url, browser( '203.0.113.21' ) );
t( 'άλλη IP δεν επηρεάζεται', 200 === $code, (string) $code );
PNCP_Log::unban( '203.0.113.20' );
fresh();
settings( array( 'rate_limit' => 0, 'rate_limit_search' => 1, 'ban_strikes' => 0 ) );
$codes = array( req( '/?s=test', browser( '203.0.113.22' ) )[0], req( '/?s=test2', browser( '203.0.113.22' ) )[0], req( $url, browser( '203.0.113.22' ) )[0] );
t( 'όριο αναζήτησης 1: 200,429 και η σελίδα περνά', array( 200, 429, 200 ) === $codes, implode( ',', $codes ) );
settings( array( 'rate_limit_search' => 0, 'rate_limit_rest' => 2 ) );
$codes = array();
for ( $i = 0; $i < 3; $i++ ) {
	$codes[] = req( '/wp-json/wp/v2/posts', browser( '203.0.113.23' ) )[0];
}
t( 'όριο REST 2: 200,200,429', array( 200, 200, 429 ) === $codes, implode( ',', $codes ) );
settings( array( 'rate_limit_rest' => 0, 'ban_strikes' => 3 ) );

/* ---------- IPv6: όριο ανά δίκτυο /64 ---------- */
fresh();
settings( array( 'rate_limit' => 2, 'ban_strikes' => 0 ) );
$codes = array();
foreach ( array( '2001:db8:5:6::1', '2001:db8:5:6::2', '2001:db8:5:6:aaaa::3' ) as $v6 ) {
	$codes[] = req( $url, browser( $v6 ) )[0];
}
t( 'IPv6: νέα διεύθυνση στο ίδιο /64 δεν παρακάμπτει το όριο (200,200,429)', array( 200, 200, 429 ) === $codes, implode( ',', $codes ) );
list( $code ) = req( $url, browser( '2001:db8:5:7::1' ) );
t( 'IPv6: άλλο /64 δεν επηρεάζεται', 200 === $code, (string) $code );
t( 'IPv6: ::ffff:198.51.100.7 μετρά ως 198.51.100.7', '198.51.100.7' === PNCP_Ip::limit_key( '::ffff:198.51.100.7' ) );
settings( array( 'rate_limit' => 0, 'ban_strikes' => 3 ) );

/* ---------- Applebot / YandexBot: χωρίς αποκλεισμό όταν το DNS δεν δίνει απάντηση ---------- */
$ab = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)';
fresh();
settings( array( 'ban_strikes' => 1, 'ban_minutes' => 30 ) );
// Ο προϋπολογισμός DNS εξαντλημένος (π.χ. από ψεύτικα «Applebot» άλλων IP), και για το επόμενο λεπτό.
$minute = (int) floor( time() / MINUTE_IN_SECONDS );
PNCP_Log::cache_set( 'pncp_dns_' . $minute, 1000, 2 * MINUTE_IN_SECONDS );
PNCP_Log::cache_set( 'pncp_dns_' . ( $minute + 1 ), 1000, 2 * MINUTE_IN_SECONDS );
$codes = array();
for ( $i = 0; $i < 2; $i++ ) {
	$codes[] = req( $url, array( 'X-Test-IP' => '17.58.101.10', 'User-Agent' => $ab, 'X-Test-RDNS' => '17-58-101-10.applebot.apple.com', 'X-Test-FDNS' => '17.58.101.10' ) )[0];
}
wp_cache_flush(); PNCP_Log::reset_cache();
t( 'Applebot χωρίς διαθέσιμο DNS: 403 αλλά χωρίς αποκλεισμό', array( 403, 403 ) === $codes && ! isset( PNCP_Log::active_bans()['17.58.101.10'] ), implode( ',', $codes ) );
fresh();
list( $code ) = req( $url, array( 'X-Test-IP' => '17.58.101.11', 'User-Agent' => $ab, 'X-Test-RDNS' => '17.58.101.11' ) );
wp_cache_flush(); PNCP_Log::reset_cache();
t( 'Applebot χωρίς reverse DNS: 403 αλλά χωρίς αποκλεισμό', 403 === $code && ! isset( PNCP_Log::active_bans()['17.58.101.11'] ), (string) $code );
list( $code ) = req( $url, array( 'X-Test-IP' => '17.58.101.11', 'User-Agent' => $ab, 'X-Test-RDNS' => '17.58.101.11' ) );
wp_cache_flush(); PNCP_Log::reset_cache();
t( 'Applebot χωρίς reverse DNS, 2ο αίτημα (από την cache): πάλι χωρίς αποκλεισμό', 403 === $code && ! isset( PNCP_Log::active_bans()['17.58.101.11'] ), (string) $code );
fresh();
list( $code ) = req( $url, array( 'X-Test-IP' => '17.58.101.12', 'User-Agent' => $ab, 'X-Test-RDNS' => '17-58-101-12.applebot.apple.com', 'X-Test-FDNS' => '17.58.101.12' ) );
t( 'πραγματικό Applebot (rDNS + forward DNS): 200', 200 === $code, (string) $code );
list( $code ) = req( $url, array( 'X-Test-IP' => '45.1.2.9', 'User-Agent' => $ab, 'X-Test-RDNS' => 'scraper.example.net' ) );
wp_cache_flush(); PNCP_Log::reset_cache();
t( 'ψεύτικο Applebot (λάθος rDNS): 403 και αποκλεισμός, όπως πριν', 403 === $code && isset( PNCP_Log::active_bans()['45.1.2.9'] ), (string) $code );
fresh();
settings( array( 'ban_strikes' => 3, 'ban_minutes' => 60 ) );

/* ---------- Άλλες μέθοδοι ---------- */
list( $code ) = req( $url, array( 'X-Test-IP' => '198.51.100.11', 'User-Agent' => 'GPTBot/1.2' ), 'POST' );
t( 'POST σελίδας από GPTBot: 403 (στην 1.4 περνούσε)', 403 === $code, (string) $code );
list( $code ) = req( $url . '?wc-ajax=1', array( 'X-Test-IP' => '198.51.100.12', 'User-Agent' => 'GPTBot/1.2' ) );
t( '?wc-ajax χωρίς WooCommerce δεν παρακάμπτει τον έλεγχο', 403 === $code, (string) $code );
list( $code, , $body ) = req( '/wp-json/wp/v2/comments', array( 'X-Test-IP' => '198.51.100.13', 'User-Agent' => 'Go-http-client/1.1' ), 'POST' );
t( 'REST POST (π.χ. webhook πληρωμής με Go-http-client): δεν μπλοκάρεται από το plugin', false === strpos( $body, 'pncp_' ), $code . ' ' . substr( $body, 0, 100 ) );
settings( array( 'rate_limit' => 1, 'ban_strikes' => 0 ) );
$codes = array();
for ( $i = 0; $i < 3; $i++ ) {
	$codes[] = req( $url, browser( '203.0.113.25', array( 'Sec-Purpose' => 'prefetch' ) ) )[0];
}
t( 'prefetch δεν μετρά στο όριο σελίδων', array( 200, 200, 200 ) === $codes, implode( ',', $codes ) );
settings( array( 'rate_limit' => 0, 'ban_strikes' => 3 ) );

/* ---------- 2.1.1: συνδεδεμένοι χρήστες, page builders, wc-ajax ---------- */
fresh();
function test_user( $login, $role ) {
	$u = get_user_by( 'login', $login );
	return $u ? $u->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role ) );
}
function login_cookie( $user_id ) {
	$exp = time() + HOUR_IN_SECONDS;
	$tok = WP_Session_Tokens::get_instance( $user_id )->create( $exp );
	return LOGGED_IN_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( $user_id, $exp, 'logged_in', $tok ) );
}
$customer = test_user( 'pncp_customer', 'subscriber' );
$editor   = test_user( 'pncp_editor', 'editor' );
list( $code ) = req( $url, array( 'X-Test-IP' => '198.51.100.70', 'User-Agent' => 'GPTBot/1.2', 'Cookie' => login_cookie( $customer ) ) );
t( 'πελάτης με λογαριασμό και User-Agent bot: 403 (πριν περνούσε κάθε συνδεδεμένος)', 403 === $code, (string) $code );
list( $code ) = req( $url, array( 'X-Test-IP' => '198.51.100.71', 'User-Agent' => 'GPTBot/1.2', 'Cookie' => login_cookie( $editor ) ) );
t( 'συντάκτης (ρόλος που εξαιρείται): περνά', 200 === $code, (string) $code );
settings( array( 'rate_limit' => 2, 'ban_strikes' => 0 ) );
$codes = array();
for ( $i = 0; $i < 3; $i++ ) {
	$codes[] = req( $url, browser( '203.0.113.72', array( 'Cookie' => login_cookie( $customer ) ) ) )[0];
}
t( 'πελάτης με λογαριασμό: μετρά στο όριο σελίδων', array( 200, 200, 429 ) === $codes, implode( ',', $codes ) );
// Με πραγματικό WooCommerce: η δηλωμένη ενέργεια get_refreshed_fragments· αλλιώς η test_ok του harness.
$wc_action = class_exists( 'WooCommerce' ) ? 'get_refreshed_fragments' : 'test_ok';
$codes = array();
for ( $i = 0; $i < 3; $i++ ) {
	$codes[] = req( $url . '?wc-ajax=' . $wc_action, browser( '203.0.113.73', array( 'X-Test-WC' => '1' ) ) )[0];
}
t( 'δηλωμένο wc-ajax του WooCommerce: δεν μετρά στο όριο', ! in_array( 429, $codes, true ), implode( ',', $codes ) );
$codes = array();
for ( $i = 0; $i < 3; $i++ ) {
	$codes[] = req( $url . '?wc-ajax=bogus', browser( '203.0.113.74', array( 'X-Test-WC' => '1' ) ) )[0];
}
t( 'άγνωστο wc-ajax: μετρά όπως κάθε σελίδα', 429 === end( $codes ), implode( ',', $codes ) );
list( $code ) = req( $url . '?wc-ajax=' . $wc_action, array( 'X-Test-IP' => '198.51.100.75', 'User-Agent' => 'GPTBot/1.2', 'X-Test-WC' => '1' ) );
t( 'δηλωμένο wc-ajax από bot: 403', 403 === $code, (string) $code );
settings( array( 'rate_limit' => 0, 'ban_strikes' => 3 ) );
list( , , $body ) = req( $url . '?elementor-preview=1', browser( '203.0.113.76' ) );
t( '?elementor-preview=1 από επισκέπτη: η προστασία φορτώνεται κανονικά', false !== strpos( $body, 'assets/js/protect.js' ) );
list( , , $body ) = req( $url . '?fl_builder', browser( '203.0.113.77', array( 'Cookie' => login_cookie( $customer ) ) ) );
t( 'builder flag από πελάτη χωρίς δικαίωμα επεξεργασίας: η προστασία φορτώνεται', false !== strpos( $body, 'assets/js/protect.js' ) );
settings( array( 'exempt_cap' => 'none' ) );
list( , , $body ) = req( $url, browser( '203.0.113.78', array( 'Cookie' => login_cookie( $editor ) ) ) );
$base_ok = false !== strpos( $body, 'assets/js/protect.js' );
list( , , $body ) = req( $url . '?elementor-preview=1', browser( '203.0.113.78', array( 'Cookie' => login_cookie( $editor ) ) ) );
t( '?elementor-preview=1 από συντάκτη: χωρίς την προστασία (λειτουργεί ο builder)', $base_ok && false === strpos( $body, 'assets/js/protect.js' ) );
settings( array( 'exempt_cap' => 'edit_posts' ) );

/* ---------- REST χωρίς κείμενο ---------- */
list( $code, , $body ) = req( '/wp-json/wp/v2/posts/' . $post->ID, browser( '203.0.113.24' ) );
$json = json_decode( $body, true );
t( 'REST: content.rendered κενό για επισκέπτη', 200 === $code && isset( $json['content']['rendered'] ) && '' === $json['content']['rendered'], substr( $body, 0, 120 ) );
list( $code ) = req( '/wp-json/wp/v2/posts', array( 'X-Test-IP' => '198.51.100.9', 'User-Agent' => 'python-requests/2.32' ) );
t( 'REST: python-requests 403 (στην 1.4 περνούσε)', 403 === $code, (string) $code );

/* ---------- Παγίδα ---------- */
fresh();
$trap = wp_parse_url( home_url( PNCP_Bots::trap_path() ), PHP_URL_PATH );
list( $code ) = req( $trap, browser( '203.0.113.30', array( 'Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'image' ) ) );
wp_cache_flush(); PNCP_Log::reset_cache();
t( 'παγίδα από <img> ξένου site: 403 χωρίς αποκλεισμό', 403 === $code && ! PNCP_Log::is_banned( '203.0.113.30' ) );
list( $code ) = req( $trap, browser( '203.0.113.30', array( 'Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'document' ) ) );
t( 'παγίδα από σύνδεσμο ξένου site: χωρίς αποκλεισμό', ! PNCP_Log::is_banned( '203.0.113.30' ) );
list( $code ) = req( $trap, browser( '203.0.113.30', array( 'Sec-Purpose' => 'prefetch', 'Sec-Fetch-Site' => 'same-origin' ) ) );
t( 'παγίδα από prefetch: χωρίς αποκλεισμό', ! PNCP_Log::is_banned( '203.0.113.30' ) );
list( $code ) = req( $trap, browser( '203.0.113.31', array( 'Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'document' ) ) );
wp_cache_flush(); PNCP_Log::reset_cache(); // Οι αποκλεισμοί είναι option με autoload: το CLI κρατά ό,τι διάβασε.
t( 'παγίδα από headless browser στο site: αποκλεισμός', PNCP_Log::is_banned( '203.0.113.31' ) );
list( $code ) = req( $trap, array( 'X-Test-IP' => '203.0.113.32', 'User-Agent' => 'Mozilla/5.0 Chrome' ) );
wp_cache_flush(); PNCP_Log::reset_cache();
t( 'παγίδα από «browser» χωρίς Sec-Fetch (παλιό Safari;): όχι αποκλεισμός την πρώτη φορά', ! PNCP_Log::is_banned( '203.0.113.32' ) );
list( $code ) = req( $trap, array( 'X-Test-IP' => '203.0.113.32', 'User-Agent' => 'Mozilla/5.0 Chrome' ) );
list( $code2 ) = req( $url, browser( '203.0.113.32' ) );
wp_cache_flush(); PNCP_Log::reset_cache();
t( '… αποκλεισμός τη δεύτερη φορά και 403 μετά', PNCP_Log::is_banned( '203.0.113.32' ) && 403 === $code2, (string) $code2 );
req( $trap, array( 'X-Test-IP' => '203.0.113.34', 'User-Agent' => 'python-requests/2.32' ) );
wp_cache_flush(); PNCP_Log::reset_cache();
t( 'παγίδα από script με μη-browser User-Agent: αποκλεισμός αμέσως', PNCP_Log::is_banned( '203.0.113.34' ) );
req( $trap, browser( '203.0.113.35', array( 'Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'document', 'Sec-Fetch-User' => '?1' ) ) );
wp_cache_flush(); PNCP_Log::reset_cache();
t( 'παγίδα από κλικ ανθρώπου (σύνδεσμος σε σχόλιο): όχι αποκλεισμός', ! PNCP_Log::is_banned( '203.0.113.35' ) );
req( $trap, array( 'X-Test-IP' => '66.249.64.9', 'User-Agent' => 'python-requests/2.32' ) );
wp_cache_flush(); PNCP_Log::reset_cache();
t( 'παγίδα με πλαστή IP της Google (άλλο User-Agent): όχι αποκλεισμός', ! PNCP_Log::is_banned( '66.249.64.9' ) );
PNCP_Log::ban( '66.249.64.10', 60, 'trap' );
t( 'IP της Google δεν αποκλείεται ποτέ', ! PNCP_Log::is_banned( '66.249.64.10' ) );
req( $trap, array( 'X-Test-IP' => '203.0.113.36', 'User-Agent' => 'Mozilla/5.0 (compatible; DuckDuckBot/1.1)' ) );
req( $trap, array( 'X-Test-IP' => '203.0.113.36', 'User-Agent' => 'Mozilla/5.0 (compatible; DuckDuckBot/1.1)' ) );
wp_cache_flush(); PNCP_Log::reset_cache();
t( 'παγίδα από DuckDuckBot: όχι αποκλεισμός', ! PNCP_Log::is_banned( '203.0.113.36' ) );
list( $code ) = req( $trap, array( 'X-Test-IP' => '66.249.64.7', 'User-Agent' => $gb ) );
t( 'παγίδα από πραγματικό Googlebot: χωρίς αποκλεισμό', ! PNCP_Log::is_banned( '66.249.64.7' ) );
PNCP_Log::unban_all();
t( '«Άρση όλων»', ! PNCP_Log::is_banned( '203.0.113.31' ) && ! PNCP_Log::is_banned( '203.0.113.32' ) && array() === PNCP_Log::active_bans() );

/* ---------- robots.txt και TDMRep ---------- */
list( $code, , $body ) = req( '/robots.txt', browser( '203.0.113.33' ) );
t( 'robots.txt: Disallow της παγίδας', false !== strpos( $body, 'Disallow: ' . $trap ) );
t( 'robots.txt: GPTBot και Google-Extended', false !== strpos( $body, 'User-agent: GPTBot' ) && false !== strpos( $body, 'User-agent: Google-Extended' ) );
t( 'robots.txt: όχι Googlebot', false === stripos( $body, 'User-agent: Googlebot' ) );
t( 'robots.txt: OAI-SearchBot δηλώνεται (AI αναζήτηση μπλοκαρισμένη από προεπιλογή)', false !== strpos( $body, 'User-agent: OAI-SearchBot' ) );
$oai = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.3; +https://openai.com/searchbot';
list( $code ) = req( $url, array( 'X-Test-IP' => '198.51.100.81', 'User-Agent' => $oai ) );
t( 'OAI-SearchBot (προεπιλογή): 403', 403 === $code, (string) $code );
settings( array( 'allow_ai_search' => 1 ) );
list( $code, , $body ) = req( '/robots.txt', browser( '203.0.113.33' ) );
t( 'AI αναζήτηση ενεργή: robots.txt χωρίς OAI-SearchBot / PerplexityBot, με GPTBot', false === strpos( $body, 'User-agent: OAI-SearchBot' ) && false === strpos( $body, 'User-agent: PerplexityBot' ) && false !== strpos( $body, 'User-agent: GPTBot' ) );
list( $code ) = req( $url, array( 'X-Test-IP' => '198.51.100.82', 'User-Agent' => $oai ) );
t( 'AI αναζήτηση ενεργή: OAI-SearchBot 200', 200 === $code, (string) $code );
list( $code ) = req( $url, array( 'X-Test-IP' => '198.51.100.83', 'User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)' ) );
t( 'AI αναζήτηση ενεργή: GPTBot (εκπαίδευση) ακόμη 403', 403 === $code, (string) $code );
settings( array( 'allow_ai_search' => 0 ) );
list( $code, $hdr, $body ) = req( '/.well-known/tdmrep.json', browser( '203.0.113.33' ) );
$json = json_decode( $body, true );
t( 'tdmrep.json', 200 === $code && isset( $json[0]['tdm-reservation'] ) && 1 === $json[0]['tdm-reservation'], $body );

/* ---------- Προστατευμένα feeds ---------- */
fresh();
list( $code ) = req( '/skroutz.xml', browser( '203.0.113.40' ) );
t( 'feed χωρίς token: 403', 403 === $code, (string) $code );
$java = array( 'X-Test-IP' => '203.0.113.41', 'User-Agent' => 'Java/1.8.0_401' );
list( $code ) = req( '/skroutz.xml?cp_token=Legacy1234567890Token', $java );
t( 'feed με το token της 1.4 στο URL, ακόμη και με User-Agent «Java/» της λίστας: όχι 403', 403 !== $code, (string) $code );
$new = PNCP_Private_Urls::rotate( 7 );
list( $code ) = req( '/skroutz.xml', array_merge( $java, array( 'X-ScrapeLock-Token' => $new ) ) );
t( 'νέο token στο header X-ScrapeLock-Token', 403 !== $code, (string) $code );
list( $code ) = req( '/skroutz.xml', array_merge( $java, array( 'X-Content-Protect-Token' => $new ) ) );
t( 'header με το παλιό όνομα X-Content-Protect-Token: ισχύει ακόμη', 403 !== $code, (string) $code );
list( $code ) = req( '/skroutz.xml', array_merge( $java, array( 'Authorization' => 'Bearer ' . $new ) ) );
t( 'νέο token στο Authorization: Bearer', 403 !== $code, (string) $code );
list( $code ) = req( '/skroutz.xml?cp_token=Legacy1234567890Token', $java );
t( 'παλιό token μέσα στην περίοδο χάριτος', 403 !== $code, (string) $code );
PNCP_Private_Urls::revoke_previous();
list( $code ) = req( '/skroutz.xml?cp_token=Legacy1234567890Token', $java );
t( 'παλιό token μετά την ακύρωση: 403', 403 === $code, (string) $code );
list( $code ) = req( '/skroutz.xml?cp_token=' . substr( $new, 0, 20 ), $java );
t( 'μισό token: 403', 403 === $code, (string) $code );
$opt = get_option( PNCP_Private_Urls::TOKEN_OPTION );
t( 'στη βάση δεν υπάρχει το token σε καθαρή μορφή', false === strpos( serialize( $opt ), $new ) && false === strpos( serialize( PNCP_Options::stored() ), $new ) );
$stats = PNCP_Private_Urls::stats()['/skroutz.xml'];
t( 'στατιστικά feed: επιτυχίες και αρνήσεις (οι αρνήσεις γράφονται το πολύ μία φορά το λεπτό)', $stats['ok'] >= 4 && $stats['denied'] >= 1 && in_array( $stats['last_via'], array( 'url', 'header' ), true ), wp_json_encode( $stats ) );
list( $code, $hdr ) = req( '/skroutz.xml?cp_token=' . $new, $java );
t( 'feed: Cache-Control private, no-store', false !== strpos( $hdr, 'no-store' ) );
t( 'feed: Referrer-Policy no-referrer και nosniff', false !== strpos( $hdr, 'referrer-policy: no-referrer' ) && false !== strpos( $hdr, 'x-content-type-options: nosniff' ) );

/* ---------- Emergency Mode ---------- */
fresh();
PNCP_Options::set_emergency( 1 );
list( $code ) = req( $url, array( 'X-Test-IP' => '203.0.113.50', 'User-Agent' => 'Mozilla/5.0 Chrome/141' ) );
t( 'emergency: χωρίς Accept-Language 403', 403 === $code, (string) $code );
list( $code ) = req( $url, browser( '203.0.113.51' ) );
t( 'emergency: browser 200', 200 === $code, (string) $code );
list( $code ) = req( $url, array( 'X-Test-IP' => '203.0.113.52', 'User-Agent' => 'WhatsApp/2.23' ) );
t( 'emergency: preview του WhatsApp περνά', 200 === $code, (string) $code );
t( 'emergency: οι αποθηκευμένες ρυθμίσεις δεν άλλαξαν', 0 === (int) PNCP_Options::stored()['require_browser_headers'] );
PNCP_Options::set_emergency( 0 );
list( $code ) = req( $url, array( 'X-Test-IP' => '203.0.113.53', 'User-Agent' => 'Mozilla/5.0 Chrome/141' ) );
t( 'μετά το emergency: χωρίς Accept-Language ξανά 200', 200 === $code, (string) $code );

/* ---------- Trusted proxy (μέσα από HTTP: X-Test-IP σβήνεται, ελέγχεται το πραγματικό header) ---------- */
remove_all_filters( 'pncp_client_ip' );
$_SERVER['REMOTE_ADDR']           = '8.8.8.8';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
settings( array( 'ip_header' => 'cf_connecting_ip', 'trust_cloudflare' => 1 ) );
PNCP_Ip::reset();
t( 'CF-Connecting-IP από μη-Cloudflare IP: αγνοείται', '8.8.8.8' === PNCP_Ip::client_ip(), PNCP_Ip::client_ip() );
$_SERVER['REMOTE_ADDR'] = '173.245.48.10';
PNCP_Ip::reset();
t( 'CF-Connecting-IP από IP του Cloudflare: γίνεται δεκτό', '1.2.3.4' === PNCP_Ip::client_ip(), PNCP_Ip::client_ip() );
settings( array( 'trust_cloudflare' => 0 ) );
PNCP_Ip::reset();
t( 'Cloudflare όχι αξιόπιστο: αγνοείται', '173.245.48.10' === PNCP_Ip::client_ip() );
$_SERVER['REMOTE_ADDR']    = '173.245.48.10';
$_SERVER['HTTP_X_REAL_IP'] = '1.2.3.4';
settings( array( 'ip_header' => 'x_real_ip', 'trust_cloudflare' => 1 ) );
PNCP_Ip::reset();
t( 'X-Real-IP μέσω Cloudflare (το περνά όπως το έστειλε ο επισκέπτης): αγνοείται', '173.245.48.10' === PNCP_Ip::client_ip(), PNCP_Ip::client_ip() );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
PNCP_Ip::reset();
t( 'X-Real-IP από Nginx στον ίδιο server: γίνεται δεκτό', '1.2.3.4' === PNCP_Ip::client_ip(), PNCP_Ip::client_ip() );
settings( array( 'ip_header' => 'cf_connecting_ip' ) );
$_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
PNCP_Ip::reset();
t( 'CF-Connecting-IP από ιδιωτική IP (όχι Cloudflare): αγνοείται', '127.0.0.1' === PNCP_Ip::client_ip(), PNCP_Ip::client_ip() );
unset( $_SERVER['HTTP_X_REAL_IP'] );
settings( array( 'ip_header' => 'x_forwarded_for', 'trusted_proxies' => "10.0.0.0/8\n52.1.1.0/24" ) );
$_SERVER['REMOTE_ADDR']          = '52.1.1.7';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 9.9.9.9, 10.0.0.5';
PNCP_Ip::reset();
t( 'X-Forwarded-For: παραλείπει αξιόπιστους proxies από το τέλος', '9.9.9.9' === PNCP_Ip::client_ip(), PNCP_Ip::client_ip() );
$_SERVER['REMOTE_ADDR'] = '5.5.5.5';
PNCP_Ip::reset();
t( 'X-Forwarded-For από άγνωστο proxy: αγνοείται', '5.5.5.5' === PNCP_Ip::client_ip() );
settings( array( 'trust_any_proxy' => 1, 'allow_ips' => '10.0.0.5' ) );
PNCP_Ip::reset();
t( 'trust_any_proxy (1.4): τελευταία τιμή', '10.0.0.5' === PNCP_Ip::client_ip(), PNCP_Ip::client_ip() );
t( 'trust_any_proxy: η IP θεωρείται αναξιόπιστη (δεν ανοίγει επιτρεπόμενες IP / feeds)', ! PNCP_Ip::reliable() );
settings( array( 'allow_ips' => '' ) );
settings( array( 'trust_any_proxy' => 0, 'ip_header' => 'remote_addr', 'trusted_proxies' => '' ) );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
PNCP_Ip::reset();

/* ---------- Απενεργοποίηση: ποτέ με ένα κλικ ---------- */
settings( array( 'enabled' => 1 ) );
fresh();
list( $code ) = req( $url, array( 'X-Test-IP' => '198.51.100.60', 'User-Agent' => 'GPTBot' ) );
settings( array( 'enabled' => 0 ) );
list( $code2 ) = req( $url, array( 'X-Test-IP' => '198.51.100.60', 'User-Agent' => 'GPTBot' ) );
t( 'ανενεργό plugin: τίποτα δεν μπλοκάρεται', 403 === $code && 200 === $code2, "$code/$code2" );
settings( array( 'enabled' => 1 ) );

echo $fails ? "\n$fails FAIL\n" : "\nΟΛΑ PASS\n";
exit( $fails ? 1 : 0 );
