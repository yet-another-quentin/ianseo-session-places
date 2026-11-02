<?php
/*
 * Session places — PHP helpers shared by the admin page, the public image and their APIs.
 * The module only reads IANSEO's tables (and SelfRegistration's, when installed); it writes to ModulesParameters only.
 * Every function takes the competition id explicitly: the public image has no IANSEO competition session.
 */

require_once(__DIR__ . '/core.php');

const SP_MODULE = 'SessionPlaces';
const SP_SELFREG = 'SelfRegistration';

// URL of a module asset with a cache buster: version + file time (the version stays 0.0.0-dev in development).
function sp_asset($file) {
	return $file . '?v=' . rawurlencode(sp_version_info()['version'] . '-' . intval(@filemtime(__DIR__ . '/' . $file)));
}

function sp_version_info() {
	$v = @json_decode(@file_get_contents(__DIR__ . '/version.json'), true);
	return is_array($v) ? $v : array('version' => '0.0.0', 'repo' => '', 'asset' => SP_MODULE . '.zip');
}

// ---------- language ----------

// 'fr' or 'en', following IANSEO's language (admin pages only: the image has its own language setting).
function sp_lang() {
	return preg_match('/^fr/i', (string)SelectLanguage()) ? 'fr' : 'en';
}

// Server-side messages (admin API, menu, updates). The admin page has its own dictionary (i18n.js).
function sp_t($key, $params = array()) {
	static $dict = array(
		'en' => array(
			'menu' => 'Places left',
			'noTournament' => 'No competition open',
			'forbidden' => 'Insufficient rights',
			'adminOnly' => 'Administrator only',
			'unknownAction' => 'Unknown action',
			'invalid' => 'Invalid data',
			'channelInvalid' => 'Invalid channel',
			'repoMissing' => 'Repository not configured',
			'connection' => 'connection failed',
			'connectionFopen' => 'connection failed (curl missing, allow_url_fopen or SSL?)',
			'githubUnexpected' => 'unexpected GitHub answer',
			'noRelease' => 'no release published yet',
			'noUpdate' => 'No new version',
			'manual' => ' — Manual update: download {asset} and unzip it into Modules/Custom/.',
			'zipMissing' => 'PHP "zip" extension missing',
			'notWritable' => 'Module folder not writable by the web server',
			'downloadFailed' => 'Download failed: {error}',
			'zipUnreadable' => 'Unreadable archive',
			'zipRefused' => 'Archive refused: unexpected path "{name}"',
			'zipIncomplete' => 'Incomplete archive',
			'versionInvalid' => 'Invalid version.json in the archive',
			'backupFailed' => 'Backup failed',
			'copyFailed' => 'Cannot copy the new files: previous version restored',
		),
		'fr' => array(
			'menu' => 'Places restantes',
			'noTournament' => 'Aucune compétition ouverte',
			'forbidden' => 'Droits insuffisants',
			'adminOnly' => 'Réservé à l\'administrateur',
			'unknownAction' => 'Action inconnue',
			'invalid' => 'Données invalides',
			'channelInvalid' => 'Canal invalide',
			'repoMissing' => 'Dépôt non configuré',
			'connection' => 'connexion impossible',
			'connectionFopen' => 'connexion impossible (curl absent, allow_url_fopen ou SSL ?)',
			'githubUnexpected' => 'réponse GitHub inattendue',
			'noRelease' => 'aucune version publiée pour le moment',
			'noUpdate' => 'Aucune nouvelle version',
			'manual' => ' — Mise à jour manuelle : télécharger {asset} et décompresser son contenu dans Modules/Custom/.',
			'zipMissing' => 'Extension PHP « zip » absente',
			'notWritable' => 'Dossier du module non modifiable par le serveur web',
			'downloadFailed' => 'Téléchargement impossible : {error}',
			'zipUnreadable' => 'Archive illisible',
			'zipRefused' => 'Archive refusée : chemin inattendu « {name} »',
			'zipIncomplete' => 'Archive incomplète',
			'versionInvalid' => 'version.json invalide dans l\'archive',
			'backupFailed' => 'Sauvegarde impossible',
			'copyFailed' => 'Copie des nouveaux fichiers impossible : ancienne version restaurée',
		),
	);
	$s = $dict[sp_lang()][$key] ?? $dict['en'][$key] ?? $key;
	foreach ($params as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
	return $s;
}

// ---------- parameters ----------
// Stored like setModuleParameter() does (serialized), but the value is always a JSON string, so that no PHP object
// is ever unserialized. Direct SQL: setModuleParameter() replaces an empty TourId with the open competition, and
// the public image has no open competition. TourId 0 = installation-wide.

function sp_param_get($TourId, $param, $default = null, $module = SP_MODULE) {
	$q = safe_r_sql("select MpValue from ModulesParameters where MpModule=" . StrSafe_DB($module)
		. " and MpParameter=" . StrSafe_DB($param) . " and MpTournament=" . intval($TourId));
	if ($r = safe_fetch($q)) {
		$v = @unserialize($r->MpValue, array('allowed_classes' => false));
		$d = json_decode(is_string($v) ? $v : $r->MpValue, true);
		return $d === null ? $default : $d;
	}
	return $default;
}

function sp_param_set($TourId, $param, $value) {
	$v = StrSafe_DB(serialize(json_encode($value, JSON_UNESCAPED_UNICODE)));
	safe_w_sql("insert into ModulesParameters set MpModule=" . StrSafe_DB(SP_MODULE)
		. ", MpParameter=" . StrSafe_DB($param) . ", MpTournament=" . intval($TourId) . ", MpValue=$v"
		. " on duplicate key update MpValue=$v");
}

function sp_settings($TourId, $sessions = null, $labels = null) {
	$TourId = intval($TourId);
	if ($sessions === null) $sessions = sp_sessions($TourId);
	if ($labels === null) $labels = sp_distance_labels(sp_distance_groups_of($TourId));
	return sp_clean_settings(sp_param_get($TourId, 'settings', array()), $sessions, $labels, $TourId ? sp_tour_lang($TourId) : 'fr');
}

// Default language of the image: the competition's print language when it is French or English.
function sp_tour_lang($TourId) {
	$r = safe_fetch(safe_r_sql("select ToPrintLang from Tournament where ToId=" . intval($TourId)));
	return ($r and preg_match('/^en/i', (string)$r->ToPrintLang)) ? 'en' : 'fr';
}

// IANSEO's tour_delete() does not know the module parameters: rows of deleted competitions are removed here.
function sp_purge_orphans() {
	safe_w_sql("delete from ModulesParameters where MpModule=" . StrSafe_DB(SP_MODULE)
		. " and MpTournament>0 and MpTournament not in (select ToId from Tournament)");
}

// ---------- competition ----------

// Id of a competition from a code already checked by sp_valid_code(). The query runs for an empty code too, so that
// a refused code costs the same as an unknown one.
function sp_tour_id($code) {
	$r = safe_fetch(safe_r_sql("select ToId from Tournament where ToCode=" . StrSafe_DB((string)$code) . " and ToCode<>''"));
	return $r ? intval($r->ToId) : 0;
}

// Competition row, without the image blobs (only the size of the left logo, which is part of the fingerprint).
function sp_tour($TourId) {
	return safe_fetch(safe_r_sql("select ToId, ToCode, ToName, ToWhere, ToWhenFrom, ToWhenTo, ToType, ToNumDist,
		length(ToImgL) as ImgL from Tournament where ToId=" . intval($TourId)));
}

function sp_sessions($TourId) {
	$out = array();
	$q = safe_r_sql("select SesOrder, SesName, SesTar4Session, SesAth4Target, SesDtStart from Session
		where SesTournament=" . intval($TourId) . " and SesType='Q' order by SesOrder");
	while ($r = safe_fetch($q)) {
		$out[] = array(
			'order' => intval($r->SesOrder),
			'name' => (string)$r->SesName,
			'start' => ($r->SesDtStart and substr($r->SesDtStart, 0, 4) != '0000') ? (string)$r->SesDtStart : '',
			'targets' => intval($r->SesTar4Session),
			'perTarget' => intval($r->SesAth4Target),
			'capacity' => intval($r->SesTar4Session) * intval($r->SesAth4Target),
		);
	}
	return $out;
}

function sp_division_names($TourId) {
	$out = array();
	$q = safe_r_sql("select DivId, DivDescription from Divisions where DivTournament=" . intval($TourId) . " and DivAthlete='1' order by DivViewOrder");
	while ($r = safe_fetch($q)) $out[(string)$r->DivId] = (string)$r->DivDescription;
	return $out;
}

// Distance groups of the competition (Competition › Distances), in IANSEO's table order.
function sp_distance_groups_of($TourId, $tour = null) {
	$tour = $tour ?: sp_tour($TourId);
	if (!$tour) return array();
	$n = max(1, min(8, intval($tour->ToNumDist)));
	$rows = array();
	$q = safe_r_sql("select * from TournamentDistances where TdTournament=" . intval($tour->ToId)
		. " and TdType=" . intval($tour->ToType) . " order by TdClasses");
	while ($r = safe_fetch($q)) {
		$names = $metres = array();
		for ($i = 1; $i <= $n; $i++) {
			$names[] = (string)$r->{'Td' . $i};
			$metres[] = intval($r->{'TdDist' . $i} ?? 0);
		}
		$rows[] = array('pattern' => (string)$r->TdClasses, 'names' => $names, 'metres' => $metres);
	}
	return sp_distance_groups($rows);
}

// ---------- places ----------

// SelfRegistration's table exists (module installed and used at least once).
function sp_selfreg_table() {
	static $exists = null;
	if ($exists === null) $exists = (bool)safe_num_rows(safe_r_sql("show tables like 'SelfRegRequests'"));
	return $exists;
}

function sp_selfreg_installed() {
	return is_file(dirname(__DIR__) . '/' . SP_SELFREG . '/register.php');
}

/**
 * Taken places per session, division and class: entries (EnAthlete=1, any status, as IANSEO's CheckSession.php)
 * and the pending requests of SelfRegistration, which hold their places. Validated requests count through the
 * entries they created. Sorted, so that the fingerprint only changes when a number changes.
 */
function sp_usage($TourId) {
	$TourId = intval($TourId);
	$rows = array();
	$add = function ($ses, $div, $class, $n) use (&$rows) {
		$k = intval($ses) . "\t" . $div . "\t" . $class;
		if (!isset($rows[$k])) $rows[$k] = array('ses' => intval($ses), 'div' => (string)$div, 'class' => (string)$class, 'n' => 0);
		$rows[$k]['n'] += intval($n);
	};
	$q = safe_r_sql("select QuSession, EnDivision, EnClass, count(*) as n from Entries inner join Qualifications on QuId=EnId
		where EnTournament=$TourId and EnAthlete=1 and QuSession>0 group by QuSession, EnDivision, EnClass");
	while ($r = safe_fetch($q)) $add($r->QuSession, $r->EnDivision, $r->EnClass, $r->n);
	if (sp_selfreg_table()) {
		$q = safe_r_sql("select SrSession, SrDivision, SrClass, count(*) as n from SelfRegRequests
			where SrTournament=$TourId and SrStatus=0 group by SrSession, SrDivision, SrClass");
		while ($r = safe_fetch($q)) $add($r->SrSession, $r->SrDivision, $r->SrClass, $r->n);
	}
	ksort($rows, SORT_STRING);
	return array_values($rows);
}

// Quotas per session and bow type set in SelfRegistration (read only): [session => [div => max]].
function sp_selfreg_quotas($TourId) {
	$s = sp_param_get($TourId, 'settings', array(), SP_SELFREG);
	$quotas = array();
	foreach ((array)($s['quotas'] ?? array()) as $ses => $divs) {
		foreach ((array)$divs as $div => $max) {
			if (intval($max) > 0) $quotas[intval($ses)][(string)$div] = intval($max);
		}
	}
	ksort($quotas);
	return $quotas;
}

/**
 * Everything the image is drawn from, the places computed from it, and its fingerprint (hash).
 * Any change of an input — an entry, a request, a setting, the competition's name or logo, the module version —
 * changes the hash, hence the cached file and the ETag.
 */
function sp_data($TourId, $format = 'full') {
	$format = sp_format($format);
	$tour = sp_tour($TourId);
	if (!$tour) return null;
	$sessions = sp_sessions($TourId);
	$groups = sp_distance_groups_of($TourId, $tour);
	$settings = sp_settings($TourId, $sessions, sp_distance_labels($groups));
	$usage = sp_usage($TourId);
	$quotas = sp_selfreg_quotas($TourId);
	$divNames = sp_division_names($TourId);
	$places = sp_places($sessions, $usage, array(
		'quotas' => $quotas, 'divNames' => $divNames, 'groups' => $groups, 'targets' => $settings['targets'],
		'hidden' => $settings['hidden'], 'divisions' => $settings['divisions'],
	));
	$head = array('name' => (string)$tour->ToName, 'where' => (string)$tour->ToWhere, 'from' => (string)$tour->ToWhenFrom,
		'to' => (string)$tour->ToWhenTo, 'logo' => intval($tour->ImgL));
	$hash = sp_fingerprint(array(sp_version_info()['version'], intval(@filemtime(__DIR__ . '/render.php')),
		$format, $head, $settings['lang'], $places));
	return array('tourId' => intval($tour->ToId), 'format' => $format, 'code' => (string)$tour->ToCode, 'head' => $head,
		'lang' => $settings['lang'], 'settings' => $settings, 'sessions' => $sessions, 'places' => $places,
		'labels' => sp_distance_labels($groups), 'quotas' => $quotas, 'divNames' => $divNames, 'hash' => $hash);
}

// Left logo of the competition as a GD image (decoded from IANSEO's blob), or null.
function sp_tour_logo($TourId) {
	if (!function_exists('imagecreatefromstring')) return null;
	$r = safe_fetch(safe_r_sql("select ToImgL as img from Tournament where ToId=" . intval($TourId)));
	if (!$r or !strlen((string)$r->img) or strlen($r->img) > 20000000) return null;
	$im = @imagecreatefromstring($r->img);
	return $im === false ? null : $im;
}

// ---------- image cache ----------

/**
 * Cache folder, outside the web folders, private to this installation and to the web server's user. On a shared
 * temporary folder, a folder created by someone else (or a link) is refused: the image is then drawn every time.
 */
function sp_cache_dir() {
	$dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ianseo-sessionplaces-' . substr(sha1(__DIR__), 0, 12);
	if (!is_dir($dir) and !@mkdir($dir, 0700) and !is_dir($dir)) return null;
	if (is_link($dir) or !is_writable($dir)) return null;
	if (function_exists('posix_geteuid') and @fileowner($dir) !== posix_geteuid()) return null;
	return $dir;
}

/**
 * PNG of the data (sp_data()): read from the cache when its fingerprint is known, drawn otherwise. One drawing at a
 * time per competition (flock); the file is written aside then renamed, and the previous image of the competition
 * removed, so the cache holds at most one file per competition and format.
 */
function sp_png($data) {
	require_once(__DIR__ . '/render.php');
	$dir = sp_cache_dir();
	if (!$dir) return sp_render($data);
	$id = intval($data['tourId']);
	$hash = preg_replace('/[^0-9a-f]/', '', $data['hash']);
	$prefix = $dir . DIRECTORY_SEPARATOR . $id . '-' . sp_format($data['format']) . '-';
	$file = $prefix . $hash . '.png';
	if (is_file($file) and ($png = @file_get_contents($file)) !== false and $png !== '') return $png;

	$lock = @fopen($dir . DIRECTORY_SEPARATOR . $id . '.lock', 'c');
	if ($lock) flock($lock, LOCK_EX);
	try {
		// drawn by another request while this one was waiting
		if (is_file($file) and ($png = @file_get_contents($file)) !== false and $png !== '') return $png;
		$png = sp_render($data);
		$tmp = @tempnam($dir, 'sp');
		if ($tmp and @file_put_contents($tmp, $png) === strlen($png) and @rename($tmp, $file)) {
			@chmod($file, 0600);
			foreach (glob($prefix . '*.png') ?: array() as $old) {
				if ($old !== $file) @unlink($old);
			}
		} elseif ($tmp) {
			@unlink($tmp);
		}
		return $png;
	} finally {
		if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
	}
}

// ---------- admin page context ----------

function sp_channel() {
	// "nightly" is the former name of the latest channel
	return in_array(sp_param_get(0, 'channel', 'stable'), array('latest', 'nightly'), true) ? 'latest' : 'stable';
}

// Absolute URL of a file of this module, from the current request (admin pages are in the module folder).
function sp_module_url($file) {
	return getMyScheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . $file;
}

function sp_context($TourId) {
	$data = sp_data($TourId);
	$version = sp_version_info();
	$isAdmin = hasFullACL(AclRoot, '', AclReadWrite);
	// the admin table lists every session, hidden ones too
	$all = sp_places($data['sessions'], sp_usage($TourId), array('groups' => sp_distance_groups_of($TourId)));
	return array(
		'lang' => sp_lang(),
		'tour' => array('code' => $data['code'], 'name' => $data['head']['name']),
		'sessions' => $data['sessions'],
		'used' => array_column($all, 'used', 'order') ?: new stdClass(),
		'labels' => $data['labels'],
		'quotas' => $data['quotas'] ?: new stdClass(),
		'divNames' => $data['divNames'] ?: new stdClass(),
		'settings' => array('targets' => $data['settings']['targets'] ?: new stdClass()) + $data['settings'],
		'hash' => $data['hash'],
		'selfReg' => sp_selfreg_installed(),
		'publicUrl' => sp_module_url('image.php?c=' . rawurlencode($data['code'])),
		'gd' => function_exists('imagecreatetruecolor'),
		'isAdmin' => $isAdmin,
		'channel' => sp_channel(),
		'version' => $version['version'],
	);
}

// ---------- updates from GitHub releases (same mechanism as ianseo-entry-import) ----------

function sp_http($url, $accept = '*/*', $timeout = 60) {
	$ua = 'IANSEO-SessionPlaces';
	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
			CURLOPT_CONNECTTIMEOUT => min(10, $timeout), CURLOPT_TIMEOUT => $timeout,
			CURLOPT_HTTPHEADER => array('User-Agent: ' . $ua, 'Accept: ' . $accept),
		));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($body === false) return array(0, '', $err ?: sp_t('connection'));
		return array($code, $body, $code >= 400 ? "HTTP $code" : '');
	}
	$http = array('header' => "User-Agent: $ua\r\nAccept: $accept\r\n", 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 1);
	$body = @file_get_contents($url, false, stream_context_create(array('http' => $http)));
	if ($body === false) return array(0, '', sp_t('connectionFopen'));
	$code = 0;
	if (!empty($http_response_header)) {
		foreach ($http_response_header as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = intval($m[1]);
	}
	return array($code, $body, $code >= 400 ? "HTTP $code" : '');
}

/**
 * Is $latest an update for $current on this channel?
 * Stable: strictly newer version. Latest (build of main): any different build, unless the installed version is a newer stable
 * (builds of main are "X.Y.(Z+1)-latest.STAMP.SHA", formerly "-nightly.", which version_compare() puts before "X.Y.(Z+1)").
 */
function sp_is_update($channel, $latest, $current) {
	if ($latest === '' or $latest === $current) return false;
	if ($channel === 'latest') return preg_match('/-(latest|nightly)\./', $current) or version_compare($latest, $current, '>');
	return version_compare($latest, $current, '>');
}

function sp_update_check($force = false) {
	$info = sp_version_info();
	$channel = sp_channel();
	$cache = sp_param_get(0, 'updateCheck', array());
	if (!$force and !empty($cache['at']) and time() - $cache['at'] < 86400
		and ($cache['current'] ?? '') === $info['version'] and ($cache['channel'] ?? '') === $channel) {
		return $cache;
	}
	$res = array('at' => time(), 'channel' => $channel, 'current' => $info['version'], 'latest' => '', 'notes' => '', 'asset' => '', 'url' => '', 'error' => '');
	if (empty($info['repo'])) {
		$res['error'] = sp_t('repoMissing');
	} else {
		// `api` (optional in version.json) allows testing against a fake release server
		$api = rtrim($info['api'] ?? 'https://api.github.com', '/');
		$path = $channel === 'latest' ? '/releases/tags/latest' : '/releases/latest';
		list($code, $body, $err) = sp_http($api . '/repos/' . $info['repo'] . $path, 'application/vnd.github+json');
		$j = $err ? null : json_decode($body, true);
		if ($code == 404) {
			$res['error'] = sp_t('noRelease');
		} elseif (!$j or empty($j['tag_name'])) {
			$res['error'] = $err ?: sp_t('githubUnexpected');
		} else {
			$res['notes'] = (string)($j['body'] ?? '');
			$res['url'] = (string)($j['html_url'] ?? '');
			foreach ((array)($j['assets'] ?? array()) as $a) {
				if (($a['name'] ?? '') === $info['asset']) $res['asset'] = $a['browser_download_url'];
			}
			// the latest tag is fixed ("latest"): its version is in the release name
			$res['latest'] = $channel === 'latest' ? trim((string)($j['name'] ?? '')) : ltrim($j['tag_name'], 'vV');
		}
	}
	$res['available'] = ($res['asset'] !== '' and sp_is_update($channel, $res['latest'], $info['version']));
	sp_param_set(0, 'updateCheck', $res);
	return $res;
}

function sp_rrmdir($dir) {
	if (!is_dir($dir)) return;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
		$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
	}
	@rmdir($dir);
}

function sp_rcopy($src, $dst) {
	if (!is_dir($dst) and !@mkdir($dst, 0775, true)) return false;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	foreach ($it as $f) {
		$target = $dst . DIRECTORY_SEPARATOR . $it->getSubPathName();
		if ($f->isDir()) {
			if (!is_dir($target) and !@mkdir($target, 0775, true)) return false;
		} elseif (!@copy($f->getPathname(), $target)) {
			return false;
		}
	}
	return true;
}

/**
 * Installs the release found by sp_update_check(). The current version is backed up in the server's temporary
 * folder — not in Modules/Custom/, where IANSEO would load its menu.php — and restored if any step fails.
 * Files are copied rather than renamed (Windows/XAMPP may lock them).
 */
function sp_update_install() {
	$check = sp_update_check(true);
	if (!$check['available']) return array('error' => $check['error'] ?: sp_t('noUpdate'));
	$manual = sp_t('manual', array('asset' => $check['asset']));
	if (!class_exists('ZipArchive')) return array('error' => sp_t('zipMissing') . $manual);

	$dir = __DIR__;
	if (!is_writable($dir)) return array('error' => sp_t('notWritable') . $manual);

	list($code, $body, $err) = sp_http($check['asset'], 'application/octet-stream');
	if ($err or !$body) return array('error' => sp_t('downloadFailed', array('error' => $err)) . $manual);

	$tmp = tempnam(sys_get_temp_dir(), 'sp');
	$extracted = $tmp . '-new';
	$backup = $tmp . '-backup';
	file_put_contents($tmp, $body);
	$cleanup = function () use ($tmp, $extracted, $backup) { @unlink($tmp); sp_rrmdir($extracted); sp_rrmdir($backup); };

	$zip = new ZipArchive();
	if ($zip->open($tmp) !== true) { $cleanup(); return array('error' => sp_t('zipUnreadable') . $manual); }
	$hasVersion = false;
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$name = str_replace('\\', '/', $zip->getNameIndex($i));
		if (strpos($name, '..') !== false or substr($name, 0, strlen(SP_MODULE) + 1) !== SP_MODULE . '/') {
			$zip->close(); $cleanup();
			return array('error' => sp_t('zipRefused', array('name' => $name)));
		}
		if ($name === SP_MODULE . '/version.json') $hasVersion = true;
	}
	if (!$hasVersion or !$zip->extractTo($extracted)) { $zip->close(); $cleanup(); return array('error' => sp_t('zipIncomplete') . $manual); }
	$zip->close();

	$new = @json_decode(@file_get_contents($extracted . '/' . SP_MODULE . '/version.json'), true);
	if (empty($new['version'])) { $cleanup(); return array('error' => sp_t('versionInvalid')); }

	if (!sp_rcopy($dir, $backup)) { $cleanup(); return array('error' => sp_t('backupFailed') . $manual); }
	if (!sp_rcopy($extracted . '/' . SP_MODULE, $dir)) {
		sp_rcopy($backup, $dir);
		$cleanup();
		return array('error' => sp_t('copyFailed') . $manual);
	}
	$cleanup();
	if (function_exists('opcache_reset')) @opcache_reset();
	sp_param_set(0, 'updateCheck', array());
	return array('ok' => true, 'version' => $new['version']);
}
