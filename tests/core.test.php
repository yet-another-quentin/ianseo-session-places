<?php
// Pure logic tests, without IANSEO or database: php tests/core.test.php
require __DIR__ . '/../SessionPlaces/core.php';

$failures = 0;
$count = 0;
function check($name, $actual, $expected) {
	global $failures, $count;
	$count++;
	if ($actual !== $expected) {
		$failures++;
		echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
	}
}

// ---------- public parameter ----------
check('code ok', sp_valid_code('TEST26'), 'TEST26');
check('code with _ - .', sp_valid_code('A_b-1.2'), 'A_b-1.2');
check('code too long (ToCode is 8)', sp_valid_code('ABCDEFGHI'), '');
check('code empty', sp_valid_code(''), '');
check('code starting with a dot', sp_valid_code('.htacc'), '');
check('code path traversal', sp_valid_code('../x'), '');
check('code slash', sp_valid_code('a/b'), '');
check('code NUL byte', sp_valid_code("AB\0CD"), '');
check('code trailing newline', sp_valid_code("TEST26\n"), '');
check('code quote', sp_valid_code("T'1"), '');
check('code percent', sp_valid_code('T%27'), '');
check('code unicode', sp_valid_code('TÉST'), '');
check('code space', sp_valid_code('TE ST'), '');
check('code array', sp_valid_code(array('TEST26')), '');
check('code integer', sp_valid_code(26), '');

check('query ok', sp_valid_query('c=TEST26', array('c' => 'TEST26')), true);
check('query other parameter', sp_valid_query('c=TEST26&v=2', array('c' => 'TEST26', 'v' => '2')), false);
check('query duplicate (PHP keeps the last)', sp_valid_query('c=AAA&c=TEST26', array('c' => 'TEST26')), false);
check('query array', sp_valid_query('c[]=TEST26', array('c' => array('TEST26'))), false);
check('query encoded', sp_valid_query('c=TEST%32%36', array('c' => 'TEST26')), false);
check('query IANSEO hook', sp_valid_query('c=TEST26&SetLanguage=it', array('c' => 'TEST26', 'SetLanguage' => 'it')), false);
check('query empty', sp_valid_query('', array()), false);
check('query missing c', sp_valid_query('d=TEST26', array('d' => 'TEST26')), false);
check('query too long', sp_valid_query('c=ABCDEFGHI', array('c' => 'ABCDEFGHI')), false);
check('query not a string', sp_valid_query(null, array('c' => 'TEST26')), false);
check('query compact', sp_valid_query('c=TEST26&f=compact', array('c' => 'TEST26', 'f' => 'compact')), true);
check('query other format', sp_valid_query('c=TEST26&f=full', array('c' => 'TEST26', 'f' => 'full')), false);
check('query format first', sp_valid_query('f=compact&c=TEST26', array('f' => 'compact', 'c' => 'TEST26')), false);
check('query format empty', sp_valid_query('c=TEST26&f=', array('c' => 'TEST26', 'f' => '')), false);
check('query format array', sp_valid_query('c=TEST26&f[]=compact', array('c' => 'TEST26', 'f' => array('compact'))), false);
check('query format duplicate', sp_valid_query('c=TEST26&f=compact&f=compact', array('c' => 'TEST26', 'f' => 'compact')), false);
check('query format then other', sp_valid_query('c=TEST26&f=compact&x=1', array('c' => 'TEST26', 'f' => 'compact', 'x' => '1')), false);
check('query format mismatch with $_GET', sp_valid_query('c=TEST26&f=compact', array('c' => 'TEST26', 'f' => 'x')), false);
check('format compact', sp_format('compact'), 'compact');
check('format default', sp_format(null), 'full');
check('format other', sp_format(array('compact')), 'full');

// ---------- texts ----------
check('clean control chars', sp_clean_text("Tir\x00 en\tsalle\r\n"), 'Tir en salle');
check('clean bidi override', sp_clean_text("abc\u{202E}def"), 'abc def');
check('clean length', sp_clean_text(str_repeat('a', 20), 10), 'aaaaaaaaa…');
check('clean invalid UTF-8', sp_clean_text("Cr\xE9teil"), 'Créteil');

// ---------- distances ----------
check('like exact', sp_like('CLS1H', 'CLS1H'), true);
check('like percent', sp_like('CLS1H', 'CL%'), true);
check('like underscore', sp_like('COS2F', 'CO__F'), true);
check('like case-insensitive', sp_like('cls1h', 'CL%'), true);
check('like no match', sp_like('COS1H', 'CL%'), false);
check('like regex chars are literal', sp_like('C.S', 'C.S'), true);
check('like regex chars are not wildcards', sp_like('CXS', 'C.S'), false);
check('like escaped percent', sp_like('A%', 'A\\%'), true);
check('like escaped percent literal', sp_like('AB', 'A\\%'), false);

check('label one distance', sp_distance_label(array('70m', '70m')), '70m');
check('label two distances', sp_distance_label(array('50m', '50m', '30m', '30m')), '50m / 30m');
check('label empty', sp_distance_label(array('', '')), '');
check('label from metres', sp_distance_label(array('70m-1', '70m-2'), array(70, 70)), '70 m');
check('label from metres, two distances', sp_distance_label(array('a', 'b', 'c'), array(50, 30, 30)), '50 m / 30 m');
check('label IANSEO suffixes removed', sp_distance_label(array('70m-1', '70m-2'), array(0, 0)), '70m');

$groups = sp_distance_groups(array(
	array('pattern' => 'CL%', 'names' => array('70m', '70m')),
	array('pattern' => 'CO%', 'names' => array('50m', '50m')),
	array('pattern' => 'BB%', 'names' => array('50m', '50m')),
	array('pattern' => 'XX%', 'names' => array('', '')),
));
check('groups without distances left out', count($groups), 3);
check('labels distinct', sp_distance_labels($groups), array('70m', '50m'));
check('distance for CL', sp_distance_for('CL', 'S1H', $groups), '70m');
check('distance for BB', sp_distance_for('BB', 'S1F', $groups), '50m');
check('distance trims', sp_distance_for('CO ', ' S1H', $groups), '50m');
check('distance unknown', sp_distance_for('TL', 'S1H', $groups), null);

// ---------- settings ----------
$sessions = array(
	array('order' => 1, 'name' => 'Samedi matin', 'start' => '2026-12-12 10:00:00', 'capacity' => 4, 'perTarget' => 2, 'targets' => 2),
	array('order' => 2, 'name' => '', 'start' => '', 'capacity' => 40, 'perTarget' => 4, 'targets' => 10),
	array('order' => 3, 'name' => 'Vide', 'start' => '', 'capacity' => 0, 'perTarget' => 4, 'targets' => 0),
);
$clean = sp_clean_settings(array(
	'public' => '1', 'lang' => 'de', 'hidden' => array(3, 9, '2', array(1)),
	'targets' => array('2' => array('70m' => '6', '50m' => 2000, 'XX' => 3, '30m' => -1), '9' => array('70m' => 1), 'x' => 'y'),
), $sessions, array('70m', '50m'), 'fr');
check('settings public', $clean['public'], true);
check('settings unknown language', $clean['lang'], 'fr');
check('settings hidden: existing sessions only', $clean['hidden'], array(2, 3));
check('settings targets cleaned and capped', $clean['targets'], array(2 => array('70m' => 6, '50m' => SP_MAX_TARGETS)));
check('settings divisions on by default', $clean['divisions'], true);
check('settings garbage', sp_clean_settings('garbage', $sessions, array(), 'en'),
	array('public' => false, 'lang' => 'en', 'hidden' => array(), 'divisions' => true, 'targets' => array()));

// ---------- places ----------
$usage = array(
	array('ses' => 1, 'div' => 'CL', 'class' => 'S1H', 'n' => 3),
	array('ses' => 1, 'div' => 'CO', 'class' => 'S1F', 'n' => 2),   // over capacity (validated past the limit)
	array('ses' => 2, 'div' => 'CL', 'class' => 'S1H', 'n' => 10),
	array('ses' => 2, 'div' => 'CO', 'class' => 'S1H', 'n' => 5),
	array('ses' => 2, 'div' => 'BB', 'class' => 'S1H', 'n' => 4),
	array('ses' => 2, 'div' => 'TL', 'class' => 'S1H', 'n' => 1),   // no distance
);
$p = sp_places($sessions, $usage, array(
	'quotas' => array(2 => array('CO' => 5, 'BB' => 8)), 'divNames' => array('CO' => 'Arc à poulies', 'BB' => 'Bare Bow'),
	'groups' => $groups, 'targets' => array(2 => array('70m' => 4, '50m' => 5)), 'hidden' => array(), 'divisions' => true,
));
check('sessions without places left out', array_column($p, 'order'), array(1, 2));
check('full session never negative', array($p[0]['used'], $p[0]['free']), array(5, 0));
check('session free', array($p[1]['used'], $p[1]['free']), array(20, 20));
check('quota per bow type', $p[1]['divisions'], array(
	array('id' => 'CO', 'name' => 'Arc à poulies', 'quota' => 5, 'used' => 5, 'free' => 0),
	array('id' => 'BB', 'name' => 'Bare Bow', 'quota' => 8, 'used' => 4, 'free' => 4),
));
check('places per distance', $p[1]['distances'], array(
	array('label' => '70m', 'targets' => 4, 'capacity' => 16, 'used' => 10, 'free' => 6),
	array('label' => '50m', 'targets' => 5, 'capacity' => 20, 'used' => 9, 'free' => 11),
));
check('no distances without targets', $p[0]['distances'], array());
$p2 = sp_places($sessions, $usage, array('quotas' => array(2 => array('CO' => 5)), 'groups' => $groups, 'hidden' => array(1), 'divisions' => false));
check('hidden session', array_column($p2, 'order'), array(2));
check('bow types off', $p2[0]['divisions'], array());
check('division name falls back to the id', sp_places($sessions, array(), array('quotas' => array(2 => array('XY' => 3)), 'divisions' => true))[1]['divisions'][0]['name'], 'XY');

// ---------- fingerprint and ETag ----------
$fp = sp_fingerprint(array('v1', $p));
check('fingerprint stable', sp_fingerprint(array('v1', $p)), $fp);
check('fingerprint is hex', (bool)preg_match('/^[0-9a-f]{40}$/', $fp), true);
$changed = $p;
$changed[1]['used']++;
check('fingerprint changes with a number', sp_fingerprint(array('v1', $changed)) !== $fp, true);
check('fingerprint changes with the version', sp_fingerprint(array('v2', $p)) !== $fp, true);
$etag = sp_etag($fp);
check('etag weak', $etag, 'W/"sp-' . $fp . '"');
check('etag match', sp_etag_matches($etag, $etag), true);
check('etag match strong form', sp_etag_matches('"sp-' . $fp . '"', $etag), true);
check('etag match in a list', sp_etag_matches('"x", ' . $etag, $etag), true);
check('etag star', sp_etag_matches('*', $etag), true);
check('etag other', sp_etag_matches('W/"sp-0000"', $etag), false);
check('etag empty', sp_etag_matches('', $etag), false);
check('etag prefix only', sp_etag_matches('W/"sp-' . substr($fp, 0, 10) . '"', $etag), false);

// ---------- dates ----------
check('start fr', sp_format_start('2026-12-12 10:00:00', 'fr'), 'Samedi 12 décembre · 10:00');
check('start en', sp_format_start('2026-12-13 15:30:00', 'en'), 'Sunday 13 December · 15:30');
check('start without time', sp_format_start('2026-12-12 00:00:00', 'fr'), 'Samedi 12 décembre');
check('start unknown', sp_format_start('0000-00-00 00:00:00', 'fr'), '');
check('start empty', sp_format_start('', 'fr'), '');
check('range one day', sp_format_range('2026-12-12', '2026-12-12', 'fr'), '12 décembre 2026');
check('range same month', sp_format_range('2026-12-12', '2026-12-13', 'fr'), '12–13 décembre 2026');
check('range two months', sp_format_range('2026-11-30', '2026-12-01', 'fr'), 'du 30 novembre au 1er décembre 2026');
check('range two years en', sp_format_range('2026-12-31', '2027-01-01', 'en'), 'from 31 December 2026 to 1 January 2027');
check('range no end', sp_format_range('2026-12-12', '', 'en'), '12 December 2026');
check('range unknown', sp_format_range('0000-00-00', '0000-00-00', 'fr'), '');

check('image texts fr', sp_img_t('fr', 'taken', array('used' => 3, 'capacity' => 4)), '3 / 4 inscrits');
check('image texts fall back to English', sp_img_t('xx', 'full'), 'FULL');

// ---------- wrapping (1 unit per character) ----------
$w = function ($s) { return mb_strlen($s); };
check('wrap fits', sp_wrap('Tir en salle', 20, $w), array('Tir en salle'));
check('wrap two lines', sp_wrap('Tir en salle de Cestas', 12, $w), array('Tir en salle', 'de Cestas'));
check('wrap ellipsis', sp_wrap('un deux trois quatre cinq', 9, $w, 2), array('un deux', 'trois…'));
check('wrap long word cut', sp_wrap('Championnat', 5, $w, 3), array('Champ', 'ionna', 't'));
check('wrap one line', sp_wrap('Tir en salle', 6, $w, 1), array('Tir…'));
check('wrap empty', sp_wrap('  ', 10, $w), array());

echo $failures ? "\n$failures / $count FAILED\n" : "$count tests OK\n";
exit($failures ? 1 : 0);
