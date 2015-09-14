<?php
//Login Script
//Validates Username and Password
require_once ("config.php");

if ($_POST['legalterms'] != "on")
{
	//did not agree to legal terms, go back
	header("Location: authenticate.php?status=legalterms");
	exit();
}

$_GET['php_version'] = PHP_VERSION;

if (version_compare(PHP_VERSION, '5.5.0*', '>='))
{

	if (password_verify($_POST['f_user'].$_POST['f_pass'], $admin_password) == $admin_password && $_POST['f_user'] == $admin_username)
	{
		//successful admin login
		session_start();
		$_SESSION['admin_logged_in'] = true;
		$_SESSION['username'] = $admin_username;
		header("Location: admin.php");
		exit();
	}

	if (password_verify($_POST['f_user'].$_POST['f_pass'], $upload_password) == $upload_password && $_POST['f_user'] == $upload_username)
	{
	//successful upload login
	session_start();
	$_SESSION['upload_logged_in'] = true;
	$_SESSION['username'] = $upload_username;
	header("Location: index.php");
	exit();
	}
}

else if (version_compare(PHP_VERSION, '5.4.0*', '<='))
{

	if (crypt($_POST['f_user'].$_POST['f_pass'], $admin_password) == $admin_password && $_POST['f_user'] == $admin_username)
	{
		//successful admin login
		session_start();
		$_SESSION['admin_logged_in'] = true;
		$_SESSION['username'] = $admin_username;
		header("Location: admin.php");
		exit();
	}

	if (crypt($_POST['f_user'].$_POST['f_pass'], $upload_password) == $upload_password && $_POST['f_user'] == $upload_username)
	{
	//successful upload login
	session_start();
	$_SESSION['upload_logged_in'] = true;
	$_SESSION['username'] = $upload_username;
	header("Location: index.php");
	exit();
	}
}


//Username or password was incorrect at this point!
header("Location: authenticate.php?status=error");
exit();

?>
