<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

// --- BLF WebSocket JWT Configuration ---
$blf_jwt_secret = "ff6f69e33986e9abb9aaa048c0d06846343f736ca90aa96236649c13b5a71b6a";
$blf_ws_url     = "wss://push6.tecnoadsl.net/ws";
$blf_token_ttl  = 86400; // 24 hours

function blf_base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");
}

function blf_generate_jwt($payload, $secret) {
    $header = blf_base64url_encode(json_encode(["alg" => "HS256", "typ" => "JWT"]));
    $payload_encoded = blf_base64url_encode(json_encode($payload));
    $signature = blf_base64url_encode(
        hash_hmac("sha256", "$header.$payload_encoded", $secret, true)
    );
    return "$header.$payload_encoded.$signature";
}

// Contatti originali di Fusion (v_contacts + v_contact_phones) del tenant, per le app mobili (sola lettura).
// Nome = "nome cognome", altrimenti organizzazione, altrimenti soprannome; numeri = telefoni non-fax puliti.
// Contatti senza nome o senza numeri vengono scartati. Visibili a tutti gli interni del tenant.
function normalize_contact_number($n) {
    // Il PBX instrada i numeri italiani SENZA prefisso (3xx..., 0xx...) e rifiuta +39/0039: qui si tolgono.
    // I numeri esteri restano con + davanti (+44...). Solo cifre, nessuno spazio.
    $n = trim($n);
    $plus = (strpos($n, "+") === 0);
    $d = preg_replace("/[^0-9]/", "", $n);
    if ($d === "") { return ""; }
    if (!$plus && strpos($d, "00") === 0) { $d = substr($d, 2); $plus = true; }
    if ($plus) {
        if (strpos($d, "39") === 0) { $d = substr($d, 2); $plus = false; }
    } elseif (preg_match("/^39[0-9]{9,}$/", $d)) {
        $d = substr($d, 2);
    }
    return (strlen($d) >= 3) ? (($plus ? "+" : "") . $d) : "";
}

function build_shared_contacts($pdo, $domain_uuid) {
    // Aggregazioni una sola volta per tenant (le sotto-query per contatto con 15mila righe impiegavano 35 s)
    $stmt = $pdo->prepare("WITH ph AS (
            SELECT contact_uuid, string_agg(phone_number, '|' ORDER BY phone_primary DESC NULLS LAST) AS numbers
              FROM v_contact_phones
             WHERE domain_uuid = :d AND nullif(trim(phone_number), '') IS NOT NULL AND coalesce(phone_type_fax, 0) = 0
             GROUP BY contact_uuid),
          em AS (
            SELECT DISTINCT ON (contact_uuid) contact_uuid, email_address
              FROM v_contact_emails
             WHERE domain_uuid = :d AND nullif(trim(email_address), '') IS NOT NULL
             ORDER BY contact_uuid, email_primary DESC NULLS LAST)
        SELECT c.contact_name_given, c.contact_name_family, c.contact_organization, c.contact_nickname,
               ph.numbers, em.email_address AS email
          FROM v_contacts c
          JOIN ph ON ph.contact_uuid = c.contact_uuid
          LEFT JOIN em ON em.contact_uuid = c.contact_uuid
         WHERE c.domain_uuid = :d");
    $stmt->execute([":d" => $domain_uuid]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = trim(trim($r["contact_name_given"] ?? "") . " " . trim($r["contact_name_family"] ?? ""));
        if ($name === "") { $name = trim($r["contact_organization"] ?? ""); }
        if ($name === "") { $name = trim($r["contact_nickname"] ?? ""); }
        if ($name === "") { continue; }
        $numbers = [];
        foreach (explode("|", $r["numbers"] ?? "") as $n) {
            $n = normalize_contact_number($n);
            if ($n !== "" && !in_array($n, $numbers, true)) { $numbers[] = $n; }
        }
        if (count($numbers) === 0) { continue; }
        $out[] = [
            "name"    => $name,
            "numbers" => $numbers,
            "company" => trim($r["contact_organization"] ?? ""),
            "email"   => trim($r["email"] ?? ""),
        ];
    }
    usort($out, function ($a, $b) { return strcasecmp($a["name"], $b["name"]); });
    return $out;
}

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
$password_verified = false; // true solo col Metodo 2 (password SIP verificata)

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
    if ($row && hash_equals((string)$row["password"], (string)$password)) {
        $authenticated = true;
        $password_verified = true;
        $domain_uuid = $row["domain_uuid"];
    }
}

// Metodo 2b: HA1 al posto della password in chiaro. L'SDK Linphone, dopo l'uso, sostituisce la password
// con md5(utente:realm:password) e le app inviano quello. Il realm e' il dominio del tenant.
if (!$authenticated && !empty($username) && !empty($domain) && preg_match('/^[0-9a-f]{32}$/i', $password)) {
    $stmt = $pdo->prepare("SELECT e.extension_uuid, e.password, d.domain_uuid
        FROM v_extensions e
        JOIN v_domains d ON e.domain_uuid = d.domain_uuid
        WHERE e.extension = ? AND d.domain_name = ? AND e.enabled = 'true'");
    $stmt->execute([$username, $domain]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && hash_equals(md5($username . ":" . $domain . ":" . $row["password"]), strtolower($password))) {
        $authenticated = true;
        $password_verified = true;
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

// Ottieni lista estensioni (escludi l'interno richiedente)
$stmt = $pdo->prepare("SELECT extension, effective_caller_id_name, description
    FROM v_extensions
    WHERE domain_uuid = ? AND enabled = 'true' AND extension != ?
    ORDER BY extension");
$stmt->execute([$domain_uuid, $user]);
$extensions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$friends = [];
foreach ($extensions as $ext) {
    $name = $ext["effective_caller_id_name"] ?: $ext["description"] ?: $ext["extension"];
    $friends[] = [
        "name" => $name,
        "sip_address" => $ext["extension"] . "@" . $domain,
        "subscribe" => true,
        "subscribe_policy" => "accept"
    ];
}

// Generate BLF JWT token
$blf_token = blf_generate_jwt([
    "sub"      => $user,
    "username" => $user,
    "domain"   => $domain,
    "iat"      => time(),
    "exp"      => time() + $blf_token_ttl,
], $blf_jwt_secret);

$response = [
    "version"   => time(),
    "count"     => count($friends),
    "friends"   => $friends,
    "blf_token" => $blf_token,
    "blf_url"   => $blf_ws_url,
];

// La rubrica condivisa solo a password SIP verificata: mai col token fisso (Metodo 1)
// ne' con l'accesso per sola esistenza dell'utente (Metodo 3).
if ($password_verified) {
    try {
        $response["contacts"] = build_shared_contacts($pdo, $domain_uuid);
    } catch (Throwable $e) {
        // Es. tabella assente su un PBX senza l'app rubrica: si omette solo contacts, interni e BLF restano
        error_log("LinphoneFriends: contacts omessi: " . $e->getMessage());
    }
}

echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
