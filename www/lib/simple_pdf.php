<?php
declare(strict_types=1);

require_once __DIR__ . '/branding.php';

/**
 * Minimal PDF generator (no external dependencies).
 * - A4 landscape
 * - WinAnsi encoding for core fonts (renders ÄÖÜäöüß correctly when text is Windows-1252)
 * - Table layout with grid lines + 1.5 line spacing (approx.)
 *
 * This is intentionally small and self-contained.
 */

function simple_pdf_bulk_passwords(string $class, int $pinLen, string $createdAt, array $rows, ?string $qrUrl = null): string {
  $branding = branding_settings();
  $appTitle = trim((string)($branding['brand_title'] ?? 'COOL Rooms')) ?: 'COOL Rooms';
  // A4 landscape in points
  $pageW = 842.0;
  $pageH = 595.0;

  // Margins
  $mL = 36.0;
  $mR = 36.0;
  $mT = 40.0;
  $mB = 48.0;

  // Typography
  $titleSize = 16;
  $metaSize  = 11;
  $headSize  = 11;
  $rowSize   = 10;

  // Table sizing (1.5 line spacing ≈ 18pt row height for 10pt text)
  $headerH = 22.0;
  $rowH    = 18.0;

  // Optional QR code (top center). If enabled, we shift header down slightly.
  $qrObj = null;
  $qrSizePt = 0.0;
  if ($qrUrl !== null && trim($qrUrl) !== '') {
    $qrObj = simple_pdf_make_qr_image_object(trim($qrUrl));
    if ($qrObj) {
      $qrSizePt = 34.0; // QR size (pt)

  // Header layout tuning (adjustable)
  // You can tweak these values if you want more/less space in the PDF header:
  $hdrGapBelow = 10.0;     // gap between header and table (pt)
  $hdrTextSizeTitle = 12;  // title font size
  $hdrTextSizeMeta  = 10;  // meta font size
  $hdrLineGap = 12.0;      // distance between title and meta line (pt)
  $hdrPageOffsetX = 120.0; // width reserved at right for page indicator (pt)
  $hdrQrNudgeY = -6.0;     // move QR up/down (pt), negative = slightly up
  $hdrMinTextHeight = 28.0;// minimum header height needed for 2 text lines (pt)

    }
  }

  // Header layout (single line)
  $yHeaderTop = $pageH - $mT;

  // Header height: keep compact so the table stays on one page.
  $headerHpt = $qrObj ? (max($hdrMinTextHeight, $qrSizePt) + 8.0) : $hdrMinTextHeight;
  $yHeaderCenter = $yHeaderTop - ($headerHpt / 2.0);
  $yHeaderText = $yHeaderTop - 14.0; // text baseline
  $yPage = $yHeaderText;

  // Table starts below header with a small gap
  $tableTop = $yHeaderTop - $headerHpt - $hdrGapBelow;

  // Column boundaries (table width = pageW - mL - mR = 770)
  $x0 = $mL;              // left
  $x1 = $x0 + 34;         // #
  $x2 = $x1 + 170;        // Nachname
  $x3 = $x2 + 150;        // Vorname
  $x4 = $x3 + 290;        // Username
  $x5 = $pageW - $mR;     // Kennwort (rest)

  // Text padding inside cells
  $padX = 6.0;

  // Rows per page
  $firstRowTop = $tableTop - $headerH;
  $usableH = $firstRowTop - $mB;
  $rowsPerPage = (int)floor($usableH / $rowH);
  if ($rowsPerPage < 8) $rowsPerPage = 8;

  $chunks = array_chunk($rows, $rowsPerPage);
  $pageCount = max(1, count($chunks));
  if (!$chunks) $chunks = [[]];

  $globalIndex = 1;
  $pages = [];

  foreach ($chunks as $p => $chunk) {
    $ops = [];

    $addText = function(float $x, float $y, string $font, float $size, string $text) use (&$ops) {
      // Convert to Windows-1252 (bytes) and escape for PDF literal strings
      $t = simple_pdf_escape(simple_pdf_to_win1252($text));
      $ops[] = "BT /$font $size Tf $x $y Td ($t) Tj ET";
    };

    $setStroke = function(float $lw=0.6) use (&$ops) {
      $ops[] = sprintf("%.2f w", $lw);
    };

    $line = function(float $x1, float $y1, float $x2, float $y2) use (&$ops) {
      $ops[] = sprintf("%.2f %.2f m %.2f %.2f l S", $x1, $y1, $x2, $y2);
    };

    // Draw QR code (centered in header line)
    if ($qrObj) {
      $qrX = ($pageW - $qrSizePt) / 2.0;
      $qrY = ($yHeaderText + $hdrQrNudgeY) - ($qrSizePt / 2.0);
      $ops[] = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q", $qrSizePt, $qrSizePt, $qrX, $qrY);
    }

    // Header (single line)
    $headerLine = $appTitle . " – Initial-Kennwörter";
    $metaLine = "Klasse: $class · PIN-Länge: $pinLen · Erstellt: $createdAt";

    // 3-column header: left text, centered QR, right page number
    $leftX = $mL;
    $rightX = $pageW - $mR - $hdrPageOffsetX;
    $qrX = ($pageW - $qrSizePt) / 2.0;

    // Left column (2 lines)
    $addText($leftX, $yHeaderText, 'F2', $hdrTextSizeTitle, $headerLine);
    $addText($leftX, $yHeaderText - $hdrLineGap, 'F1', $hdrTextSizeMeta, $metaLine);

    // Right column (page number)
    $addText($rightX, $yPage, 'F1', 10, 'Seite ' . ($p+1) . '/' . $pageCount);
// Table grid: vertical lines
    $setStroke(0.6);
    $line($x0, $tableTop, $x5, $tableTop); // top border
    $line($x0, $tableTop - $headerH, $x5, $tableTop - $headerH); // header bottom

    // outer vertical borders for header+rows area (draw down to last line later)
    $line($x0, $tableTop, $x0, $mB);
    $line($x5, $tableTop, $x5, $mB);

    // inner vertical lines
    $line($x1, $tableTop, $x1, $mB);
    $line($x2, $tableTop, $x2, $mB);
    $line($x3, $tableTop, $x3, $mB);
    $line($x4, $tableTop, $x4, $mB);

    // Header text baseline (center-ish)
    $yHeadText = $tableTop - 16;
    $addText($x0 + $padX, $yHeadText, 'F2', $headSize, '#');
    $addText($x1 + $padX, $yHeadText, 'F2', $headSize, 'Nachname');
    $addText($x2 + $padX, $yHeadText, 'F2', $headSize, 'Vorname');
    $addText($x3 + $padX, $yHeadText, 'F2', $headSize, 'Username');
    $addText($x4 + $padX, $yHeadText, 'F2', $headSize, 'Initial-Kennwort');

    // Rows
    $rowTop = $firstRowTop;
    foreach ($chunk as $r) {
      $ln  = (string)($r['last_name'] ?? '');
      $fn  = (string)($r['first_name'] ?? '');
      $un  = (string)($r['username'] ?? '');
      $pin = (string)($r['pin'] ?? '');

      // Row bottom line
      $setStroke(0.4);
      $line($x0, $rowTop - $rowH, $x5, $rowTop - $rowH);

      // Baseline for row text
      $yRowText = $rowTop - 13;

      $addText($x0 + $padX, $yRowText, 'F1', $rowSize, (string)$globalIndex);
      $addText($x1 + $padX, $yRowText, 'F1', $rowSize, simple_pdf_trunc($ln, 28));
      $addText($x2 + $padX, $yRowText, 'F1', $rowSize, simple_pdf_trunc($fn, 24));
      $addText($x3 + $padX, $yRowText, 'F1', $rowSize, simple_pdf_trunc($un, 46));
      $addText($x4 + $padX, $yRowText, 'F2', $rowSize, $pin);

      $rowTop -= $rowH;
      $globalIndex++;
    }

    // Footer line + note
    $setStroke(0.6);
    $line($mL, 44, $pageW-$mR, 44);
    $addText($mL, 30, 'F1', 9, 'Hinweis: Schüler:innen müssen nach dem Login ihr Passwort ändern. Diese Liste vertraulich behandeln.');

    $pages[] = implode("\n", $ops) . "\n";
  }

  return simple_pdf_build_document_multi($pageW, $pageH, $pages, $qrObj);
}

function simple_pdf_trunc(string $s, int $max): string {
  if (mb_strlen($s) <= $max) return $s;
  return mb_substr($s, 0, max(0, $max-3)) . '...';
}

function simple_pdf_to_win1252(string $s): string {
  // Most of your data is UTF-8. Convert to Windows-1252 which matches WinAnsiEncoding.
  $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
  if ($out === false) {
    // fallback: replace non-printables
    $out = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? $s;
  }
  return $out;
}

function simple_pdf_escape(string $s): string {
  // PDF literal string escaping
  $s = str_replace('\\', '\\\\', $s);
  $s = str_replace('(', '\\(', $s);
  $s = str_replace(')', '\\)', $s);
  $s = str_replace(["\r", "\n"], ' ', $s);
  return $s;
}

function simple_pdf_build_document_multi(float $w, float $h, array $streams, ?array $qrObj = null): string {
  // Objects:
  // 1 Catalog -> Pages 2
  // 2 Pages
  // 3 Font Helvetica (WinAnsi)
  // 4 Font Helvetica-Bold (WinAnsi)
  // 5 Image XObject (optional)
  // 5/6.. Page + Contents pairs

  $objs = [];
  $objs[] = "<< /Type /Catalog /Pages 2 0 R >>"; // 1

  $countPages = count($streams);

  $hasImg = ($qrObj && isset($qrObj['dict'], $qrObj['stream']));
  $pageBase = $hasImg ? 6 : 5;

  $kidsIds = [];
  for ($i=0; $i<$countPages; $i++) {
    $pageId = $pageBase + ($i*2);
    $kidsIds[] = $pageId . " 0 R";
  }
  $objs[] = "<< /Type /Pages /Kids [" . implode(' ', $kidsIds) . "] /Count $countPages >>"; // 2

  // Core fonts with WinAnsi encoding (enables umlauts etc.)
  $objs[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>"; // 3
  $objs[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>"; // 4
  $hasImg = ($qrObj && isset($qrObj['dict'], $qrObj['stream']));
  if ($hasImg) {
    $objs[] = $qrObj['dict'] . "
stream
" . $qrObj['stream'] . "
endstream"; // 5
  }

  for ($i=0; $i<$countPages; $i++) {
    $pageObjId = $pageBase + ($i*2);
    $contentsObjId = $pageObjId + 1;
    $xobj = $hasImg ? " /XObject << /Im1 5 0 R >>" : "";
    $objs[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>$xobj >> /Contents $contentsObjId 0 R >>";
    $stream = $streams[$i];
    $objs[] = "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream";
  }

  $pdf = "%PDF-1.4\n";
  $offsets = [0];

  foreach ($objs as $i => $obj) {
    $offsets[] = strlen($pdf);
    $n = $i + 1;
    $pdf .= "$n 0 obj\n$obj\nendobj\n";
  }

  $xrefPos = strlen($pdf);
  $count = count($objs) + 1;
  $pdf .= "xref\n0 $count\n";
  $pdf .= "0000000000 65535 f \n";
  for ($i = 1; $i < $count; $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
  }

  $pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";
  return $pdf;
}

/**
 * Create a PDF Image XObject for a QR code that encodes the given text (URL).
 * Uses an external QR generator API to obtain a PNG, then embeds it as FlateDecode RGB.
 * If fetching or decoding fails, returns null (PDF will be generated without QR).
 */
function simple_pdf_make_qr_image_object(string $text): ?array {
  $png = simple_pdf_fetch_qr_png($text);
  if ($png === null) return null;
  if (!function_exists('imagecreatefromstring')) return null;

  $im = @imagecreatefromstring($png);
  if (!$im) return null;

  $w = imagesx($im);
  $h = imagesy($im);
  if ($w <= 0 || $h <= 0) {
    imagedestroy($im);
    return null;
  }

  // QR PNGs often contain transparency. If we read pixels directly, transparent areas
  // may appear black in the PDF. Therefore we flatten on a white background first.
  $canvas = imagecreatetruecolor($w, $h);
  if (!$canvas) {
    imagedestroy($im);
    return null;
  }
  $white = imagecolorallocate($canvas, 255, 255, 255);
  imagefilledrectangle($canvas, 0, 0, $w, $h, $white);

  // Copy with alpha blending
  imagealphablending($canvas, true);
  imagesavealpha($canvas, false);
  imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);
  imagedestroy($im);

  // Build raw RGB data
  $rgb = '';
  $isTrue = function_exists('imageistruecolor') ? imageistruecolor($canvas) : true;
  for ($y=0; $y<$h; $y++) {
    for ($x=0; $x<$w; $x++) {
      $col = imagecolorat($canvas, $x, $y);
      if ($isTrue) {
        $r = ($col >> 16) & 0xFF;
        $g = ($col >> 8) & 0xFF;
        $b = $col & 0xFF;
      } else {
        $arr = imagecolorsforindex($canvas, $col);
        $r = $arr['red'] ?? 0;
        $g = $arr['green'] ?? 0;
        $b = $arr['blue'] ?? 0;
      }
      $rgb .= chr($r) . chr($g) . chr($b);
    }
  }
  imagedestroy($canvas);

  $compressed = gzcompress($rgb, 9);
  if ($compressed === false) return null;

  $dict = "<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($compressed) . " >>";
  return [
    'dict' => $dict,
    'stream' => $compressed,
  ];
}


/** Fetch QR code as PNG bytes. */
function simple_pdf_fetch_qr_png(string $text): ?string {
  $url = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=10&data=' . rawurlencode($text);

  // Prefer cURL (more reliable than allow_url_fopen)
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT => 6,
      CURLOPT_USERAGENT => 'COOL-Raumfinder/1.0',
    ]);
    $data = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (is_string($data) && $code >= 200 && $code < 300 && strlen($data) > 100) {
      return $data;
    }
  }

  // Fallback: file_get_contents
  $ctx = stream_context_create([
    'http' => [
      'timeout' => 6,
      'follow_location' => 1,
      'user_agent' => 'COOL-Raumfinder/1.0',
    ]
  ]);
  $data = @file_get_contents($url, false, $ctx);
  if (is_string($data) && strlen($data) > 100) {
    return $data;
  }
  return null;
}
