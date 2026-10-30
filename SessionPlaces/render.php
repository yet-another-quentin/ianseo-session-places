<?php
/*
 * Session places — drawing of the PNG with GD. Only called by sp_png() (lib.php) when the image is not cached.
 * Drawn at twice the size then reduced, for smooth shapes. The layout runs twice: once without an image to know the
 * height, then for real. Fonts: IANSEO's own (Roboto, else FreeSans, in Common/tcpdf/fonts); without FreeType,
 * GD's built-in font with ASCII text.
 */

const SP_W = 1080;   // width of the image
const SP_SS = 2;     // supersampling

function sp_render($data) {
	$fonts = sp_fonts();
	$logo = $data['head']['logo'] ? sp_tour_logo($data['tourId']) : null;
	$h = sp_layout($data, null, $fonts, $logo);
	$im = imagecreatetruecolor(SP_W * SP_SS, $h * SP_SS);
	imagealphablending($im, true);
	sp_layout($data, $im, $fonts, $logo);
	if ($logo) imagedestroy($logo);
	$out = imagecreatetruecolor(SP_W, $h);
	imagecopyresampled($out, $im, 0, 0, 0, 0, SP_W, $h, SP_W * SP_SS, $h * SP_SS);
	imagedestroy($im);
	ob_start();
	imagepng($out, null, 9);
	imagedestroy($out);
	return ob_get_clean();
}

function sp_fonts() {
	if (!function_exists('imagettftext')) return null;
	$dir = dirname(__DIR__, 3) . '/Common/tcpdf/fonts/';
	foreach (array(array('Roboto-Regular.ttf', 'Roboto-Bold.ttf'), array('FreeSans.ttf', 'FreeSansBold.ttf')) as $f) {
		if (is_file($dir . $f[0]) and is_file($dir . $f[1])) return array($dir . $f[0], $dir . $f[1]);
	}
	return null;
}

/**
 * Lays out (and draws, when $im is given) the image; returns its height at scale 1.
 * Coordinates are at scale 1 and multiplied by SP_SS when drawing.
 */
function sp_layout($data, $im, $fonts, $logo) {
	$lang = $data['lang'];
	$S = SP_SS;
	$M = 56;          // outer margin
	$P = 32;          // card padding
	$W = SP_W;
	$col = array();
	foreach (array('bg' => 0xf4f2ec, 'card' => 0xffffff, 'line' => 0xdedbd3, 'text' => 0x1d1d1b, 'muted' => 0x6b6a66,
		'green' => 0x2f6f4f, 'greenBg' => 0xe1efe6, 'orange' => 0xa35f00, 'orangeBg' => 0xfbefd9, 'red' => 0xb3261e,
		'redBg' => 0xf6e1df, 'bar' => 0xe7e4dc) as $k => $rgb) {
		$col[$k] = $im ? imagecolorallocate($im, $rgb >> 16, ($rgb >> 8) & 255, $rgb & 255) : 0;
	}

	// text helpers: size in points at scale 1
	$width = function ($text, $size, $bold = false) use ($fonts, $S) {
		if (!$fonts) return strlen(sp_ascii($text)) * imagefontwidth(5) / $S * ($size / 13);
		$b = imagettfbbox($size * $S, 0, $fonts[$bold ? 1 : 0], $text);
		return (max($b[2], $b[4]) - min($b[0], $b[6])) / $S;
	};
	$text = function ($x, $baseline, $text, $size, $color, $bold = false, $align = 'left') use ($im, $fonts, $S, $width) {
		if (!$im or $text === '') return;
		if ($align === 'right') $x -= $width($text, $size, $bold);
		if ($fonts) {
			imagettftext($im, $size * $S, 0, intval($x * $S), intval($baseline * $S), $color, $fonts[$bold ? 1 : 0], $text);
		} else {
			imagestring($im, 5, intval($x * $S), intval(($baseline - 12) * $S), sp_ascii($text), $color);
		}
	};
	$box = function ($x, $y, $w, $h, $r, $color) use ($im, $S) {
		if ($im) sp_round_rect($im, $x * $S, $y * $S, $w * $S, $h * $S, $r * $S, $color);
	};

	$y = $M;
	if ($im) imagefilledrectangle($im, 0, 0, imagesx($im), imagesy($im), $col['bg']);

	// ----- header: logo, "PLACES LEFT", name of the competition, dates and town -----
	$tx = $M;
	$logoH = 0;
	if ($logo) {
		$lw = imagesx($logo);
		$lh = imagesy($logo);
		$scale = min(128 / $lw, 128 / $lh);
		$dw = max(1, intval($lw * $scale));
		$logoH = max(1, intval($lh * $scale));
		if ($im) imagecopyresampled($im, $logo, $M * $S, $y * $S, 0, 0, $dw * $S, $logoH * $S, $lw, $lh);
		$tx = $M + $dw + 28;
	}
	$tw = $W - $M - $tx;
	$ty = $y;
	$text($tx, $ty + 22, sp_img_t($lang, 'heading'), 15, $col['green'], true);
	$ty += 30;
	foreach (sp_wrap(sp_clean_text($data['head']['name'], 120), $tw, function ($s) use ($width) { return $width($s, 29, true); }, 2) as $line) {
		$text($tx, $ty + 40, $line, 29, $col['text'], true);
		$ty += 48;
	}
	$sub = array_filter(array(sp_format_range($data['head']['from'], $data['head']['to'], $lang), sp_clean_text($data['head']['where'], 60)));
	if ($sub) {
		$line = sp_wrap(implode(' · ', $sub), $tw, function ($s) use ($width) { return $width($s, 17); }, 1)[0];
		$text($tx, $ty + 30, $line, 17, $col['muted']);
		$ty += 36;
	}
	$y = max($y + $logoH, $ty) + 32;

	// ----- one card per session -----
	$cw = $W - 2 * $M;
	$ix = $M + $P;
	$iw = $cw - 2 * $P;
	if (!$data['places']) {
		$box($M, $y, $cw, 96, 16, $col['line']);
		$box($M + 1, $y + 1, $cw - 2, 94, 15, $col['card']);
		$text($ix, $y + 56, sp_img_t($lang, 'none'), 18, $col['muted']);
		$y += 96 + 20;
	}
	$noop = function () {};
	foreach ($data['places'] as $s) {
		// measured first (nothing drawn), so that the card background goes under the content
		$h = sp_card($s, $lang, $col, $width, $noop, $noop, $ix, $iw, $y + $P) - $y + $P;
		$box($M, $y, $cw, $h, 16, $col['line']);
		$box($M + 1, $y + 1, $cw - 2, $h - 2, 15, $col['card']);
		sp_card($s, $lang, $col, $width, $text, $box, $ix, $iw, $y + $P);
		$y += $h + 20;
	}

	// ----- footer -----
	$text($M, $y + 22, sp_img_t($lang, 'updated', array('date' => date('d/m/Y'), 'time' => date('H:i'))), 13, $col['muted']);
	return intval($y + 22 + $M - 16);
}

/**
 * One session: name and date on the left, places left on the right, fill bar, then the places per bow type and per
 * distance as chips. Returns the y below the content.
 */
function sp_card($s, $lang, $col, $width, $text, $box, $x, $w, $y) {
	$full = $s['free'] <= 0;
	$tone = $full ? 'red' : ($s['free'] <= max(2, intval($s['capacity'] / 10)) ? 'orange' : 'green');

	// right: big number + "places left", or FULL
	if ($full) {
		$rw = $width(sp_img_t($lang, 'full'), 28, true);
		$text($x + $w, $y + 40, sp_img_t($lang, 'full'), 28, $col['red'], true, 'right');
		$rightH = 48;
	} else {
		$label = sp_img_t($lang, $s['free'] === 1 ? 'free.1' : 'free.n');
		$rw = max($width((string)$s['free'], 44, true), $width($label, 14));
		$text($x + $w, $y + 50, (string)$s['free'], 44, $col[$tone], true, 'right');
		$text($x + $w, $y + 76, $label, 14, $col['muted'], false, 'right');
		$rightH = 82;
	}

	// left: name (or "Session N") and "Session N · day date · time"
	$lw = $w - $rw - 24;
	$name = sp_clean_text($s['name'], 60);
	$title = $name !== '' ? $name : sp_img_t($lang, 'session', array('n' => $s['order']));
	$title = sp_wrap($title, $lw, function ($t) use ($width) { return $width($t, 23, true); }, 1)[0] ?? '';
	$text($x, $y + 30, $title, 23, $col['text'], true);
	$when = array_filter(array($name !== '' ? sp_img_t($lang, 'session', array('n' => $s['order'])) : '', sp_format_start($s['start'], $lang)));
	$leftH = 36;
	if ($when) {
		$text($x, $y + 64, sp_wrap(implode(' · ', $when), $lw, function ($t) use ($width) { return $width($t, 16); }, 1)[0] ?? '', 16, $col['muted']);
		$leftH = 74;
	}
	$y += max($leftH, $rightH) + 18;

	// fill bar and "used / capacity registered"
	$ratio = $s['capacity'] > 0 ? min(1, $s['used'] / $s['capacity']) : 1;
	$box($x, $y, $w, 14, 7, $col['bar']);
	if ($ratio > 0) $box($x, $y, max(14, $w * $ratio), 14, 7, $col[$tone]);
	$y += 14;
	$text($x, $y + 28, sp_img_t($lang, 'taken', array('used' => $s['used'], 'capacity' => $s['capacity'])), 13, $col['muted']);
	$y += 36;

	// chips per bow type (SelfRegistration quotas) and per distance (targets set in the module)
	$groups = array();
	if ($s['divisions']) $groups[] = array(sp_img_t($lang, 'byDivision'), array_map(function ($d) { return array(sp_clean_text($d['name'], 30), $d['free']); }, $s['divisions']));
	if ($s['distances']) $groups[] = array(sp_img_t($lang, 'byDistance'), array_map(function ($d) { return array($d['label'], $d['free']); }, $s['distances']));
	$labelW = 0;
	foreach ($groups as $g) $labelW = max($labelW, $width($g[0], 14) + 20);
	foreach ($groups as $g) {
		$y += 14;
		$text($x, $y + 24, $g[0], 14, $col['muted']);
		$cx = $x + $labelW;
		foreach ($g[1] as $chip) {
			$t = $chip[0] . ' · ' . ($chip[1] > 0 ? $chip[1] : sp_img_t($lang, 'chipFull'));
			$cwid = $width($t, 15, true) + 28;
			if ($cx > $x + $labelW and $cx + $cwid > $x + $w) {
				$cx = $x + $labelW;
				$y += 46;
			}
			$c = $chip[1] > 0 ? 'green' : 'red';
			$box($cx, $y, $cwid, 36, 18, $col[$c . 'Bg']);
			$text($cx + 14, $y + 25, $t, 15, $col[$c], true);
			$cx += $cwid + 10;
		}
		$y += 36;
	}
	return $y;
}

// Filled rectangle with rounded corners (pixels of the drawing).
function sp_round_rect($im, $x, $y, $w, $h, $r, $color) {
	$x = intval($x); $y = intval($y); $w = intval($w); $h = intval($h);
	$r = intval(min($r, $w / 2, $h / 2));
	if ($w <= 0 or $h <= 0) return;
	if ($r <= 0) { imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, $color); return; }
	imagefilledrectangle($im, $x + $r, $y, $x + $w - 1 - $r, $y + $h - 1, $color);
	imagefilledrectangle($im, $x, $y + $r, $x + $w - 1, $y + $h - 1 - $r, $color);
	foreach (array(array($x + $r, $y + $r), array($x + $w - 1 - $r, $y + $r), array($x + $r, $y + $h - 1 - $r), array($x + $w - 1 - $r, $y + $h - 1 - $r)) as $c) {
		imagefilledellipse($im, $c[0], $c[1], 2 * $r, 2 * $r, $color);
	}
}

// Text for GD's built-in font (no FreeType): ASCII only.
function sp_ascii($s) {
	$a = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : false;
	return preg_replace('/[^\x20-\x7E]/', '?', $a === false ? $s : $a);
}
