<?php
/**
 * POST /careers/apply.php — one job application from the careers pages.
 *
 * The CV is emailed as an attachment to the clinic inbox with Reply-To set to
 * the applicant, so the team answers from Gmail as usual. Nothing is stored on
 * the server. Same rules as the Lavish careers form (lavish-next/src/app/api/careers/apply).
 *
 * Mail goes out over SMTP from a proudphysio.com mailbox. Its login lives in a
 * private file OUTSIDE public_html, so it is never in git or reachable on the web:
 *
 *   domains/proudphysio.com/careers-mail.php   (the folder that holds public_html)
 *
 *   <?php
 *   return [
 *     'host' => 'smtp.hostinger.com',
 *     'port' => 465,
 *     'user' => 'info@proudphysio.com',   // the mailbox
 *     'pass' => '…',                      // its password
 *     // 'to' => 'proudphysio1@gmail.com', // optional, defaults to CAREERS_TO below
 *   ];
 *
 * Without that file the form answers 503 and shows the email route instead.
 */

const CAREERS_TO = 'proudphysio1@gmail.com';
const SITE_HOST = 'proudphysio.com';
const MAX_CV_BYTES = 5242880; // 5 MB
const MIN_FILL_MS = 3000;
const PER_HOUR = 6;

/** Open roles: slug => [title, location]. Remove a role here when it is filled, along with its page. */
const ROLES = [
  'physiotherapist' => ['Physiotherapist (Male)', 'Kakkanad, Kochi'],
];

/** Choices offered on the form; anything else is refused. */
const QUALIFICATIONS = ['BPT', 'BPT + MPT', 'Other'];
const EXPERIENCE = ['Less than 1 year', '1–2 years', '2–3 years', '3–5 years', '5–10 years', 'More than 10 years'];

/** Accepted CV formats, checked by their first bytes as well as their extension. */
const CV_TYPES = [
  ['ext' => ['pdf'], 'mime' => 'application/pdf', 'magic' => "\x25\x50\x44\x46"],
  ['ext' => ['docx'], 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'magic' => "\x50\x4b\x03\x04"],
  ['ext' => ['doc'], 'mime' => 'application/msword', 'magic' => "\xd0\xcf\x11\xe0"],
  ['ext' => ['jpg', 'jpeg'], 'mime' => 'image/jpeg', 'magic' => "\xff\xd8\xff"],
  ['ext' => ['png'], 'mime' => 'image/png', 'magic' => "\x89\x50\x4e\x47"],
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function reply($status, $body)
{
  http_response_code($status);
  echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function fail($status, $error, $extra = [])
{
  reply($status, array_merge(['ok' => false, 'error' => $error], $extra));
}

/** Bots get the same answer as people, so the honeypot and timing checks reveal nothing. */
function ok()
{
  reply(200, ['ok' => true]);
}

function cut($s, $max)
{
  return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

/** One line of plain text: control characters and runs of whitespace collapse to a single space. */
function line($key, $max)
{
  $v = isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
  if (!preg_match('//u', $v)) return '';
  $v = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $v);
  $v = preg_replace('/\s+/u', ' ', $v);
  return cut(trim($v), $max);
}

/** Free text that keeps its line breaks. */
function para($key, $max)
{
  $v = isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
  if (!preg_match('//u', $v)) return '';
  $v = preg_replace('/\r\n?/', "\n", $v);
  $v = preg_replace('/[\x00-\x09\x0b-\x1f\x7f]+/u', ' ', $v);
  $v = preg_replace('/\n{3,}/', "\n\n", $v);
  return cut(trim($v), $max);
}

function esc($s)
{
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** File names keep letters (any script), digits and a few separators. */
function safe_name($s)
{
  $s = str_replace('&', 'and', $s);
  $s = preg_replace('/[^\p{L}\p{N} ().-]+/u', ' ', $s);
  return cut(trim(preg_replace('/\s+/u', ' ', $s)), 80);
}

function client_ip()
{
  $fwd = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : '';
  return $fwd !== '' ? $fwd : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
}

/**
 * Applications sent per client IP in the last hour, kept in a small file.
 * With $record, also counts this one. Returns the count before recording.
 */
function rate($ip, $record = false)
{
  $file = sys_get_temp_dir() . '/proudphysio-careers-' . md5(__DIR__) . '.json';
  $fh = @fopen($file, 'c+');
  if (!$fh) return 0;
  flock($fh, LOCK_EX);
  $data = json_decode(stream_get_contents($fh), true);
  if (!is_array($data)) $data = [];
  $now = time();
  foreach ($data as $k => $hits) {
    $data[$k] = array_values(array_filter((array) $hits, function ($t) use ($now) { return $now - $t < 3600; }));
    if (!$data[$k]) unset($data[$k]);
  }
  $key = md5($ip);
  $count = isset($data[$key]) ? count($data[$key]) : 0;
  if ($record) $data[$key][] = $now;
  ftruncate($fh, 0);
  rewind($fh);
  fwrite($fh, json_encode($data));
  flock($fh, LOCK_UN);
  fclose($fh);
  return $count;
}

function mail_config()
{
  $root = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') : '';
  if ($root === '') return null;
  $file = dirname($root) . '/careers-mail.php';
  if (!is_file($file)) return null;
  $cfg = include $file;
  if (!is_array($cfg) || empty($cfg['host']) || empty($cfg['user']) || empty($cfg['pass'])) return null;
  return $cfg;
}

/** "Name" <address> with the name as an RFC 2047 encoded word, so any script survives. */
function mailbox($name, $address)
{
  return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $address . '>';
}

function write_all($s, $data)
{
  for ($done = 0, $len = strlen($data); $done < $len; $done += $n) {
    $n = fwrite($s, substr($data, $done, 65536));
    if ($n === false || $n === 0) throw new RuntimeException('SMTP write failed');
  }
}

/** A small SMTP client: implicit TLS on 465, STARTTLS on 587, AUTH LOGIN. */
function smtp_send($cfg, $from, $to, $message)
{
  $host = $cfg['host'];
  $port = isset($cfg['port']) ? (int) $cfg['port'] : 465;
  $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
  $s = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
  if (!$s) throw new RuntimeException("connect $host:$port failed: $errstr");
  stream_set_timeout($s, 30);

  $expect = function ($codes) use ($s) {
    $resp = '';
    while (($l = fgets($s, 2048)) !== false) {
      $resp .= $l;
      if (strlen($l) < 4 || $l[3] === ' ') break;
    }
    if (!in_array((int) substr($resp, 0, 3), (array) $codes, true)) throw new RuntimeException('SMTP: ' . trim($resp));
  };
  $cmd = function ($line, $codes) use ($s, $expect) {
    write_all($s, $line . "\r\n");
    $expect($codes);
  };

  try {
    $expect(220);
    $cmd('EHLO ' . SITE_HOST, 250);
    if ($port !== 465) {
      $cmd('STARTTLS', 220);
      if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS failed');
      $cmd('EHLO ' . SITE_HOST, 250);
    }
    $cmd('AUTH LOGIN', 334);
    $cmd(base64_encode($cfg['user']), 334);
    $cmd(base64_encode($cfg['pass']), 235);
    $cmd("MAIL FROM:<$from>", 250);
    $cmd("RCPT TO:<$to>", [250, 251]);
    $cmd('DATA', 354);
    write_all($s, preg_replace('/^\./m', '..', $message) . "\r\n.\r\n");
    $expect(250);
    try { $cmd('QUIT', 221); } catch (Exception $e) { /* the message is already accepted */ }
  } finally {
    fclose($s);
  }
}

// ---------------------------------------------------------------------------

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Allow: POST');
  fail(405, 'Please send the application from the form on the careers page.');
}

$type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (stripos($type, 'multipart/form-data') !== 0) fail(415, 'Please send the application from the form on the careers page.');

// Past PHP's post_max_size the request arrives with $_POST and $_FILES empty.
$length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($length > MAX_CV_BYTES + 262144 || (!$_POST && !$_FILES && $length > 0)) {
  fail(413, 'Your CV is larger than 5 MB. Please attach a smaller file, such as a PDF.', ['field' => 'cv']);
}

// Spam checks: a hidden field people never see, and a form filled faster than a person could.
if (line('company', 200) !== '') ok();
$elapsed = isset($_POST['elapsed']) ? $_POST['elapsed'] : '';
if (!is_numeric($elapsed) || (float) $elapsed < MIN_FILL_MS) ok();

$slug = line('role', 80);
if (!array_key_exists($slug, ROLES)) fail(400, 'That position is no longer open. Please choose a role on the careers page.');
list($roleTitle, $location) = ROLES[$slug];

$name = line('name', 100);
if (strlen($name) < 2) fail(400, 'Please enter your full name.', ['field' => 'name']);

$phone = line('phone', 20);
$digits = strlen(preg_replace('/\D/', '', $phone));
if ($digits < 10 || $digits > 13) fail(400, 'Please enter a valid mobile number.', ['field' => 'phone']);

$email = line('email', 254);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(400, 'Please enter a valid email address.', ['field' => 'email']);

$qualification = line('qualification', 40);
if (!in_array($qualification, QUALIFICATIONS, true)) fail(400, 'Please choose your qualification.', ['field' => 'qualification']);

$experience = line('experience', 40);
if (!in_array($experience, EXPERIENCE, true)) fail(400, 'Please choose your experience.', ['field' => 'experience']);

$place = line('place', 100);
$message = para('message', 1500);

$cv = isset($_FILES['cv']) && is_array($_FILES['cv']) ? $_FILES['cv'] : null;
if (!$cv || is_array($cv['error']) || $cv['error'] === UPLOAD_ERR_NO_FILE || (int) $cv['size'] === 0) fail(400, 'Please attach your CV.', ['field' => 'cv']);
if ($cv['error'] === UPLOAD_ERR_INI_SIZE || $cv['error'] === UPLOAD_ERR_FORM_SIZE || $cv['size'] > MAX_CV_BYTES) {
  fail(413, 'Your CV is larger than 5 MB. Please attach a smaller file, such as a PDF.', ['field' => 'cv']);
}
if ($cv['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($cv['tmp_name'])) fail(400, 'We could not read your CV. Please attach it again.', ['field' => 'cv']);
$ext = strtolower(pathinfo((string) $cv['name'], PATHINFO_EXTENSION));
$bytes = file_get_contents($cv['tmp_name']);
$kind = null;
foreach (CV_TYPES as $t) {
  if (in_array($ext, $t['ext'], true) && strncmp($bytes, $t['magic'], strlen($t['magic'])) === 0) {
    $kind = $t;
    break;
  }
}
if (!$kind) fail(415, 'Please attach your CV as a PDF, a Word document or a JPG/PNG photo.', ['field' => 'cv']);

$ip = client_ip();
if ($ip !== '' && rate($ip) >= PER_HOUR) fail(429, 'We have received several applications from this connection. Please email your CV instead.', ['fallback' => true]);

$cfg = mail_config();
if (!$cfg) {
  error_log('[careers] careers-mail.php is missing or incomplete; online applications are off.');
  fail(503, 'Online applications are not available at the moment.', ['fallback' => true]);
}

$to = !empty($cfg['to']) ? $cfg['to'] : CAREERS_TO;
$from = !empty($cfg['from']) ? $cfg['from'] : $cfg['user'];
$subject = "$roleTitle – $location — $name";
$sentAt = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('j M Y, g:i a');
$filename = 'CV - ' . (safe_name($name) ?: 'Applicant') . ' - ' . safe_name($roleTitle) . '.' . $kind['ext'][0];
$rows = [
  ['Role', $roleTitle],
  ['Location', $location],
  ['Name', $name],
  ['Mobile', $phone],
  ['Email', $email],
  ['Lives in', $place !== '' ? $place : '—'],
  ['Qualification', $qualification],
  ['Experience', $experience],
];

$text = "New job application from proudphysio.com/careers\n\n";
foreach ($rows as $r) $text .= "$r[0]: $r[1]\n";
$text .= "\nMessage:\n" . ($message !== '' ? $message : '—') . "\n\nCV attached: $filename\nReply to this email to write to the applicant directly.\nSent $sentAt IST\n";

$td = 'padding:6px 16px 6px 0;vertical-align:top;font:14px/1.5 Arial,sans-serif';
$html = '<div style="font:14px/1.6 Arial,sans-serif;color:#11283a">'
  . '<p style="margin:0 0 14px">New job application from <a href="https://proudphysio.com/careers/">proudphysio.com/careers</a></p><table style="border-collapse:collapse">';
foreach ($rows as $r) $html .= '<tr><td style="' . $td . ';color:#46555b">' . esc($r[0]) . '</td><td style="' . $td . '"><strong>' . esc($r[1]) . '</strong></td></tr>';
$html .= '</table><p style="margin:16px 0 4px;color:#46555b">Message</p><p style="margin:0;white-space:pre-wrap">' . esc($message !== '' ? $message : '—') . '</p>'
  . '<p style="margin:18px 0 0;color:#46555b">CV attached: ' . esc($filename) . '<br>Reply to this email to write to the applicant directly.<br>Sent ' . esc($sentAt) . ' IST</p></div>';

$mixed = 'mixed-' . bin2hex(random_bytes(12));
$alt = 'alt-' . bin2hex(random_bytes(12));
$ascii = preg_replace('/[^A-Za-z0-9 ().-]+/', '_', $filename);
$headers = [
  'Date: ' . date('r'),
  'From: ' . mailbox('Proud Physio Careers (website)', $from),
  'To: <' . $to . '>',
  'Reply-To: ' . mailbox($name, $email),
  'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
  'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . SITE_HOST . '>',
  'MIME-Version: 1.0',
  'Content-Type: multipart/mixed; boundary="' . $mixed . '"',
];
$body = "--$mixed\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n"
  . "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode(str_replace("\n", "\r\n", $text)))
  . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
  . "--$alt--\r\n"
  . "--$mixed\r\nContent-Type: {$kind['mime']}; name=\"$ascii\"\r\n"
  . "Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($filename) . "\r\n"
  . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bytes))
  . "--$mixed--";

try {
  smtp_send($cfg, $from, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
} catch (Exception $e) {
  error_log('[careers] sending failed: ' . $e->getMessage());
  fail(502, 'We could not send your application just now.', ['fallback' => true]);
}

if ($ip !== '') rate($ip, true);
ok();
