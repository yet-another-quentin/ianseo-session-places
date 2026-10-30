<?php
/*
 * Session places — administration page of the open competition: public image on/off, language, sessions shown,
 * targets per distance, preview and public address.
 */
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');

CheckTourSession(true);
checkFullACL(AclParticipants, 'pAdvancedEntries', AclReadWrite);

sp_purge_orphans();

$PAGE_TITLE = sp_t('menu');
$JS_SCRIPT = array(
	'<link href="./' . sp_asset('admin.css') . '" rel="stylesheet" type="text/css">',
	'<script src="./' . sp_asset('i18n.js') . '"></script>',
	'<script src="./' . sp_asset('admin.js') . '" defer></script>',
	'<script>window.SP_CONTEXT = ' . json_encode(sp_context($_SESSION['TourId']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>',
);

include('Common/Templates/head.php');
?>
<div id="sp-admin">
	<noscript>JavaScript is required for this module.</noscript>
</div>
<?php
include('Common/Templates/tail.php');
