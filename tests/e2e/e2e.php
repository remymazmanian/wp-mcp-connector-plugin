<?php
/**
 * End-to-end tests for WP MCP Connector against a real WordPress.
 * Run through tests/e2e/run.sh, which starts the site in Docker.
 *
 * Each check states the intended (fixed) behaviour, so a FAIL against older
 * code is expected until the matching fix lands.
 *
 * Env: WPMCP_BASE (site URL), WPMCP_COMPOSE (docker compose command prefix).
 *
 * Copyright (C) 2026 Remy Mazmanian
 * GPL-2.0-or-later. See LICENSE.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit; // command-line only
}

$base    = rtrim( getenv( 'WPMCP_BASE' ) ?: 'http://localhost:8090', '/' );
$compose = getenv( 'WPMCP_COMPOSE' ) ?: 'docker compose -f ' . escapeshellarg( __DIR__ . '/compose.yml' ) . ' -p wpmcp-e2e';
$cli     = $compose . ' run --rm -T cli';
$mcp_url = "$base/wp-json/mcp/v1/mcp";
$passed  = 0;
$failed  = 0;

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

$BASE_SETTINGS = array(
	'enabled'                    => true,
	'profile'                    => 'admin', // the only profile that exposes wp_update_option
	'enabled_tools'              => array(),
	'capability'                 => 'edit_posts',
	'allow_app_passwords'        => true,
	'allow_bearer'               => true,
	'oauth_enabled'              => true,
	'oauth_dynamic_registration' => true,
	'oauth_default_scope'        => 'mcp',
	'sse_enabled'                => true,
	'sse_max_duration'           => 60,
	'rate_limit_requests'        => 100000, // the run makes far more than 120 tool calls a minute
	'rate_limit_window'          => 60,
	'max_upload_bytes'           => 8388608,
	'allowed_option_keys'        => array( 'blogname', 'blogdescription', 'posts_per_page', 'date_format', 'time_format', 'start_of_week' ),
	'allowed_media_hosts'        => array(),
	'log_enabled'                => true,
	'require_https'              => false,
);

/* ------------------------------------------------------------------ helpers */

function wp( ...$args ) {
	global $cli;
	$out = shell_exec( $cli . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' </dev/null 2>/dev/null' );
	return trim( str_replace( "\r", '', (string) $out ) );
}

function settings( $overrides = array() ) {
	global $BASE_SETTINGS;
	wp( 'option', 'update', 'wpmcp_settings', json_encode( array_merge( $BASE_SETTINGS, $overrides ) ), '--format=json' );
}

/** $o: headers (list), cookies (map), json (body to encode), form (map). */
function http( $method, $url, $o = array() ) {
	$headers = array_merge( array( 'Expect:' ), $o['headers'] ?? array() );
	$ch      = curl_init( $url );
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 120 ) );
	if ( ! empty( $o['cookies'] ) ) {
		curl_setopt( $ch, CURLOPT_COOKIE, implode( '; ', array_map( function ( $k, $v ) { return "$k=$v"; }, array_keys( $o['cookies'] ), $o['cookies'] ) ) );
	}
	if ( array_key_exists( 'json', $o ) ) {
		$headers[] = 'Content-Type: application/json';
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $o['json'], JSON_UNESCAPED_SLASHES ) );
	} elseif ( isset( $o['form'] ) ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $o['form'] ) );
	}
	curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
	$raw = curl_exec( $ch );
	if ( false === $raw ) {
		return array( 'code' => 0, 'headers' => array(), 'body' => curl_error( $ch ), 'json' => null, 'location' => '' );
	}
	$size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$h    = array();
	foreach ( preg_split( '/\r?\n/', substr( $raw, 0, $size ) ) as $line ) {
		if ( preg_match( '/^([^:\s]+):\s*(.*)$/', $line, $m ) ) {
			$h[ strtolower( $m[1] ) ][] = trim( $m[2] );
		}
	}
	$body = substr( $raw, $size );
	return array(
		'code'     => curl_getinfo( $ch, CURLINFO_HTTP_CODE ),
		'headers'  => $h,
		'body'     => $body,
		'json'     => json_decode( $body, true ),
		'location' => $h['location'][0] ?? '',
	);
}

function header_value( $r, $name ) {
	return implode( ', ', $r['headers'][ strtolower( $name ) ] ?? array() );
}

/** Auth cookies for a user, minted through WP-CLI (no password needed). */
function cookies_for( $user_id ) {
	$out = wp( 'eval', '$t = WP_Session_Tokens::get_instance(' . (int) $user_id . ')->create(time()+3600); echo AUTH_COOKIE."=".wp_generate_auth_cookie(' . (int) $user_id . ', time()+3600, "auth", $t)."\n".LOGGED_IN_COOKIE."=".wp_generate_auth_cookie(' . (int) $user_id . ', time()+3600, "logged_in", $t);' );
	$cookies = array();
	foreach ( explode( "\n", $out ) as $line ) {
		if ( false !== strpos( $line, '=' ) ) {
			list( $k, $v ) = explode( '=', $line, 2 );
			$cookies[ $k ] = $v;
		}
	}
	return $cookies;
}

/** Posts of any type and status with exactly this title. */
function posts_titled( $title ) {
	return (int) wp( 'eval', 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = %s", base64_decode( "' . base64_encode( $title ) . '" ) ) );' );
}

function check( $name, $cond, $detail = '' ) {
	global $passed, $failed;
	if ( $cond ) {
		$passed++;
		echo "PASS  $name\n";
	} else {
		$failed++;
		echo "FAIL  $name" . ( '' !== $detail ? "  ($detail)" : '' ) . "\n";
	}
}

function section( $title ) {
	echo "\n== $title\n";
}

function brief( $r ) {
	$m = $r['msg'] ?? $r['json'] ?? null;
	if ( null !== $m ) {
		$s = json_encode( $m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	} elseif ( preg_match( '#<h1[^>]*>(.*?)</h1>#is', $r['body'], $t ) ) {
		$s = 'HTML: ' . trim( strip_tags( $t[1] ) );
	} else {
		$s = 'non-JSON: ' . trim( preg_replace( '/\s+/', ' ', strip_tags( $r['body'] ) ) );
	}
	return 'HTTP ' . $r['code'] . ' ' . mb_substr( $s, 0, 240 );
}

/* ------------------------------------------------------------------ MCP client */

function mcp_client( $auth_header ) {
	return array( 'auth' => $auth_header, 'session' => '', 'proto' => null, 'id' => 0 );
}

/** The JSON-RPC reply with this id, from a JSON or an event-stream response. */
function mcp_message( $r, $id ) {
	if ( false === stripos( header_value( $r, 'content-type' ), 'text/event-stream' ) ) {
		return is_array( $r['json'] ) ? $r['json'] : null;
	}
	$found = null;
	foreach ( preg_split( "/\r?\n\r?\n/", $r['body'] ) as $event ) {
		$data = array();
		foreach ( preg_split( "/\r?\n/", $event ) as $line ) {
			if ( 0 === strpos( $line, 'data:' ) ) {
				$data[] = preg_replace( '/^ /', '', substr( $line, 5 ) );
			}
		}
		$m = $data ? json_decode( implode( "\n", $data ), true ) : null;
		if ( is_array( $m ) && array_key_exists( 'id', $m ) && $m['id'] === $id ) {
			$found = $m;
		}
	}
	return $found;
}

/** One JSON-RPC request. $o['proto'] overrides the MCP-Protocol-Version header ('' or null sends none). */
function mcp( &$c, $method, $params = null, $o = array() ) {
	global $mcp_url;
	$c['id']++;
	$msg = array( 'jsonrpc' => '2.0', 'id' => $c['id'], 'method' => $method );
	if ( null !== $params ) {
		$msg['params'] = $params;
	}
	$headers = array( 'Accept: application/json, text/event-stream' );
	if ( $c['auth'] ) {
		$headers[] = $c['auth'];
	}
	if ( $c['session'] ) {
		$headers[] = 'Mcp-Session-Id: ' . $c['session'];
	}
	$proto = array_key_exists( 'proto', $o ) ? $o['proto'] : $c['proto'];
	if ( null !== $proto && '' !== $proto ) {
		$headers[] = 'MCP-Protocol-Version: ' . $proto;
	}
	$r        = http( 'POST', $mcp_url, array( 'headers' => $headers, 'json' => $msg ) );
	$r['msg'] = mcp_message( $r, $c['id'] );
	$sid      = header_value( $r, 'mcp-session-id' );
	if ( '' !== $sid ) {
		$c['session'] = $sid;
	}
	return $r;
}

/** initialize plus notifications/initialized, as a real client does. */
function mcp_init( &$c, $version = '2025-06-18' ) {
	global $mcp_url;
	$r = mcp( $c, 'initialize', array( 'protocolVersion' => $version, 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'wpmcp-e2e', 'version' => '1.0' ) ), array( 'proto' => null ) );
	if ( isset( $r['msg']['result']['protocolVersion'] ) ) {
		$c['proto'] = $r['msg']['result']['protocolVersion'];
		$headers    = array( 'Accept: application/json, text/event-stream', $c['auth'], 'MCP-Protocol-Version: ' . $c['proto'] );
		if ( $c['session'] ) {
			$headers[] = 'Mcp-Session-Id: ' . $c['session'];
		}
		http( 'POST', $mcp_url, array( 'headers' => $headers, 'json' => array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ) );
	}
	return $r;
}

function tool( &$c, $name, $args = array() ) {
	return mcp( $c, 'tools/call', array( 'name' => $name, 'arguments' => (object) $args ) );
}

function tool_ok( $r ) {
	return 200 === $r['code'] && isset( $r['msg']['result'] ) && empty( $r['msg']['result']['isError'] );
}

/** A tool error (isError) or a JSON-RPC error. A crash or a success is not a refusal. */
function refused( $r ) {
	return isset( $r['msg']['error'] ) || ! empty( $r['msg']['result']['isError'] );
}

function tool_text( $r ) {
	return implode( "\n", array_column( $r['msg']['result']['content'] ?? array(), 'text' ) );
}

/* ------------------------------------------------------------------ OAuth client */

function b64url( $bin ) {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}

/** array( code_verifier, S256 code_challenge ) */
function pkce() {
	$verifier = b64url( random_bytes( 32 ) );
	return array( $verifier, b64url( hash( 'sha256', $verifier, true ) ) );
}

function dcr( $meta ) {
	global $base;
	return http( 'POST', "$base/mcp-oauth/register", array( 'json' => $meta ) );
}

function dcr_ok( $r ) {
	return $r['code'] >= 200 && $r['code'] < 300 && ! empty( $r['json']['client_id'] );
}

function authorize_url( $client_id, $redirect_uri, $challenge, $state, $scope = 'mcp' ) {
	global $base;
	return "$base/mcp-oauth/authorize?" . http_build_query(
		array(
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'redirect_uri'          => $redirect_uri,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'scope'                 => $scope,
		),
		'',
		'&',
		PHP_QUERY_RFC3986
	);
}

/** Named inputs of the consent page's POST form, or null when there is no such form. */
function form_fields( $html ) {
	if ( ! preg_match( '#<form\b[^>]*method="post"[^>]*>(.*?)</form>#is', $html, $f ) ) {
		return null;
	}
	$fields = array();
	preg_match_all( '#<input\b[^>]*>#i', $f[1], $inputs );
	foreach ( $inputs[0] as $tag ) {
		if ( preg_match( '#\bname="([^"]*)"#', $tag, $n ) ) {
			$fields[ html_entity_decode( $n[1], ENT_QUOTES | ENT_HTML5 ) ] = preg_match( '#\bvalue="([^"]*)"#', $tag, $v ) ? html_entity_decode( $v[1], ENT_QUOTES | ENT_HTML5 ) : '';
		}
	}
	return $fields;
}

function is_consent_page( $r ) {
	$fields = 200 === $r['code'] ? form_fields( $r['body'] ) : null;
	return ! empty( $fields['_wpnonce'] ) && false === stripos( $r['body'], 'Invalid redirect URI' );
}

/**
 * Full authorization code flow as a logged-in user: register, consent,
 * approve, exchange. Returns ok, stage, r (last response), client_id,
 * redirect_uri, tokens.
 */
function oauth_flow( $cookies, $redirect = 'http://127.0.0.1:53682/callback' ) {
	global $base;
	$reg = dcr( array( 'client_name' => 'wpmcp e2e', 'redirect_uris' => array( $redirect ), 'grant_types' => array( 'authorization_code', 'refresh_token' ), 'response_types' => array( 'code' ), 'token_endpoint_auth_method' => 'none' ) );
	if ( ! dcr_ok( $reg ) ) {
		return array( 'ok' => false, 'stage' => 'register', 'r' => $reg );
	}
	$client_id = $reg['json']['client_id'];
	list( $verifier, $challenge ) = pkce();
	$state   = 'e2e ' . bin2hex( random_bytes( 4 ) );
	$url     = authorize_url( $client_id, $redirect, $challenge, $state );
	$consent = http( 'GET', $url, array( 'cookies' => $cookies ) );
	$fields  = 200 === $consent['code'] ? form_fields( $consent['body'] ) : null;
	if ( empty( $fields['_wpnonce'] ) ) {
		return array( 'ok' => false, 'stage' => 'consent', 'r' => $consent );
	}
	unset( $fields['deny'] );
	$fields['approve'] = '1';
	$decision = http( 'POST', $url, array( 'cookies' => $cookies, 'form' => $fields ) );
	parse_str( (string) parse_url( $decision['location'], PHP_URL_QUERY ), $q );
	if ( ! in_array( $decision['code'], array( 302, 303 ), true ) || 0 !== strpos( $decision['location'], strtok( $redirect, '?' ) ) || empty( $q['code'] ) || ( $q['state'] ?? '' ) !== $state ) {
		$decision['json'] = array( 'location' => $decision['location'] );
		return array( 'ok' => false, 'stage' => 'approve', 'r' => $decision );
	}
	$tok = http( 'POST', "$base/mcp-oauth/token", array( 'form' => array( 'grant_type' => 'authorization_code', 'code' => $q['code'], 'client_id' => $client_id, 'redirect_uri' => $redirect, 'code_verifier' => $verifier ) ) );
	if ( empty( $tok['json']['access_token'] ) || empty( $tok['json']['refresh_token'] ) ) {
		return array( 'ok' => false, 'stage' => 'token', 'r' => $tok );
	}
	return array( 'ok' => true, 'stage' => 'done', 'r' => $tok, 'client_id' => $client_id, 'redirect_uri' => $redirect, 'tokens' => $tok['json'] );
}

function flow_brief( $f ) {
	return $f['ok'] ? '' : 'stopped at ' . $f['stage'] . ': ' . brief( $f['r'] );
}

function refresh( $refresh_token, $client_id ) {
	global $base;
	return http( 'POST', "$base/mcp-oauth/token", array( 'form' => array( 'grant_type' => 'refresh_token', 'refresh_token' => $refresh_token, 'client_id' => $client_id ) ) );
}

/* ------------------------------------------------------------------ container log */

function container_log_lines() {
	global $compose;
	return explode( "\n", str_replace( "\r", '', (string) shell_exec( $compose . ' logs --no-color --no-log-prefix wordpress </dev/null 2>&1' ) ) );
}

/** PHP warnings, notices, deprecations and errors that mention the plugin. */
function plugin_php_messages( array $lines ) {
	$out = array();
	foreach ( $lines as $line ) {
		if ( preg_match( '/PHP (Warning|Notice|Deprecated|Fatal error|Parse error|Recoverable fatal error)|\[php:(warn|notice|error|deprecated)/i', $line ) && false !== stripos( $line, 'wp-mcp-connector' ) ) {
			$out[] = trim( preg_replace( '/^\[[^\]]*\]\s*/', '', $line ) );
		}
	}
	return $out;
}

/* ------------------------------------------------------------------ setup */

// Test hooks. The two filters let the suite shorten timing and limits; the
// plugin is expected to apply them.
$mu = "<?php\n// wp-mcp-connector e2e test hooks.\n"
	. "add_filter( 'wpmcp_refresh_grace', fn() => (int) get_option( 'wpmcp_test_refresh_grace', 120 ) );\n"
	. "add_filter( 'wpmcp_dcr_rate_limit', fn( \$l ) => (int) get_option( 'wpmcp_test_dcr_rate_limit', \$l ) );\n";
wp( 'eval', 'wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . "/wpmcp-e2e.php", base64_decode( "' . base64_encode( $mu ) . '" ) );' );
wp( 'option', 'update', 'wpmcp_test_refresh_grace', '120' );
wp( 'option', 'update', 'wpmcp_test_dcr_rate_limit', '1000' ); // registrations used by the other tests must not trip the limit
wp( 'transient', 'delete', '--all' );
foreach ( array( 'wpmcp_oauth_clients', 'wpmcp_oauth_grants', 'wpmcp_oauth_tokens', 'wpmcp_tokens' ) as $opt ) {
	wp( 'option', 'delete', $opt );
}
wp( 'option', 'update', 'default_role', 'subscriber' );
wp( 'option', 'update', 'users_can_register', '0' );
settings();

wp( 'user', 'application-password', 'delete', 'admin', '--all' );
$app_pw = wp( 'user', 'application-password', 'create', 'admin', 'e2e', '--porcelain' );
$bearer = wp( 'eval', '$r = WPMCP_Auth::issue_token( 1, "e2e" ); echo is_wp_error( $r ) ? "" : $r["token"];' );
$admin_cookies = cookies_for( 1 );

$auth_app    = 'Authorization: Basic ' . base64_encode( "admin:$app_pw" );
$auth_bearer = 'Authorization: Bearer ' . $bearer;
echo 'setup: app password ' . ( $app_pw ? 'ok' : 'MISSING' ) . ', bearer ' . ( $bearer ? 'ok' : 'MISSING' ) . ', cookies ' . ( count( $admin_cookies ) === 2 ? 'ok' : 'MISSING' ) . "\n";

/* ------------------------------------------------------------------ 1. basics */

section( '1. Basics' );
$flow = oauth_flow( $admin_cookies );
check( 'OAuth: register, consent, approve and code exchange yield tokens', $flow['ok'], flow_brief( $flow ) );
$creds = array(
	'Application Password' => $auth_app,
	'Bearer token'         => $auth_bearer,
	'OAuth access token'   => 'Authorization: Bearer ' . ( $flow['tokens']['access_token'] ?? 'none' ),
);
foreach ( $creds as $label => $auth ) {
	$c = mcp_client( $auth );
	$r = mcp_init( $c );
	check( "$label: initialize", 200 === $r['code'] && ! empty( $r['msg']['result']['protocolVersion'] ) && '' !== $c['session'], brief( $r ) );
	$r     = mcp( $c, 'tools/list' );
	$names = array_column( $r['msg']['result']['tools'] ?? array(), 'name' );
	check( "$label: tools/list", 200 === $r['code'] && in_array( 'wp_get_site_info', $names, true ) && in_array( 'wp_get_option', $names, true ), brief( $r ) );
	$r = tool( $c, 'wp_get_site_info' );
	check( "$label: read-only tool call (wp_get_site_info)", tool_ok( $r ), brief( $r ) );
}

/* ------------------------------------------------------------------ 2. app passwords toggle */

section( '2. Application Passwords toggle' );
settings( array( 'allow_app_passwords' => false ) );
$c = mcp_client( $auth_app );
$r = mcp_init( $c );
check( 'with allow_app_passwords off, a valid Application Password gets HTTP 401', 401 === $r['code'], brief( $r ) );
$c = mcp_client( $auth_bearer );
$r = mcp_init( $c );
check( 'with allow_app_passwords off, a Bearer token still works (control)', 200 === $r['code'], brief( $r ) );
settings();

/* ------------------------------------------------------------------ 3. protocol version header */

section( '3. MCP-Protocol-Version header' );
$c = mcp_client( $auth_bearer );
mcp_init( $c );
$r = mcp( $c, 'tools/list', null, array( 'proto' => '1999-01-01' ) );
check( 'unsupported MCP-Protocol-Version (1999-01-01) gets HTTP 400', 400 === $r['code'], brief( $r ) );
foreach ( array( '2025-11-25', '2025-06-18' ) as $v ) {
	$r = mcp( $c, 'tools/list', null, array( 'proto' => $v ) );
	check( "MCP-Protocol-Version $v is accepted", 200 === $r['code'] && isset( $r['msg']['result']['tools'] ), brief( $r ) );
}
$r = mcp( $c, 'tools/list', null, array( 'proto' => '' ) );
check( 'no MCP-Protocol-Version header is accepted', 200 === $r['code'] && isset( $r['msg']['result']['tools'] ), brief( $r ) );

/* ------------------------------------------------------------------ 4. discovery */

section( '4. Discovery documents' );
$docs = array(
	'oauth-protected-resource'   => array( 'resource', 'authorization_servers' ),
	'oauth-authorization-server' => array( 'issuer', 'authorization_endpoint', 'token_endpoint' ),
);
settings( array( 'oauth_enabled' => false ) );
foreach ( $docs as $doc => $keys ) {
	$r = http( 'GET', "$base/.well-known/$doc" );
	check( "with OAuth off, /.well-known/$doc is 404", 404 === $r['code'], brief( $r ) );
}
settings();
foreach ( $docs as $doc => $keys ) {
	$r       = http( 'GET', "$base/.well-known/$doc" );
	$missing = is_array( $r['json'] ) ? array_diff( $keys, array_keys( $r['json'] ) ) : $keys;
	check( "with OAuth on, /.well-known/$doc is 200 JSON", 200 === $r['code'] && false !== stripos( header_value( $r, 'content-type' ), 'json' ) && ! $missing, brief( $r ) );
}

/* ------------------------------------------------------------------ 5. logged-out authorize */

section( '5. Logged-out authorize round trip' );
// Every reserved character here (: / ? & =) must be percent-encoded inside
// the authorize URL's query, and must survive wp-login.php's redirect_to.
$tricky = 'http://127.0.0.1:53682/oauth/callback?client=e2e&step=1';
$reg    = dcr( array( 'client_name' => 'wpmcp e2e encoded', 'redirect_uris' => array( $tricky ) ) );
list( , $challenge ) = pkce();
$url = authorize_url( $reg['json']['client_id'] ?? '', $tricky, $challenge, 'e2e state/' . bin2hex( random_bytes( 3 ) ) );
$r   = http( 'GET', $url );
check( 'logged-out authorize redirects (302) to wp-login.php', 302 === $r['code'] && 'wp-login.php' === basename( (string) parse_url( $r['location'], PHP_URL_PATH ) ), brief( $r ) . ' Location: ' . $r['location'] );
parse_str( (string) parse_url( $r['location'], PHP_URL_QUERY ), $q );
$back = $q['redirect_to'] ?? '';
check( 'redirect_to decodes to the exact authorize URL, percent-encoding intact', $back === $url, "sent $url | got $back" );
$consent = 0 === strpos( $back, $base . '/' ) ? http( 'GET', $back, array( 'cookies' => $admin_cookies ) ) : array( 'code' => 0, 'body' => '', 'json' => null, 'headers' => array() );
check( 'following redirect_to logged in renders the consent page, not "Invalid redirect URI"', is_consent_page( $consent ), brief( $consent ) );

/* ------------------------------------------------------------------ 6. consent headers */

section( '6. Consent page headers' );
$reg = dcr( array( 'client_name' => 'wpmcp e2e frame', 'redirect_uris' => array( 'http://127.0.0.1:53682/callback' ) ) );
list( , $challenge ) = pkce();
$consent = http( 'GET', authorize_url( $reg['json']['client_id'] ?? '', 'http://127.0.0.1:53682/callback', $challenge, 'frame' ), array( 'cookies' => $admin_cookies ) );
$xfo     = header_value( $consent, 'x-frame-options' );
$csp     = header_value( $consent, 'content-security-policy' );
check( 'consent page sends X-Frame-Options: DENY', is_consent_page( $consent ) && 'DENY' === strtoupper( $xfo ), "X-Frame-Options: '$xfo'; " . brief( $consent ) );
check( "consent page CSP contains frame-ancestors 'none'", is_consent_page( $consent ) && false !== stripos( $csp, "frame-ancestors 'none'" ), "Content-Security-Policy: '$csp'" );

/* ------------------------------------------------------------------ 7. dynamic client registration */

section( '7. Dynamic client registration' );
$r = dcr( array( 'client_name' => str_repeat( 'N', 5000 ), 'redirect_uris' => array( 'https://example.com/cb' ) ) );
if ( dcr_ok( $r ) ) {
	$returned = mb_strlen( (string) ( $r['json']['client_name'] ?? '' ) );
	$stored   = (int) wp( 'eval', '$c = get_option( "wpmcp_oauth_clients" ); echo mb_strlen( (string) ( $c[' . var_export( $r['json']['client_id'], true ) . ']["client_name"] ?? "" ) );' );
	check( 'a 5,000-character client_name is returned and stored at 200 characters or fewer', $returned <= 200 && $stored <= 200, "HTTP {$r['code']}, returned $returned chars, stored $stored chars" );
} else {
	check( 'a 5,000-character client_name is returned and stored at 200 characters or fewer', 400 === $r['code'], 'rejected instead: ' . brief( $r ) );
}
$uris = array();
for ( $i = 1; $i <= 11; $i++ ) {
	$uris[] = "https://example.com/cb$i";
}
$r = dcr( array( 'client_name' => 'wpmcp e2e many uris', 'redirect_uris' => $uris ) );
check( 'more than 10 redirect_uris gets 400', 400 === $r['code'], brief( $r ) );
$r = dcr( array( 'client_name' => 'wpmcp e2e long uri', 'redirect_uris' => array( 'https://example.com/cb?pad=' . str_repeat( 'a', 2100 ) ) ) );
check( 'a redirect_uri over 2,000 characters is rejected (400 / invalid_redirect_uri)', 400 === $r['code'] || 'invalid_redirect_uri' === ( $r['json']['error'] ?? '' ), brief( $r ) );

wp( 'option', 'update', 'wpmcp_test_dcr_rate_limit', '3' );
wp( 'transient', 'delete', '--all' ); // forget registrations counted earlier in the run
$codes = array();
for ( $i = 0; $i < 4; $i++ ) {
	$codes[] = dcr( array( 'client_name' => "wpmcp e2e rate $i", 'redirect_uris' => array( 'https://example.com/cb' ) ) )['code'];
}
check( 'with a limit of 3, the 4th registration from one IP gets HTTP 429', $codes[0] >= 200 && $codes[0] < 300 && $codes[1] === $codes[0] && $codes[2] === $codes[0] && 429 === $codes[3], 'codes ' . implode( ',', $codes ) );
wp( 'option', 'update', 'wpmcp_test_dcr_rate_limit', '1000' );
wp( 'transient', 'delete', '--all' );

/* ------------------------------------------------------------------ 8. refresh tokens */

section( '8. Refresh tokens' );
$f = oauth_flow( $admin_cookies );
$r = refresh( $f['tokens']['refresh_token'] ?? '', 'wpmcp-00000000deadbeef' );
check( 'refresh with a client_id that does not match gets 400 invalid_grant', $f['ok'] && 400 === $r['code'] && 'invalid_grant' === ( $r['json']['error'] ?? '' ), $f['ok'] ? brief( $r ) : flow_brief( $f ) );

wp( 'option', 'update', 'wpmcp_test_refresh_grace', '120' );
$f  = oauth_flow( $admin_cookies );
$rt = $f['tokens']['refresh_token'] ?? '';
$a  = refresh( $rt, $f['client_id'] ?? '' );
$b  = refresh( $rt, $f['client_id'] ?? '' );
check(
	'grace 120: presenting the same refresh token twice returns the same new pair',
	$f['ok'] && 200 === $a['code'] && 200 === $b['code'] && ! empty( $a['json']['access_token'] )
		&& ( $a['json']['access_token'] ?? 1 ) === ( $b['json']['access_token'] ?? 2 ) && ( $a['json']['refresh_token'] ?? 1 ) === ( $b['json']['refresh_token'] ?? 2 ),
	$f['ok'] ? 'first ' . brief( $a ) . ' | second ' . brief( $b ) : flow_brief( $f )
);

wp( 'option', 'update', 'wpmcp_test_refresh_grace', '0' );
$f  = oauth_flow( $admin_cookies );
$rt = $f['tokens']['refresh_token'] ?? '';
$a  = refresh( $rt, $f['client_id'] ?? '' );
$c  = mcp_client( 'Authorization: Bearer ' . ( $a['json']['access_token'] ?? 'none' ) );
$works_before = mcp_init( $c )['code'];
sleep( 2 ); // well outside a zero-second grace window
$b = refresh( $rt, $f['client_id'] ?? '' );
check( 'grace 0: re-presenting an already-rotated refresh token gets 400', $f['ok'] && 200 === $a['code'] && 400 === $b['code'], $f['ok'] ? 'rotation ' . brief( $a ) . ' | reuse ' . brief( $b ) : flow_brief( $f ) );
$c     = mcp_client( 'Authorization: Bearer ' . ( $a['json']['access_token'] ?? 'none' ) );
$after = mcp_init( $c );
check( 'grace 0: the reuse revokes the grant, so the rotated access token gets 401', 200 === $works_before && 401 === $after['code'], "access token before reuse: HTTP $works_before; after: " . brief( $after ) );
wp( 'option', 'update', 'wpmcp_test_refresh_grace', '120' );

/* ------------------------------------------------------------------ 9. option tools */

section( '9. Option tools with allowed_option_keys = *' );
settings( array( 'allowed_option_keys' => array( '*' ) ) );
$secret = 'sk_live_e2e_' . bin2hex( random_bytes( 8 ) );
wp( 'option', 'update', 'my_service_api_key', $secret );
$c = mcp_client( $auth_bearer );
mcp_init( $c );
$reads = array(
	'wpmcp_oauth_tokens' => 'wpmcp_oat_', // stored token keys
	'admin_email'        => 'admin@example.test',
	'siteurl'            => 'localhost:8090',
	'my_service_api_key' => $secret,
);
foreach ( $reads as $name => $needle ) {
	$r = tool( $c, 'wp_get_option', array( 'name' => $name ) );
	check( "wp_get_option refuses $name", refused( $r ) && false === strpos( $r['body'], $needle ), brief( $r ) );
}
foreach ( array( 'default_role' => 'administrator', 'users_can_register' => '1' ) as $name => $value ) {
	$before = wp( 'option', 'get', $name );
	$r      = tool( $c, 'wp_update_option', array( 'name' => $name, 'value' => $value ) );
	$after  = wp( 'option', 'get', $name );
	check( "wp_update_option refuses $name=$value and leaves it unchanged", refused( $r ) && $after === $before, "before '$before', after '$after'; " . brief( $r ) );
	wp( 'option', 'update', $name, $before );
}
$orig = wp( 'option', 'get', 'blogname' );
$r    = tool( $c, 'wp_get_option', array( 'name' => 'blogname' ) );
check( 'wp_get_option still reads blogname', tool_ok( $r ) && false !== strpos( tool_text( $r ), $orig ), brief( $r ) );
$new = 'WPMCP E2E ' . bin2hex( random_bytes( 3 ) );
$r   = tool( $c, 'wp_update_option', array( 'name' => 'blogname', 'value' => $new ) );
$now = wp( 'option', 'get', 'blogname' );
check( 'wp_update_option still writes blogname', tool_ok( $r ) && $now === $new, "blogname now '$now'; " . brief( $r ) );
wp( 'option', 'update', 'blogname', $orig );
wp( 'option', 'delete', 'my_service_api_key' );
settings();

/* ------------------------------------------------------------------ 10. post types */

section( '10. Excluded post types' );
$c     = mcp_client( $auth_bearer );
mcp_init( $c );
$title = 'E2E template probe ' . bin2hex( random_bytes( 4 ) );
$r     = tool( $c, 'wp_publish_article', array( 'title' => $title, 'content' => '<!-- wp:paragraph --><p>probe</p><!-- /wp:paragraph -->', 'post_type' => 'wp_template' ) );
$made  = posts_titled( $title );
check( 'wp_publish_article refuses post_type wp_template and creates nothing', refused( $r ) && 0 === $made, "posts created: $made; " . brief( $r ) );

/* ------------------------------------------------------------------ 11. nested arguments */

section( '11. Nested argument validation' );
// wp_publish_article.featured_image is an object whose "alt" is required.
$title = 'E2E nested probe ' . bin2hex( random_bytes( 4 ) );
$mark  = count( container_log_lines() );
$r     = tool( $c, 'wp_publish_article', array( 'title' => $title, 'content' => '<!-- wp:paragraph --><p>probe</p><!-- /wp:paragraph -->', 'featured_image' => array( 'base64' => PNG_1X1, 'filename' => 'e2e-probe' ) ) );
sleep( 1 );
$new   = plugin_php_messages( array_slice( container_log_lines(), $mark ) );
$made  = posts_titled( $title );
check( 'featured_image without its required alt is refused (invalid params or tool error), nothing created', refused( $r ) && 0 === $made, "posts created: $made; " . brief( $r ) );
check( 'that call logs no PHP warnings from the plugin', ! $new, implode( ' | ', array_unique( $new ) ) );

/* ------------------------------------------------------------------ whole-run log */

section( 'Container log' );
$all = plugin_php_messages( container_log_lines() );
check( 'no PHP warnings or notices from wp-mcp-connector in the wordpress container log', ! $all, count( $all ) . ' line(s), listed below' );
foreach ( array_count_values( $all ) as $message => $n ) {
	echo "      [{$n}x] $message\n";
}

echo "\n$passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
