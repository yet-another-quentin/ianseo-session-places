<?php
// Menu entry in Participants, with the rights of "List load" (same as the SelfRegistration module).
// Included from both get_which_menu() and get_which_run_menu(); in the latter $acl and $ret['PART'] may not exist.
if (!empty($on) and isset($ret['PART'], $acl) and subFeatureAcl($acl, AclParticipants, 'pAdvancedEntries') == AclReadWrite) {
	require_once(__DIR__ . '/lib.php');
	$ret['PART'][] = MENU_DIVIDER;
	$ret['PART'][] = sp_t('menu') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/SessionPlaces/index.php';
}
