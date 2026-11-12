<?php
/*
 * Her Provenance — launch waitlist and contact form.
 * Contact messages go to ../herprovenance-data/contact.csv
 * Saves each signup to a CSV file OUTSIDE the website folder:
 *   ../herprovenance-data/waitlist.csv   (open it from cPanel File Manager or download it)
 * No outside services.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(['error' => 'Use the form on the page.'], 405);

$host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$oh = strtolower((string)parse_url($origin, PHP_URL_HOST));
$bare = fn($h) => preg_replace('/^www\./', '', $h);
if ($origin !== '' && $bare($oh) !== $bare($host)) reply(['error' => 'Not allowed.'], 403);

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '', true);
if (!is_array($in)) $in = $_POST;

if (!empty($in['company_website'])) reply(['ok' => true]); // spam trap

$email = strtolower(trim((string)($in['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) reply(['error' => 'Please enter a valid email address.'], 422);
$isContact = (($in['kind'] ?? '') === 'contact');
$clean = fn($s, $n) => trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', mb_substr((string)$s, 0, $n)));
$name = $clean($in['name'] ?? '', 120);
$message = $clean($in['message'] ?? '', 4000);
if ($isContact && $message === '') reply(['error' => 'Please write a message.'], 422);
// keep spreadsheet apps from running anything typed into the form
$safe = fn($s) => preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
$interest = preg_replace('/[^a-z ,-]/i', '', substr((string)($in['interest'] ?? ''), 0, 80));

$dir = dirname(__DIR__) . '/herprovenance-data';
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) reply(['error' => 'The list is not available right now. Please try again later.'], 500);
if (!file_exists("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");

// simple rate limit: 8 submissions per hour per connection
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rl = "$dir/rate.json";
$hits = is_file($rl) ? (json_decode((string)file_get_contents($rl), true) ?: []) : [];
$now = time();
foreach ($hits as $k => $ts) { $hits[$k] = array_values(array_filter((array)$ts, fn($t) => $t > $now - 3600)); if (!$hits[$k]) unset($hits[$k]); }
$key = hash('sha256', $ip);
if (count($hits[$key] ?? []) >= 8) reply(['error' => 'Too many signups from this connection. Please try again later.'], 429);
$hits[$key][] = $now;
@file_put_contents($rl, json_encode($hits), LOCK_EX);

if ($isContact) {
  $cf = fopen("$dir/contact.csv", 'a');
  if (!$cf) reply(['error' => 'Messages are not available right now. Please try again later.'], 500);
  flock($cf, LOCK_EX);
  if (ftell($cf) === 0 && filesize("$dir/contact.csv") === 0) fputcsv($cf, ['received', 'name', 'email', 'message']);
  fputcsv($cf, [date('c'), $safe($name), $email, $safe($message)]);
  flock($cf, LOCK_UN); fclose($cf);
  reply(['ok' => true]);
}

$file = "$dir/waitlist.csv";
$fp = fopen($file, 'c+');
if (!$fp) reply(['error' => 'The list is not available right now. Please try again later.'], 500);
flock($fp, LOCK_EX);
$exists = false;
rewind($fp);
while (($row = fgetcsv($fp)) !== false) { if (($row[1] ?? '') === $email) { $exists = true; break; } }
if (!$exists) {
  fseek($fp, 0, SEEK_END);
  if (ftell($fp) === 0) fputcsv($fp, ['signed_up', 'email', 'interest']);
  fputcsv($fp, [date('c'), $email, $interest]);
}
flock($fp, LOCK_UN);
fclose($fp);
reply(['ok' => true]);
