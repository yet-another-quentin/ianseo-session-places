<?php
/*
 * Session places — pure logic, without database or IANSEO: public parameter check, places left per session, bow type
 * and distance, cache fingerprint and ETag, texts of the image. Tested by tests/core.test.php.
 */

const SP_LANGS = array('fr', 'en');
const SP_MAX_TARGETS = 999;

/**
 * Competition code from the public URL (image.php?c=…), or '' when it is not acceptable.
 * IANSEO's ToCode is a varchar(8); only letters, digits, "_", "-" and "." (not first) get through: nothing else
 * reaches SQL, and the code is never used in a path, a header or the image.
 */
function sp_valid_code($c) {
	if (!is_string($c) or !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,7}$/D', $c)) return '';
	return $c;
}

const SP_FORMATS = array('full', 'compact');

// The public query string must be exactly "c=<code>" or "c=<code>&f=compact", checked on the raw string: any other
// parameter or value, array, duplicate (PHP would keep the last one) or encoded character is refused.
function sp_valid_query($queryString, $get) {
	if (!is_string($queryString) or !preg_match('/^c=[A-Za-z0-9_.-]{1,8}(&f=compact)?$/D', $queryString) or !is_array($get)) return false;
	$keys = array_keys($get);
	return is_string($get['c'] ?? null) and ($keys === array('c') or ($keys === array('c', 'f') and ($get['f'] ?? null) === 'compact'));
}

// Format of the image from a checked query (or the preview's): 'compact' (no header, no logo), else 'full'.
function sp_format($f) {
	return $f === 'compact' ? 'compact' : 'full';
}

// Text drawn in the image (names entered in IANSEO): valid UTF-8, no control characters, one line, limited length.
function sp_clean_text($s, $max = 80) {
	$s = (string)$s;
	if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
	$s = preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $s);
	$s = trim(preg_replace('/\s+/u', ' ', $s));
	return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
}

// MySQL "LIKE" (case-insensitive, % and _ wildcards, \ escape), as IANSEO matches categories with TdClasses.
function sp_like($subject, $pattern) {
	$re = '';
	$len = strlen($pattern);
	for ($i = 0; $i < $len; $i++) {
		$ch = $pattern[$i];
		if ($ch === '\\' and $i + 1 < $len) $re .= preg_quote($pattern[++$i], '/');
		elseif ($ch === '%') $re .= '.*';
		elseif ($ch === '_') $re .= '.';
		else $re .= preg_quote($ch, '/');
	}
	return (bool)preg_match('/^' . $re . '$/is', (string)$subject);
}

/**
 * Label of a series of distances ("70 m", "50 m / 30 m"): distinct distances in shooting order, from the metres
 * (TdDist1…) or, when they are not set, from the names without IANSEO's "-1", "-2" suffixes ("70m-1" → "70m").
 */
function sp_distance_label($names, $metres = array()) {
	$out = array();
	foreach ($names as $i => $n) {
		$m = intval($metres[$i] ?? 0);
		$n = $m > 0 ? $m . ' m' : sp_clean_text(preg_replace('/\s*-\s*\d+$/', '', (string)$n), 12);
		if ($n !== '' and !in_array($n, $out, true)) $out[] = $n;
	}
	return implode(' / ', $out);
}

/**
 * Distance groups of a competition from its TournamentDistances rows: [{pattern, names: [Td1…TdN], metres: [TdDist1…]}].
 * Returns [{pattern, label}] in the given order (first match wins), rows without distances left out.
 */
function sp_distance_groups($rows) {
	$out = array();
	foreach ($rows as $r) {
		$label = sp_distance_label($r['names'], $r['metres'] ?? array());
		if ($label !== '') $out[] = array('pattern' => (string)$r['pattern'], 'label' => $label);
	}
	return $out;
}

function sp_distance_labels($groups) {
	return array_values(array_unique(array_column($groups, 'label')));
}

function sp_distance_for($div, $class, $groups) {
	$cat = trim($div) . trim($class);
	foreach ($groups as $g) {
		if (sp_like($cat, $g['pattern'])) return $g['label'];
	}
	return null;
}

/**
 * Settings of a competition, cleaned against what exists (also used for what the admin page sends):
 *   public:    the image is served by image.php
 *   lang:      language of the image
 *   hidden:    sessions left out of the image (new sessions are shown)
 *   divisions: show the places per bow type when the SelfRegistration module sets quotas
 *   targets:   [session => [distance label => number of targets]]
 */
function sp_clean_settings($s, $sessions, $labels, $defaultLang = 'fr') {
	$s = is_array($s) ? $s : array();
	$orders = array_map('intval', array_column($sessions, 'order'));
	$targets = array();
	foreach ((is_array($s['targets'] ?? null) ? $s['targets'] : array()) as $ses => $byLabel) {
		if (!in_array(intval($ses), $orders, true) or !is_array($byLabel)) continue;
		foreach ($byLabel as $label => $n) {
			$n = is_numeric($n) ? intval($n) : 0;
			if ($n > 0 and in_array((string)$label, $labels, true)) $targets[intval($ses)][(string)$label] = min(SP_MAX_TARGETS, $n);
		}
	}
	$hidden = array_values(array_intersect($orders, array_map('intval', array_filter((array)($s['hidden'] ?? array()), 'is_scalar'))));
	sort($hidden);
	ksort($targets);
	return array(
		'public' => !empty($s['public']),
		'lang' => in_array($s['lang'] ?? '', SP_LANGS, true) ? $s['lang'] : $defaultLang,
		'hidden' => $hidden,
		'divisions' => !isset($s['divisions']) || !empty($s['divisions']),
		'targets' => $targets,
	);
}

/**
 * Places of each session.
 * $sessions: [{order, name, start, capacity, perTarget}]
 * $usage:    [{ses, div, class, n}]  entries (EnAthlete=1) and pending SelfRegistration requests
 * $opts:     quotas [ses => [div => max]], divNames [div => name], groups (sp_distance_groups()),
 *            targets [ses => [label => n]], hidden [orders], divisions (bool)
 * Returns [{order, name, start, capacity, used, free, divisions: [{id, name, quota, used, free}],
 *           distances: [{label, targets, capacity, used, free}]}]
 */
function sp_places($sessions, $usage, $opts) {
	$used = array();
	foreach ($usage as $u) {
		$o = intval($u['ses']);
		$n = intval($u['n']);
		if (!isset($used[$o])) $used[$o] = array('total' => 0, 'div' => array(), 'dist' => array());
		$used[$o]['total'] += $n;
		$used[$o]['div'][$u['div']] = ($used[$o]['div'][$u['div']] ?? 0) + $n;
		$label = sp_distance_for($u['div'], $u['class'], $opts['groups'] ?? array());
		if ($label !== null) $used[$o]['dist'][$label] = ($used[$o]['dist'][$label] ?? 0) + $n;
	}
	$out = array();
	foreach ($sessions as $s) {
		$o = intval($s['order']);
		if ($s['capacity'] <= 0 or in_array($o, $opts['hidden'] ?? array(), true)) continue;
		$u = $used[$o] ?? array('total' => 0, 'div' => array(), 'dist' => array());
		$divs = array();
		if (!empty($opts['divisions'])) {
			foreach ($opts['quotas'][$o] ?? array() as $div => $max) {
				$n = intval($u['div'][$div] ?? 0);
				$divs[] = array('id' => (string)$div, 'name' => (string)($opts['divNames'][$div] ?? $div),
					'quota' => intval($max), 'used' => $n, 'free' => max(0, intval($max) - $n));
			}
		}
		$dists = array();
		foreach (sp_distance_labels($opts['groups'] ?? array()) as $label) {
			$t = intval($opts['targets'][$o][$label] ?? 0);
			if ($t <= 0) continue;
			$cap = $t * intval($s['perTarget']);
			$n = intval($u['dist'][$label] ?? 0);
			$dists[] = array('label' => $label, 'targets' => $t, 'capacity' => $cap, 'used' => $n, 'free' => max(0, $cap - $n));
		}
		$out[] = array(
			'order' => $o, 'name' => (string)$s['name'], 'start' => (string)$s['start'],
			'capacity' => intval($s['capacity']), 'used' => $u['total'], 'free' => max(0, intval($s['capacity']) - $u['total']),
			'divisions' => $divs, 'distances' => $dists,
		);
	}
	return $out;
}

// Fingerprint of everything the image is drawn from: same inputs, same image.
function sp_fingerprint($inputs) {
	return sha1(json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
}

function sp_etag($hash) {
	return 'W/"sp-' . $hash . '"';
}

// If-None-Match holds this ETag (weak comparison, list or "*")?
function sp_etag_matches($header, $etag) {
	$header = trim((string)$header);
	if ($header === '') return false;
	if ($header === '*') return true;
	$want = preg_replace('#^W/#', '', $etag);
	foreach (explode(',', $header) as $tag) {
		if (preg_replace('#^W/#', '', trim($tag)) === $want) return true;
	}
	return false;
}

// ---------- texts of the image ----------

function sp_img_t($lang, $key, $params = array()) {
	static $dict = array(
		'fr' => array(
			'heading' => 'PLACES RESTANTES',
			'session' => 'Départ {n}',
			'free.1' => 'place restante',
			'free.n' => 'places restantes',
			'full' => 'COMPLET',
			'taken' => '{used} / {capacity} inscrits',
			'byDivision' => 'Par arme',
			'byDistance' => 'Par distance',
			'chipFull' => 'complet',
			'none' => 'Aucun départ à afficher.',
			'updated' => 'Mis à jour le {date} à {time}',
			'range' => 'du {from} au {to}',
		),
		'en' => array(
			'heading' => 'PLACES LEFT',
			'session' => 'Session {n}',
			'free.1' => 'place left',
			'free.n' => 'places left',
			'full' => 'FULL',
			'taken' => '{used} / {capacity} registered',
			'byDivision' => 'Bow type',
			'byDistance' => 'Distance',
			'chipFull' => 'full',
			'none' => 'No session to show.',
			'updated' => 'Updated on {date} at {time}',
			'range' => 'from {from} to {to}',
		),
	);
	$s = $dict[$lang][$key] ?? $dict['en'][$key] ?? $key;
	foreach ($params as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
	return $s;
}

// "samedi 12 décembre · 10:00" / "Saturday 12 December · 10:00" from "2026-12-12 10:00:00"; '' when unknown.
function sp_format_start($dt, $lang) {
	if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/', (string)$dt, $m) or $m[1] === '0000') return '';
	$ts = mktime(12, 0, 0, intval($m[2]), intval($m[3]), intval($m[1]));
	$s = ucfirst(sp_day_name($ts, $lang)) . ' ' . intval($m[3]) . ' ' . sp_month_name(intval($m[2]), $lang);
	if (isset($m[4]) and ($m[4] . $m[5]) !== '0000') $s .= ' · ' . $m[4] . ':' . $m[5];
	return $s;
}

// Dates of the competition: "12 décembre 2026", "12–13 décembre 2026", "du 30 novembre au 1 décembre 2026".
function sp_format_range($from, $to, $lang) {
	$p = function ($d) { return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$d, $m) && $m[1] !== '0000' ? array(intval($m[1]), intval($m[2]), intval($m[3])) : null; };
	$a = $p($from);
	$b = $p($to) ?: $a;
	if (!$a) return '';
	$day = function ($d) use ($lang) { return $lang === 'fr' && $d === 1 ? '1er' : (string)$d; };
	if ($a === $b) return $day($a[2]) . ' ' . sp_month_name($a[1], $lang) . ' ' . $a[0];
	if ($a[0] === $b[0] and $a[1] === $b[1]) return $day($a[2]) . '–' . $day($b[2]) . ' ' . sp_month_name($a[1], $lang) . ' ' . $a[0];
	return sp_img_t($lang, 'range', array(
		'from' => $day($a[2]) . ' ' . sp_month_name($a[1], $lang) . ($a[0] !== $b[0] ? ' ' . $a[0] : ''),
		'to' => $day($b[2]) . ' ' . sp_month_name($b[1], $lang) . ' ' . $b[0],
	));
}

function sp_month_name($m, $lang) {
	$names = $lang === 'fr'
		? array('janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre')
		: array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
	return $names[($m - 1) % 12];
}

function sp_day_name($ts, $lang) {
	$names = $lang === 'fr'
		? array('dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi')
		: array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
	return $names[intval(date('w', $ts))];
}

/**
 * Splits a text into lines no wider than $max, measured by $width(text). A word wider than a line is cut.
 * At most $maxLines lines; the last one ends with "…" when text is left over.
 */
function sp_wrap($text, $max, $width, $maxLines = 2) {
	$lines = array();
	$line = '';
	foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
		$try = $line === '' ? $word : $line . ' ' . $word;
		if ($width($try) <= $max) { $line = $try; continue; }
		if ($line !== '') $lines[] = $line;
		while ($width($word) > $max and mb_strlen($word) > 1) {
			$cut = mb_strlen($word) - 1;
			while ($cut > 1 and $width(mb_substr($word, 0, $cut)) > $max) $cut--;
			$lines[] = mb_substr($word, 0, $cut);
			$word = mb_substr($word, $cut);
		}
		$line = $word;
	}
	if ($line !== '') $lines[] = $line;
	if (count($lines) > $maxLines) {
		$lines = array_slice($lines, 0, $maxLines);
		$last = $lines[$maxLines - 1];
		// whole words first, then characters
		while ($width($last . '…') > $max and ($sp = mb_strrpos($last, ' ')) !== false) $last = rtrim(mb_substr($last, 0, $sp));
		while ($last !== '' and $width($last . '…') > $max) $last = rtrim(mb_substr($last, 0, -1));
		$lines[$maxLines - 1] = $last . '…';
	}
	return $lines;
}
