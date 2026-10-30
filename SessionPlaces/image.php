<?php
/*
 * Session places — PUBLIC image of the places left per session: image.php?c=<ToCode>
 *
 * The only public file of the module. Served only for competitions whose organiser switched the image on.
 * - The query is checked BEFORE IANSEO starts: exactly one parameter "c", a short code of letters and digits.
 *   IANSEO's own GET hooks (language, debug…) are never reached, and the code is only used in one SQL query.
 * - Malformed code, unknown competition, image switched off: the same empty 404, after the same queries.
 * - No session cookie, no CORS header, nothing of the request echoed back.
 * - The PNG is drawn only when its data changed (fingerprint, sp_png()); a client holding it gets a 304.
 */

// No PHP session for this file: IANSEO's Globals.inc.php starts one, which would send a cookie and write a file
// on every view. The handler keeps nothing.
final class SpNoSession implements SessionHandlerInterface {
	public function open($path, $name): bool { return true; }
	public function close(): bool { return true; }
	public function read($id): string { return ''; }
	public function write($id, $data): bool { return true; }
	public function destroy($id): bool { return true; }
	#[\ReturnTypeWillChange]
	public function gc($max) { return 0; }
}

function sp_public_end($status, $headers = array()) {
	http_response_code($status);
	foreach ($headers as $h) header($h);
	header('Content-Length: 0');
	exit;
}

require_once(__DIR__ . '/core.php');

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' and $method !== 'HEAD') sp_public_end(405, array('Allow: GET, HEAD'));
if (!sp_valid_query($_SERVER['QUERY_STRING'] ?? '', $_GET)) sp_public_end(400);
$code = sp_valid_code($_GET['c']);

session_set_save_handler(new SpNoSession(), false);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.cache_limiter', '');
$SKIP_AUTH = true;
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');
// IANSEO's Globals.inc.php sends no-cache headers
foreach (array('Expires', 'Last-Modified', 'Pragma', 'Set-Cookie') as $h) header_remove($h);
header('Cache-Control: no-store');

// a code refused by sp_valid_code() runs the same query as an unknown one ('' never matches)
$TourId = sp_tour_id($code);
$settings = sp_settings($TourId);
if (!$TourId or !$settings['public'] or !function_exists('imagecreatetruecolor')) sp_public_end(404);

$data = sp_data($TourId);
if (!$data) sp_public_end(404);
$etag = sp_etag($data['hash']);
// short freshness, then a revalidation that costs a 304 while nothing changes
$cache = array('ETag: ' . $etag, 'Cache-Control: public, max-age=60, must-revalidate', 'Cross-Origin-Resource-Policy: cross-origin', 'Vary: Accept-Encoding');
if (sp_etag_matches($_SERVER['HTTP_IF_NONE_MATCH'] ?? '', $etag)) sp_public_end(304, $cache);

$png = sp_png($data);
foreach ($cache as $h) header($h);
header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
if ($method === 'GET') echo $png;
