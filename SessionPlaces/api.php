<?php
/*
 * Session places — JSON API of the administration page.
 * Same rights as the page; module updates require administrator rights.
 */
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');

if (!CheckTourSession()) JsonOut(array('error' => sp_t('noTournament')));
if (!hasFullACL(AclParticipants, 'pAdvancedEntries', AclReadWrite)) JsonOut(array('error' => sp_t('forbidden')));
// JSON body only: a cross-site form cannot send it
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' or stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) JsonOut(array('error' => sp_t('invalid')));

$TourId = intval($_SESSION['TourId']);
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) JsonOut(array('error' => sp_t('invalid')));
$action = (string)($in['action'] ?? '');

function sp_require_admin() {
	if (!hasFullACL(AclRoot, '', AclReadWrite)) JsonOut(array('error' => sp_t('adminOnly')));
}

try {
	switch ($action) {
		case 'context':
			JsonOut(sp_context($TourId));

		case 'settings':
			$sessions = sp_sessions($TourId);
			$labels = sp_distance_labels(sp_distance_groups_of($TourId));
			$s = sp_clean_settings($in['settings'] ?? array(), $sessions, $labels, sp_lang());
			sp_param_set($TourId, 'settings', array('targets' => $s['targets'] ?: new stdClass()) + $s);
			JsonOut(sp_context($TourId));

		case 'update-check':
			sp_require_admin();
			JsonOut(sp_update_check(!empty($in['force'])));

		case 'update-channel':
			sp_require_admin();
			if (!in_array($in['channel'] ?? '', array('stable', 'nightly'), true)) JsonOut(array('error' => sp_t('channelInvalid')));
			sp_param_set(0, 'channel', $in['channel']);
			JsonOut(array('ok' => true, 'channel' => $in['channel']));

		case 'update-install':
			sp_require_admin();
			JsonOut(sp_update_install());
	}
	JsonOut(array('error' => sp_t('unknownAction')));
} catch (Throwable $e) {
	JsonOut(array('error' => $e->getMessage()));
}
