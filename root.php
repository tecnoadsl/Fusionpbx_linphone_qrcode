<?php
/*
	FusionPBX
	Version: MPL 1.1
*/

//set the include path
	$conf = glob("{/usr/local/etc,/etc}/fusionpbx/config.conf", GLOB_BRACE);
	set_include_path(parse_ini_file($conf[0])['document.root']);

//check the document root
	if (!defined('PROJECT_PATH') && !defined('STDIN') && $_SERVER['PROJECT_PATH'] != '') {
		chdir($_SERVER['PROJECT_PATH'] . "/../..");
		define('PROJECT_PATH', $_SERVER['PROJECT_PATH']);
		set_include_path(get_include_path() . PATH_SEPARATOR . PROJECT_PATH);
	}
?>
