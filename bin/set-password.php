<?php

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

$raw = stream_get_contents(STDIN);
if ($raw === false) {
    fwrite(STDERR, "Could not read the password.\n");
    exit(1);
}
$password = rtrim($raw, "\r\n");
if (strlen($password) < 8 || strlen($password) > 200) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$db = Database::instance();
$existed = (string)$db->getSetting('password_hash', '') !== '';
$db->setSetting('password_hash', password_hash($password, PASSWORD_DEFAULT));

$mode = getenv('MODEL_MODE') ?: '';
if (in_array($mode, ['cloud', 'local', 'cpu_failsafe'], true)) {
    $db->setSetting('model_mode', $mode);
}
$ollama = getenv('OLLAMA_URL') ?: '';
if ($ollama !== '' && preg_match('#^https?://\S+$#', $ollama)) {
    $db->setSetting('ollama_url', rtrim($ollama, '/'));
}
$bind = getenv('BIND_HOST') ?: '';
if (in_array($bind, ['127.0.0.1', '0.0.0.0'], true)) {
    $db->setSetting('bind_host', $bind);
}
$localModel = getenv('LOCAL_MODEL') ?: '';
if ($localModel !== '' && preg_match('/^[A-Za-z0-9_.:\/-]{1,120}$/', $localModel)) {
    $db->setSetting('local_model', $localModel);
}
$appPort = getenv('APP_PORT') ?: '';
if (preg_match('/^\d+$/', $appPort)) {
    $portNumber = (int)$appPort;
    if ($portNumber >= 1 && $portNumber <= 65535) {
        $db->setSetting('port', (string)$portNumber);
    }
}
$aiAct = getenv('AI_ACT_APPLIES') ?: '';
if (in_array($aiAct, ['yes', 'no', 'unsure'], true)) {
    $db->setSetting('ai_act_applies', $aiAct);
}

echo $existed ? "Password updated.\n" : "Password saved.\n";
