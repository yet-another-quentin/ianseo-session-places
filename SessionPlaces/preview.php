<?php
/*
 * Session places — preview of the image for the admin page (open competition, same rights as the page), shown
 * even when the public image is off. Same drawing and cache as image.php.
 */
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox");
if (!CheckTourSession() or !hasFullACL(AclParticipants, 'pAdvancedEntries', AclReadWrite)
	or !function_exists('imagecreatetruecolor') or !($data = sp_data($_SESSION['TourId']))) {
	http_response_code(404);
	exit;
}
$png = sp_png($data);
header('Cache-Control: private, no-store');
header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
echo $png;
