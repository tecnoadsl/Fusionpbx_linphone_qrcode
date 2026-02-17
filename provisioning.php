<?php
/*
	FusionPBX - Linphone Provisioning XML Generator
	Copyright (C) 2024 Tecnoadsl.net
*/

//includes
	include "root.php";
	require_once "resources/require.php";

//get token
	$token = isset($_GET['token']) ? $_GET['token'] : '';
	if (empty($token)) {
		http_response_code(400);
		die("Invalid request");
	}

//get all domains
	$sql = "SELECT domain_uuid, domain_name FROM v_domains WHERE domain_enabled = 'true'";
	$database = new database;
	$domains = $database->select($sql, null, 'all');

//try to decrypt token with each domain
	$extension_uuid = null;
	$domain_uuid = null;
	$domain_name = null;

	foreach ($domains as $domain) {
		$iv = substr(md5($domain['domain_uuid']), 0, 16);
		$decrypted = @openssl_decrypt(base64_decode($token), 'AES-256-CBC', $domain['domain_uuid'], 0, $iv);

		if ($decrypted && strpos($decrypted, '|') !== false) {
			list($ext_uuid, $timestamp) = explode('|', $decrypted);

			//check if token is not too old (24 hours)
			if (time() - intval($timestamp) < 86400) {
				$sql2 = "SELECT extension_uuid FROM v_extensions WHERE extension_uuid = :ext AND domain_uuid = :dom";
				$params = array('ext' => $ext_uuid, 'dom' => $domain['domain_uuid']);
				if ($database->select($sql2, $params, 'row')) {
					$extension_uuid = $ext_uuid;
					$domain_uuid = $domain['domain_uuid'];
					$domain_name = $domain['domain_name'];
					break;
				}
			}
		}
	}

	if (!$extension_uuid) {
		http_response_code(403);
		die("Invalid or expired token");
	}

//get extension details
	$sql = "SELECT extension, password, effective_caller_id_name FROM v_extensions ";
	$sql .= "WHERE extension_uuid = :ext AND domain_uuid = :dom AND enabled = 'true'";
	$parameters = array('ext' => $extension_uuid, 'dom' => $domain_uuid);
	$ext = $database->select($sql, $parameters, 'row');
	unset($sql, $parameters);

	if (!$ext) {
		http_response_code(404);
		die("Extension not found");
	}

//settings
	$push_proxy = 'push.tecnoadsl.net';

//override push proxy based on domain
	if (preg_match('/\.voip6\.tecnoadsl\.net$/', $domain_name)) {
		$push_proxy = 'push6.tecnoadsl.net';
	} elseif (preg_match('/\.voip5\.tecnoadsl\.net$/', $domain_name)) {
		$push_proxy = 'push5.tecnoadsl.net';
	}

//registration duration
	$allowed_reg_expires = array('3600', '86400', '604800', '2592000');
	$reg_expires = isset($_GET['reg_expires']) && in_array($_GET['reg_expires'], $allowed_reg_expires) ? $_GET['reg_expires'] : '2592000';

//transport selection
	$allowed_transports = array('tls', 'tcp', 'udp');
	$transport = isset($_GET['transport']) && in_array($_GET['transport'], $allowed_transports) ? $_GET['transport'] : 'tls';

//presence selection (default disabled)
	$presence = isset($_GET['presence']) && $_GET['presence'] === '1' ? '1' : '0';

//transport-dependent settings
	switch ($transport) {
		case 'tls':
			$sip_scheme = 'sips';
			$sip_port = '5061';
			$local_udp_port = '-1';
			$local_tcp_port = '-1';
			$local_tls_port = '5061';
			break;
		case 'tcp':
			$sip_scheme = 'sip';
			$sip_port = '5060';
			$local_udp_port = '-1';
			$local_tcp_port = '5060';
			$local_tls_port = '-1';
			break;
		case 'udp':
			$sip_scheme = 'sip';
			$sip_port = '5060';
			$local_udp_port = '5060';
			$local_tcp_port = '-1';
			$local_tls_port = '-1';
			break;
	}

//display name
	$display_name = $ext['effective_caller_id_name'] ? $ext['effective_caller_id_name'] : $ext['extension'];

//get all extensions in domain for friend list (always, needed for caller ID and team)
	$sql = "SELECT extension, effective_caller_id_name, description FROM v_extensions ";
	$sql .= "WHERE domain_uuid = :dom AND enabled = 'true' AND extension != :current_ext ";
	$sql .= "ORDER BY extension";
	$parameters = array('dom' => $domain_uuid, 'current_ext' => $ext['extension']);
	$all_extensions = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//output XML
	header('Content-Type: application/xml; charset=utf-8');
	echo '<?xml version="1.0" encoding="UTF-8"?>';
?>

<config xmlns="http://www.linphone.org/xsds/lpconfig.xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.linphone.org/xsds/lpconfig.xsd lpconfig.xsd">
	<section name="proxy_0">
		<entry name="reg_proxy" overwrite="true"><?php echo $sip_scheme; ?>:<?php echo $push_proxy; ?>:<?php echo $sip_port; ?>;transport=<?php echo $transport; ?></entry>
		<entry name="reg_route" overwrite="true"><?php echo $sip_scheme; ?>:<?php echo $push_proxy; ?>:<?php echo $sip_port; ?>;transport=<?php echo $transport; ?>;lr</entry>
		<entry name="reg_identity" overwrite="true"><?php echo $sip_scheme; ?>:<?php echo $ext['extension']; ?>@<?php echo $domain_name; ?></entry>
		<entry name="realm" overwrite="true"><?php echo $domain_name; ?></entry>
		<entry name="reg_expires" overwrite="true"><?php echo $reg_expires; ?></entry>
		<entry name="reg_sendregister" overwrite="true">1</entry>
		<entry name="publish" overwrite="true"><?php echo $presence; ?></entry>
		<entry name="push_notification_allowed" overwrite="true">1</entry>
	</section>
	<section name="auth_info_0">
		<entry name="username" overwrite="true"><?php echo $ext['extension']; ?></entry>
		<entry name="userid" overwrite="true"><?php echo $ext['extension']; ?></entry>
		<entry name="passwd" overwrite="true"><?php echo $ext['password']; ?></entry>
		<entry name="realm" overwrite="true"><?php echo $domain_name; ?></entry>
		<entry name="domain" overwrite="true"><?php echo $domain_name; ?></entry>
	</section>
	<section name="sip">
		<entry name="default_proxy" overwrite="true">0</entry>
		<entry name="use_rfc2833" overwrite="true">1</entry>
		<entry name="sip_port" overwrite="true"><?php echo $local_udp_port; ?></entry>
		<entry name="sip_tcp_port" overwrite="true"><?php echo $local_tcp_port; ?></entry>
		<entry name="sip_tls_port" overwrite="true"><?php echo $local_tls_port; ?></entry>
	</section>
	<section name="app">
		<entry name="display_name" overwrite="true"><?php echo htmlspecialchars($display_name); ?></entry>
		<entry name="remote_friends_url" overwrite="true">https://<?php echo $_SERVER['HTTP_HOST']; ?>/app/provision/linphone_friends.php</entry>
		<entry name="remote_friends_sync_interval_hours" overwrite="true">4</entry>
	</section>
	<section name="audio_codec_0">
		<entry name="mime" overwrite="true">opus</entry>
		<entry name="rate" overwrite="true">48000</entry>
		<entry name="channels" overwrite="true">2</entry>
		<entry name="enabled" overwrite="true">1</entry>
	</section>
	<section name="audio_codec_1">
		<entry name="mime" overwrite="true">PCMA</entry>
		<entry name="rate" overwrite="true">8000</entry>
		<entry name="channels" overwrite="true">1</entry>
		<entry name="enabled" overwrite="true">1</entry>
	</section>
	<section name="audio_codec_2">
		<entry name="mime" overwrite="true">PCMU</entry>
		<entry name="rate" overwrite="true">8000</entry>
		<entry name="channels" overwrite="true">1</entry>
		<entry name="enabled" overwrite="true">1</entry>
	</section>
<?php
//generate friend sections (always included for caller ID and team view)
if (!empty($all_extensions)) {
	$i = 0;
	foreach ($all_extensions as $friend) {
		$friend_name = $friend['effective_caller_id_name'] ?: $friend['description'] ?: $friend['extension'];
		$friend_name = htmlspecialchars($friend_name, ENT_XML1, 'UTF-8');
		$friend_ext = htmlspecialchars($friend['extension'], ENT_XML1, 'UTF-8');
?>
	<section name="friend_<?php echo $i; ?>">
		<entry name="url" overwrite="true">"<?php echo $friend_name; ?>" &lt;sip:<?php echo $friend_ext; ?>@<?php echo $domain_name; ?>&gt;</entry>
		<entry name="pol" overwrite="true">accept</entry>
		<entry name="subscribe" overwrite="true"><?php echo $presence; ?></entry>
	</section>
<?php
		$i++;
	}
}
?>
</config>
