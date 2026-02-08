<?php
/*
	FusionPBX - Linphone QR Code Generator
	Copyright (C) 2024 Tecnoadsl.net
*/

//includes
	include "root.php";
	require_once "resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (permission_exists('linphone_qrcode_view')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//settings
	$push_proxy = 'push.tecnoadsl.net';

//override push proxy based on domain
	$domain_name_temp = $_SESSION['domain_name'] ?? '';
	if (preg_match('/\.voip6\.tecnoadsl\.net$/', $domain_name_temp)) {
		$push_proxy = 'push6.tecnoadsl.net';
	} elseif (preg_match('/\.voip5\.tecnoadsl\.net$/', $domain_name_temp)) {
		$push_proxy = 'push5.tecnoadsl.net';
	}

//transport selection (default tls)
	$allowed_transports = array('tls', 'tcp', 'udp');
	$transport = isset($_GET['transport']) && in_array($_GET['transport'], $allowed_transports) ? $_GET['transport'] : 'tls';
	switch ($transport) {
		case 'tls': $sip_port = '5061'; break;
		case 'tcp': $sip_port = '5060'; break;
		case 'udp': $sip_port = '5060'; break;
	}

//registration duration selection (default 1 month)
	$allowed_reg_expires = array('3600', '86400', '604800', '2592000');
	$reg_expires = isset($_GET['reg_expires']) && in_array($_GET['reg_expires'], $allowed_reg_expires) ? $_GET['reg_expires'] : '2592000';

//get variables
	$domain_uuid = $_SESSION['domain_uuid'];
	$domain_name = $_SESSION['domain_name'];
	$user_uuid = $_SESSION['user_uuid'];

//get user's extensions
	$sql = "SELECT e.extension_uuid, e.extension, e.password, e.effective_caller_id_name ";
	$sql .= "FROM v_extensions e ";
	$sql .= "JOIN v_extension_users eu ON e.extension_uuid = eu.extension_uuid ";
	$sql .= "WHERE eu.user_uuid = :user_uuid ";
	$sql .= "AND e.domain_uuid = :domain_uuid ";
	$sql .= "AND e.enabled = 'true' ";
	$sql .= "ORDER BY e.extension ASC ";
	$parameters['user_uuid'] = $user_uuid;
	$parameters['domain_uuid'] = $domain_uuid;
	$database = new database;
	$extensions = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//select extension
	$selected_extension = null;
	$selected_extension_uuid = isset($_GET['extension_uuid']) ? $_GET['extension_uuid'] : '';

	if (!empty($extensions)) {
		foreach ($extensions as $ext) {
			if ($ext['extension_uuid'] == $selected_extension_uuid) {
				$selected_extension = $ext;
				break;
			}
		}
		if ($selected_extension === null) {
			$selected_extension = $extensions[0];
			$selected_extension_uuid = $selected_extension['extension_uuid'];
		}
	}

//generate provisioning URL
	$provisioning_url = '';
	if ($selected_extension) {
		$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'];
		$token_data = $selected_extension['extension_uuid'] . '|' . time();
		$iv = substr(md5($domain_uuid), 0, 16);
		$encrypted = openssl_encrypt($token_data, 'AES-256-CBC', $domain_uuid, 0, $iv);
		$token = base64_encode($encrypted);
		$provisioning_url = $base_url . '/app/linphone_qrcode/provisioning.php?token=' . urlencode($token) . '&transport=' . urlencode($transport) . '&reg_expires=' . urlencode($reg_expires);
	}

//include the header
	$document['title'] = $text['title-linphone_qrcode'];
	require_once "resources/header.php";

?>

<style>
.qr-container {
	text-align: center;
	max-width: 550px;
	margin: 20px auto;
	padding: 25px;
	background: #fff;
	border-radius: 10px;
	box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
.qr-code {
	margin: 25px 0;
	padding: 15px;
	background: #fff;
	display: inline-block;
	border-radius: 8px;
}
.config-table {
	width: 100%;
	margin: 20px 0;
	text-align: left;
	border-collapse: collapse;
}
.config-table td {
	padding: 10px 8px;
	border-bottom: 1px solid #eee;
}
.config-table td:first-child {
	font-weight: 600;
	width: 130px;
	color: #555;
}
.config-table td:last-child {
	font-family: monospace;
	font-size: 13px;
}
.copy-btn {
	padding: 3px 10px;
	font-size: 11px;
	cursor: pointer;
	background: #f0f0f0;
	border: 1px solid #ddd;
	border-radius: 3px;
	margin-left: 8px;
}
.copy-btn:hover {
	background: #e0e0e0;
}
.pwd-toggle {
	color: #007bff;
	cursor: pointer;
	font-size: 12px;
	margin-left: 10px;
}
.pwd-toggle:hover {
	text-decoration: underline;
}
.instructions {
	background: #f8f9fa;
	padding: 18px;
	border-radius: 8px;
	margin: 20px 0;
	text-align: left;
	border-left: 4px solid #007bff;
}
.instructions h4 {
	margin: 0 0 12px 0;
	color: #333;
}
.instructions ol {
	margin: 0;
	padding-left: 20px;
}
.instructions li {
	padding: 5px 0;
	color: #555;
}
.download-btns {
	margin: 25px 0 10px 0;
}
.download-btns a {
	display: inline-block;
	padding: 12px 25px;
	margin: 5px 10px;
	border-radius: 6px;
	text-decoration: none;
	font-weight: 600;
	transition: transform 0.2s;
}
.download-btns a:hover {
	transform: scale(1.05);
}
.download-btns .ios {
	background: #000;
	color: #fff;
}
.download-btns .android {
	background: #3ddc84;
	color: #000;
}
.ext-selector {
	margin: 15px 0 20px 0;
}
.ext-selector select {
	padding: 10px 15px;
	font-size: 15px;
	border-radius: 5px;
	border: 1px solid #ccc;
	min-width: 220px;
}
.error-box {
	background: #f8d7da;
	color: #721c24;
	padding: 20px;
	border-radius: 8px;
	margin: 20px 0;
}
</style>

<div class="action_bar" id="action_bar">
	<div class="heading"><b><?php echo $text['title-linphone_qrcode']; ?></b></div>
	<div class="actions"></div>
	<div style="clear: both;"></div>
</div>

<?php if (empty($extensions)): ?>
<div class="qr-container">
	<div class="error-box">
		<strong><?php echo $text['error-no_extension']; ?></strong>
	</div>
</div>
<?php else: ?>
<div class="qr-container">
	
	<?php if (count($extensions) > 1): ?>
	<div class="ext-selector">
		<label><strong><?php echo $text['label-select_extension']; ?>:</strong></label><br><br>
		<select onchange="location.href='?extension_uuid='+this.value+'&transport=<?php echo urlencode($transport); ?>&reg_expires=<?php echo urlencode($reg_expires); ?>'">
			<?php foreach ($extensions as $ext): ?>
			<option value="<?php echo $ext['extension_uuid']; ?>" <?php if($ext['extension_uuid']==$selected_extension_uuid) echo 'selected'; ?>>
				<?php echo htmlspecialchars($ext['extension']); ?>
				<?php if($ext['effective_caller_id_name']) echo ' - '.htmlspecialchars($ext['effective_caller_id_name']); ?>
			</option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php endif; ?>

	<div class="ext-selector">
		<label><strong><?php echo $text['label-transport']; ?>:</strong></label><br><br>
		<select onchange="location.href='?extension_uuid=<?php echo urlencode($selected_extension_uuid); ?>&transport='+this.value+'&reg_expires=<?php echo urlencode($reg_expires); ?>'">
			<option value="tls" <?php if($transport=='tls') echo 'selected'; ?>>TLS (<?php echo $text['label-recommended']; ?>)</option>
			<option value="tcp" <?php if($transport=='tcp') echo 'selected'; ?>>TCP</option>
			<option value="udp" <?php if($transport=='udp') echo 'selected'; ?>>UDP</option>
		</select>
	</div>

	<div class="ext-selector">
		<label><strong><?php echo $text['label-reg_expires']; ?>:</strong></label><br><br>
		<select onchange="location.href='?extension_uuid=<?php echo urlencode($selected_extension_uuid); ?>&transport=<?php echo urlencode($transport); ?>&reg_expires='+this.value">
			<option value="3600" <?php if($reg_expires=='3600') echo 'selected'; ?>><?php echo $text['label-1_hour']; ?></option>
			<option value="86400" <?php if($reg_expires=='86400') echo 'selected'; ?>><?php echo $text['label-1_day']; ?></option>
			<option value="604800" <?php if($reg_expires=='604800') echo 'selected'; ?>><?php echo $text['label-1_week']; ?></option>
			<option value="2592000" <?php if($reg_expires=='2592000') echo 'selected'; ?>><?php echo $text['label-1_month']; ?> (<?php echo $text['label-recommended']; ?>)</option>
		</select>
	</div>

	<p><?php echo $text['description-linphone_qrcode']; ?></p>
	
	<div class="qr-code" id="qrcode"></div>
	
	<table class="config-table">
		<tr>
			<td><?php echo $text['label-username']; ?>:</td>
			<td>
				<span id="val_user"><?php echo htmlspecialchars($selected_extension['extension']); ?></span>
				<button class="copy-btn" onclick="copyText('val_user')">📋 <?php echo $text['label-copy']; ?></button>
			</td>
		</tr>
		<tr>
			<td><?php echo $text['label-password']; ?>:</td>
			<td>
				<span id="val_pwd">••••••••</span>
				<span class="pwd-toggle" onclick="togglePwd()"><?php echo $text['label-show_password']; ?></span>
				<button class="copy-btn" onclick="copyPwd()">📋 <?php echo $text['label-copy']; ?></button>
			</td>
		</tr>
		<tr>
			<td><?php echo $text['label-domain']; ?>:</td>
			<td>
				<span id="val_domain"><?php echo htmlspecialchars($domain_name); ?></span>
				<button class="copy-btn" onclick="copyText('val_domain')">📋 <?php echo $text['label-copy']; ?></button>
			</td>
		</tr>
		<tr>
			<td><?php echo $text['label-proxy']; ?>:</td>
			<td>
				<span id="val_proxy"><?php echo htmlspecialchars($push_proxy); ?></span>
				<button class="copy-btn" onclick="copyText('val_proxy')">📋 <?php echo $text['label-copy']; ?></button>
			</td>
		</tr>
		<tr>
			<td><?php echo $text['label-transport']; ?>:</td>
			<td><?php echo strtoupper($transport); ?></td>
		</tr>
		<tr>
			<td><?php echo $text['label-port']; ?>:</td>
			<td><?php echo $sip_port; ?></td>
		</tr>
		<tr>
			<td><?php echo $text['label-reg_expires']; ?>:</td>
			<td><?php
				switch ($reg_expires) {
					case '3600': echo $text['label-1_hour']; break;
					case '86400': echo $text['label-1_day']; break;
					case '604800': echo $text['label-1_week']; break;
					case '2592000': echo $text['label-1_month']; break;
				}
			?></td>
		</tr>
	</table>
	
	<div class="instructions">
		<h4><?php echo $text['label-instructions']; ?></h4>
		<ol>
			<li><?php echo $text['label-instruction_1']; ?></li>
			<li><?php echo $text['label-instruction_2']; ?></li>
			<li><?php echo $text['label-instruction_3']; ?></li>
			<li><?php echo $text['label-instruction_4']; ?></li>
		</ol>
	</div>
	
	<div class="download-btns">
		<a href="https://apps.apple.com/app/linphone/id360065638" target="_blank" class="ios">
			🍎 iOS
		</a>
		<a href="https://play.google.com/store/apps/details?id=org.linphone" target="_blank" class="android">
			🤖 Android
		</a>
	</div>
	
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
var actualPwd = "<?php echo addslashes($selected_extension['password']); ?>";
var pwdVisible = false;

<?php if($provisioning_url): ?>
new QRCode(document.getElementById("qrcode"), {
	text: "<?php echo addslashes($provisioning_url); ?>",
	width: 220,
	height: 220,
	colorDark: "#000000",
	colorLight: "#ffffff",
	correctLevel: QRCode.CorrectLevel.M
});
<?php endif; ?>

function togglePwd() {
	var el = document.getElementById('val_pwd');
	var toggle = document.querySelector('.pwd-toggle');
	if (pwdVisible) {
		el.textContent = '••••••••';
		toggle.textContent = '<?php echo $text['label-show_password']; ?>';
		pwdVisible = false;
	} else {
		el.textContent = actualPwd;
		toggle.textContent = '<?php echo $text['label-hide_password']; ?>';
		pwdVisible = true;
	}
}

function copyText(id) {
	var text = document.getElementById(id).textContent;
	navigator.clipboard.writeText(text).then(function() {
		alert('<?php echo $text['label-copied']; ?>');
	});
}

function copyPwd() {
	navigator.clipboard.writeText(actualPwd).then(function() {
		alert('<?php echo $text['label-copied']; ?>');
	});
}
</script>

<?php
//include the footer
	require_once "resources/footer.php";
?>
