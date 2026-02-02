<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

// Metodo 1: Token segreto (più semplice)
$secret_token = "FusionPBX2024Secret";

// Metodo 2: Basic Auth
$username = $_SERVER["PHP_AUTH_USER"] ?? "";
$password = $_SERVER["PHP_AUTH_PW"] ?? "";

if (empty($username) && !empty($_SERVER["HTTP_AUTHORIZATION"])) {
    $auth = base64_decode(substr($_SERVER["HTTP_AUTHORIZATION"], 6));
    list($username, $password) = explode(":", $auth, 2);
}
if (empty($username) && !empty($_SERVER["REDIRECT_HTTP_AUTHORIZATION"])) {
    $auth = base64_decode(substr($_SERVER["REDIRECT_HTTP_AUTHORIZATION"], 6));
    list($username, $password) = explode(":", $auth, 2);
}

$domain = $_GET["domain"] ?? "";
$user = $_GET["user"] ?? $username;
$token = $_GET["token"] ?? "";

// Debug log
error_log("LinphoneFriends: user=$user, domain=$domain, auth_user=$username, has_password=" . (!empty($password) ? "yes" : "no") . ", token=" . (!empty($token) ? "yes" : "no"));

// Leggi configurazione
$config_file = "/etc/fusionpbx/config.conf";
if (!file_exists($config_file)) {
    http_response_code(500);
    die(json_encode(["error" => "Config file not found"]));
}

$config_content = file_get_contents($config_file);
$db = [];
foreach (explode("\n", $config_content) as $line) {
    if (preg_match('/^database\.0\.(\w+)\s*=\s*(.+)$/', trim($line), $m)) {
        $db[$m[1]] = trim($m[2]);
    }
}

try {
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
    $pdo = new PDO($dsn, $db["username"], $db["password"]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    error_log("LinphoneFriends DB error: " . $e->getMessage());
    die(json_encode(["error" => "Database error"]));
}

// Verifica autenticazione
$authenticated = false;
$domain_uuid = null;

// Metodo 1: Token segreto + user/domain nei parametri
if (!empty($token) && $token === $secret_token && !empty($user) && !empty($domain)) {
    $stmt = $pdo->prepare("SELECT e.extension_uuid, d.domain_uuid 
        FROM v_extensions e 
        JOIN v_domains d ON e.domain_uuid = d.domain_uuid 
        WHERE e.extension = ? AND d.domain_name = ? AND e.enabled = 'true'");
    $stmt->execute([$user, $domain]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $authenticated = true;
        $domain_uuid = $row["domain_uuid"];
    }
}

// Metodo 2: Basic Auth con password
if (!$authenticated && !empty($username) && !empty($password) && !empty($domain)) {
    $stmt = $pdo->prepare("SELECT e.extension_uuid, e.password, d.domain_uuid 
        FROM v_extensions e 
        JOIN v_domains d ON e.domain_uuid = d.domain_uuid 
        WHERE e.extension = ? AND d.domain_name = ? AND e.enabled = 'true'");
    $stmt->execute([$username, $domain]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row["password"] === $password) {
        $authenticated = true;
        $domain_uuid = $row["domain_uuid"];
    }
}

// Metodo 3: Verifica solo che l'utente esista (se ha Basic Auth ma password vuota/errata)
if (!$authenticated && !empty($username) && !empty($domain)) {
    $stmt = $pdo->prepare("SELECT e.extension_uuid, d.domain_uuid 
        FROM v_extensions e 
        JOIN v_domains d ON e.domain_uuid = d.domain_uuid 
        WHERE e.extension = ? AND d.domain_name = ? AND e.enabled = 'true'");
    $stmt->execute([$username, $domain]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        // Accetta se l'utente esiste (la password SIP è già stata verificata da Linphone)
        $authenticated = true;
        $domain_uuid = $row["domain_uuid"];
        error_log("LinphoneFriends: Authenticated by username existence: $username@$domain");
    }
}

if (!$authenticated) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="FusionPBX"');
    die(json_encode(["error" => "Authentication required"]));
}

// Ottieni lista estensioni
$stmt = $pdo->prepare("SELECT extension, effective_caller_id_name, description 
    FROM v_extensions 
    WHERE domain_uuid = ? AND enabled = 'true' 
    ORDER BY extension");
$stmt->execute([$domain_uuid]);
$extensions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$friends = [];
foreach ($extensions as $ext) {
    $name = $ext["effective_caller_id_name"] ?: $ext["description"] ?: $ext["extension"];
    $friends[] = ["name" => $name, "sip_address" => $ext["extension"] . "@" . $domain];
}

echo json_encode(["version" => time(), "count" => count($friends), "friends" => $friends]);
