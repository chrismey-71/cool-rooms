<?php
declare(strict_types=1);

// Run against a disposable application copy and a dedicated empty database only.
// COOL_ROOMS_TEST_ROOT=/tmp/copy php tests/security_integration.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath((string)getenv('COOL_ROOMS_TEST_ROOT'));
if (!$root || $root === realpath(__DIR__ . '/..') || !is_file($root . '/www/config.php')) {
  throw new RuntimeException('Set COOL_ROOMS_TEST_ROOT to a disposable app copy, not the working repository.');
}
session_start();
require $root . '/www/lib/auth.php';
$pdo = db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'cool_rooms_security_test') {
  throw new RuntimeException('Only the dedicated cool_rooms_security_test database is allowed.');
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
if (($argv[1] ?? '') === 'worker') {
  $status = security_login_begin($pdo, 'parallel');
  echo json_encode($status, JSON_THROW_ON_ERROR);
  exit;
}
$checks = [];
function check(bool $ok, string $message): void {
  global $checks;
  if (!$ok) throw new RuntimeException('FAIL: ' . $message);
  $checks[] = 'PASS: ' . $message;
}
function failures(PDO $pdo, string $scope, string $id = '', bool $ip = false): int {
  $st = $pdo->prepare('SELECT failures FROM auth_attempts WHERE bucket_hash=?');
  $st->execute([security_throttle_bucket($scope, $id, $ip)]);
  return (int)$st->fetchColumn();
}
function expire_bucket(PDO $pdo, string $scope, string $id, bool $ip = false): void {
  $st = $pdo->prepare('UPDATE auth_attempts SET locked_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE bucket_hash=?');
  $st->execute([security_throttle_bucket($scope, $id, $ip)]);
}
preg_match_all('/CREATE TABLE IF NOT EXISTS [a-z_]+\s*\(.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s', file_get_contents($root . '/www/install.php'), $schema);
foreach ($schema[0] as $sql) $pdo->exec($sql);
check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, 'empty dedicated test database');
$hash = password_hash('DemoPassword123!', PASSWORD_BCRYPT, ['cost' => 4]);
$st = $pdo->prepare("INSERT INTO users(role,first_name,last_name,username,password_hash) VALUES('student','Test','Example',?,?)");
foreach (['student-a','student-b','student-c','last-chance'] as $name) $st->execute([$name,$hash]);
$pdo->exec("INSERT INTO app_settings(k,v) VALUES('auth_max_attempts','3'),('auth_lock_minutes','1'),('test_pin','unchanged')");
$st = $pdo->prepare('INSERT INTO auth_attempts(bucket_hash,scope,failures,locked_until) VALUES(?,?,3,UTC_TIMESTAMP()+INTERVAL 1 HOUR)');
foreach (['login_source','kiosk_source','kiosk_global','login_account'] as $scope) {
  $st->execute([security_throttle_bucket($scope,'old',false),$scope]);
}
security_ensure_throttle_schema($pdo);
check((int)$pdo->query("SELECT COUNT(*) FROM auth_attempts WHERE scope IN ('login_source','kiosk_source','kiosk_global')")->fetchColumn() === 0, 'legacy shared locks removed');
check(failures($pdo,'login_account','old') === 3, 'existing account lock preserved');
for ($i=0; $i<3; $i++) check(!login_attempt('student-a','wrong'), 'wrong credentials rejected');
check(security_login_status($pdo,'student-a')['locked'], 'only attacked account locked');
check(login_attempt('student-b','DemoPassword123!'), 'another account on the same school IP can log in');
$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
check(!login_attempt('student-a','DemoPassword123!'), 'account lock follows the account across IPs');
check(!login_attempt('STUDENT-A','DemoPassword123!'), 'case alias cannot bypass account lock');
check(!login_attempt('stúdent-a','DemoPassword123!'), 'accent alias cannot bypass database-collation account lock');
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
expire_bucket($pdo,'login_account','student-a');
check(!login_attempt('student-a','wrong') && failures($pdo,'login_account','student-a') === 1, 'expired lock restarts at one failure');
check(!security_login_status($pdo,'student-a')['locked'], 'full allowance restored after expiry');
check(login_attempt('student-a','DemoPassword123!') && failures($pdo,'login_account','student-a') === 0, 'success clears only the relevant account');
login_attempt('last-chance','wrong'); login_attempt('last-chance','wrong');
check(login_attempt('last-chance','DemoPassword123!'), 'correct password on last permitted attempt succeeds');
check(!login_attempt("' OR 1=1 --",'wrong'), 'SQL-like username does not bypass authentication');
check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 4, 'accounts unchanged');

$cookieName = 'cr_auth_browser_' . substr(hash('sha256','/'),0,8);
$_COOKIE=[]; $_SESSION=[];
$browserA = security_browser_id(); $cookieA = $_COOKIE[$cookieName];
for ($i=0; $i<3; $i++) check(!security_kiosk_begin($pdo)['locked'], 'browser A receives its allowed kiosk checks');
check(security_kiosk_status($pdo)['locked'] && security_kiosk_begin($pdo)['locked'], 'browser A blocked after its allowance');
$_COOKIE=[]; $_SESSION=[];
$browserB = security_browser_id(); $cookieB = $_COOKIE[$cookieName];
check($browserA !== $browserB && !security_kiosk_begin($pdo)['locked'], 'browser B on same school IP remains usable');
security_kiosk_success($pdo);
$_COOKIE=[$cookieName=>$cookieA]; $_SESSION=[];
check(security_browser_id() === $browserA && security_kiosk_status($pdo)['locked'], 'signed identity survives logout; other browser success cannot clear it');
$_COOKIE=[$cookieName=>$browserB.'.'.str_repeat('0',64)]; $_SESSION=[];
check(security_browser_id() !== $browserB, 'forged browser cookie rejected');
$_COOKIE=[$cookieName=>$cookieA]; $_SESSION=[];
expire_bucket($pdo,'kiosk_browser',$browserA);
check(!security_kiosk_begin($pdo)['locked'] && failures($pdo,'kiosk_browser',$browserA) === 1, 'browser gets full allowance after expiry');
check($pdo->query("SELECT v FROM app_settings WHERE k='test_pin'")->fetchColumn() === 'unchanged', 'PIN settings untouched');

$pdo->exec("DELETE FROM auth_attempts WHERE scope IN ('login_burst','kiosk_burst')");
for ($i=0; $i<100; $i++) {
  if (!login_attempt('student-c','DemoPassword123!')) throw new RuntimeException('School login batch unexpectedly blocked');
}
check(failures($pdo,'login_burst','',true) === 100, '100 legitimate school logins succeed and do not erase IP accounting');
$pdo->exec("DELETE FROM auth_attempts WHERE scope='login_burst'");
for ($i=0; $i<600; $i++) {
  if (security_take_attempt($pdo,'login_burst','',true,600,60,true)['locked']) throw new RuntimeException('Default burst budget too small');
}
$blocked = security_login_begin($pdo,'student-c');
check($blocked['locked'] && $blocked['kind'] === 'ip' && $blocked['retry_after'] <= 60, 'request 601 waits at most one minute');
$until = $pdo->query("SELECT locked_until FROM auth_attempts WHERE scope='login_burst'")->fetchColumn();
security_login_begin($pdo,'other');
check($until === $pdo->query("SELECT locked_until FROM auth_attempts WHERE scope='login_burst'")->fetchColumn(), 'blocked requests do not extend IP window');
check(!security_kiosk_begin($pdo)['locked'], 'login burst does not block kiosk checks');
expire_bucket($pdo,'login_burst','',true);
check(!security_login_begin($pdo,'new-account')['locked'], 'expired IP window immediately accepts again');
$pdo->exec("DELETE FROM auth_attempts WHERE scope='kiosk_burst'");
for ($i=0; $i<120; $i++) {
  if (security_take_attempt($pdo,'kiosk_burst','',true,120,60,true)['locked']) throw new RuntimeException('Kiosk budget too small');
}
$_COOKIE=[]; $_SESSION=[];
check(security_kiosk_begin($pdo)['locked'], 'new browser cannot bypass IP burst limit');
$_SERVER['HTTP_X_FORWARDED_FOR']='192.0.2.99';
check(security_client_ip()==='203.0.113.10','forged forwarding header ignored');

$pdo->exec("DELETE FROM auth_attempts WHERE scope='login_burst'");
$workers=[];
for ($i=0; $i<20; $i++) {
  $pipes=[];
  $process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if (!is_resource($process)) throw new RuntimeException('Cannot create test worker');
  fclose($pipes[0]); $workers[]=[$process,$pipes];
}
$allowed=0;
foreach ($workers as [$process,$pipes]) {
  $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
  fclose($pipes[1]); fclose($pipes[2]);
  if (proc_close($process)!==0) throw new RuntimeException('Worker failed: '.$error);
  $result=json_decode($output,true,512,JSON_THROW_ON_ERROR);
  if (!$result['locked']) $allowed++;
}
check($allowed === 3 && failures($pdo,'login_account','parallel') === 3, '20 parallel first requests admit exactly three credential checks');
check(security_login_limits($pdo)['attempts'] === 3, 'admin account/browser settings retained');

// Existing class grants and real hashed/encrypted PINs must survive throttling.
$pdo->exec("DELETE FROM auth_attempts WHERE scope IN ('kiosk_burst','kiosk_browser')");
$today=date('Y-m-d');
kiosk_setting_set($pdo,'kiosk_enabled','1');
kiosk_setting_set($pdo,'kiosk_valid_from',$today);
kiosk_setting_set($pdo,'kiosk_valid_to',$today);
kiosk_set_school_code($pdo,'Demo-school-5829');
$classHash=password_hash('4711',PASSWORD_DEFAULT);
$st=$pdo->prepare('INSERT INTO kiosk_class_codes(class_name,code_hash,code_encrypted,valid_date) VALUES(?,?,?,?)');
$st->execute(['2A',$classHash,secret_encrypt('4711'),$today]);
$_COOKIE=[]; $_SESSION=[];
check(kiosk_mark_verified($pdo,'class','2A',$today), 'class PIN grant can be established');
for($i=0;$i<3;$i++) security_kiosk_begin($pdo);
check(security_kiosk_status($pdo)['locked'] && kiosk_session_grant_valid($pdo,$today), 'PIN-check throttling does not revoke existing kiosk grant');
$_SESSION['uid']=1;
kiosk_reset_student($pdo);
check(kiosk_session_grant_valid($pdo,$today) && $_SESSION['kiosk_scope_class']==='2A', 'return from student preserves class kiosk scope');
check(kiosk_verify_school_code($pdo,'Demo-school-5829') && kiosk_class_code($pdo,'2A',$today)==='4711', 'school and class PIN remain valid and readable');
check($pdo->query('SELECT code_hash FROM kiosk_class_codes LIMIT 1')->fetchColumn()===$classHash,'stored class hash unchanged');
echo implode(PHP_EOL,$checks).PHP_EOL.'ALL SECURITY TESTS PASSED'.PHP_EOL;
