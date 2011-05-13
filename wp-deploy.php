<?php
// Settings
$from = array(
	'host' => 'localhost',
	'dbServer' => 'localhost',
	'dbName' => 'dbName',
	'dbUsername' => 'dbUsername',
	'dbPassword' => 'dbPassword'
);

$to = array(
	'host' => 'domainName.com',
	'dbServer' => 'localhost',
	'dbName' => 'dbName',
	'dbUsername' => 'dbUsername',
	'dbPassword' => 'dbPassword',
	'ftpHost' => 'ftp.domainName.com',
	'ftpUsername' => 'ftpUsername',
	'ftpPassword' => 'ftpPassword',
	'ftpFolder' => '/public_html/'
	 // for ftpFolder, ensure forward slash is on the end
);

// Init
header('Content-type: text/html');

// Settings that are very unlikely to change
$charset = 'utf8'; // indicates the SQL script encoding
$zipFilename = 'wp-deploy.zip';
$dbFilename = 'wp-deploy.sql';
$uploadDir = 'wp-content/uploads';
$thisDir = getcwd();

// Functions
class Zipper extends ZipArchive {
	public function addDir($path, $topLevelPath) {
		$zipPath = substr($path, strlen($topLevelPath) - strlen($path));
		$this->addEmptyDir($zipPath);
		
		$nodes = glob($path . '/*');
		foreach ($nodes as $node) {
			if (is_dir($node)) {
				$this->addDir($node, $topLevelPath);
			} else if (is_file($node)) {
				$zipPath = substr($node, strlen($topLevelPath) - strlen($node));
				$this->addFile($node, $zipPath);
			}
		}
	}
}

function connectMySql($env, $charset) {
	$link = mysql_connect($env['dbServer'], $env['dbUsername'], $env['dbPassword']);
	mysql_set_charset($charset, $link);
	if (!$link) {
		die('Could not connect: ' . mysql_error());
	}
	
	$dbSelected = mysql_select_db($env['dbName'], $link);
	if (!$dbSelected) {
		die("Cannot select database $env[dbName]: " . mysql_error());
	}
	
	return $link;
}

function mysqlEscape($str) {
	if (get_magic_quotes_runtime()) return $str;
	return mysql_real_escape_string($str);
}

function processColumn($col) {
	// add backquotes & escapes string
	return '`' . mysqlEscape($col) . '`';
}

// Main
$host = strtolower(trim($_SERVER['HTTP_HOST']));
switch ($host) {
	case $from['host']:
		$link = connectMysql($from, $charset);
		
		// iterate all tables and save sql file
		$fp = fopen($dbFilename, 'w');
		$tables = mysql_query('show tables', $link);
		while ($tableRow = mysql_fetch_row($tables)) {
			$table = $tableRow[0];
			$tableSql = mysqlEscape($table);
			$createTableResult = mysql_query("SHOW CREATE TABLE `$tableSql`", $link);
			while ($row = mysql_fetch_assoc($createTableResult)) {
				fwrite($fp, "DROP TABLE IF EXISTS `$tableSql`;\n");
				fwrite($fp, $row['Create Table'] . ";\n");
				
				$getColumns = true;
				$cols = array();
				
				// create insert statement
				$result = mysql_query("SELECT * FROM $table", $link);
				while ($row = mysql_fetch_assoc($result)) {
					
					// get columns if first call
					if ($getColumns) {
						foreach($row as $col => $value) {
							$cols[] = $col;
						}
						fwrite($fp, "INSERT INTO `$table` (" . implode(', ', array_map('processColumn', $cols)) . ") VALUES\n");
						$getColumns = false;
					} else {
						fwrite($fp, ",\n");
					}
					
					// get values
					$firstTimeRow = true;
					fwrite($fp, '(');
					foreach($cols as $key) {
						if ($firstTimeRow) {
							$firstTimeRow = false;
						} else {
							fwrite($fp, ', ');
						}
						fwrite($fp, "'" . mysqlEscape($row[$key]) . "'");
					}
					fwrite($fp, ")");
				}
				
				if (!$getColumns) {
					fwrite($fp, ";\n");
				}
			}
		}
		fclose($fp);
		
		// create ZIP file
		$zip = new Zipper;
		$res = $zip->open($zipFilename, ZIPARCHIVE::OVERWRITE);
		if ($res === TRUE) {
			$paths = glob($thisDir . '/*');
			foreach ($paths as $path) {
				if (is_dir($path) && $path != $thisDir . '/_work') {
					$zip->addDir($path, $thisDir);
				} else if (is_file($path) && $path != $thisDir . '/deploy-wp.php' && $path != $thisDir . '/' . $zipFilename) {
					$zipPath = substr($path, strlen($thisDir) - strlen($path));
					$zip->addFile($path, $zipPath);
				}
			}
			$zip->close();
		} else {
			die("Zipping $zipFilename failed.");
		}
		
		// delete SQL file
		unlink($dbFilename);
		
		// set up FTP connection
		$ftp = ftp_connect($to['ftpHost']);
		$ftpLoginResult = ftp_login($ftp, $to['ftpUsername'], $to['ftpPassword']); 
		if (!$ftp || !$ftpLoginResult) { 
		    die('FTP connection failed!');
		}
		
		$upload = ftp_put($ftp, $to['ftpFolder'] . 'deploy-wp.php', 'deploy-wp.php', FTP_BINARY);
		$upload = ftp_put($ftp, $to['ftpFolder'] . 'deploy-wp.zip', 'deploy-wp.zip', FTP_BINARY);
		if (!$upload) { 
			echo "FTP upload has failed!";
			exit;
		}

		ftp_close($ftp);
		
		// redirect to deployment script
		header('Location: http://' . $to['host'] . '/deploy-wp.php');
		break;
		
	case $to['host']:
		// validate that zip filename exists
		if (!file_exists($zipFilename)) {
			die("The file $zipFilename doesn't exist to unzip.  Please ensure this file is uploaded.");
		}
		
		// unzip WordPress file
		$zip = new ZipArchive;
		$res = $zip->open($zipFilename);
		if ($res === TRUE) {
			$zip->extractTo('./');
			$zip->close();
		} else {
			die("Unzipping $zipFilename failed.");
		}
		
		// edit wp-config.php to put in new settings
		$config = file_get_contents('wp-config.php');
		$patterns = array(
			"/define\('DB_NAME', '\w*'\)/i",
			"/define\('DB_USER', '\w*'\)/i",
			"/define\('DB_PASSWORD', '\w*'\)/i",
			"/define\('DB_HOST', '\w*'\)/i"
		);
		$replacements = array(
			"define('DB_NAME', '$to[dbName]')",
			"define('DB_USER', '$to[dbUsername]')",
			"define('DB_PASSWORD', '$to[dbPassword]')",
			"define('DB_HOST', '$to[dbServer]')"
		);
		$config = preg_replace($patterns, $replacements, $config);
		file_put_contents('wp-config.php', $config);
		
		// run SQL script & update data for new server
		$link = connectMysql($to, $charset);
		
		$fileArr = file($dbFilename);
		
		$sql = '';
		foreach($fileArr as $line){
			$trimmed = trim($line);
			$firstTwoChars = substr($trimmed, 0, 2);
			if($trimmed != '' && $firstTwoChars != '/*' && $firstTwoChars != '--') {
				$sql .= $line;
				if (substr(trim($line), -1) == ';') {
					if (mysql_query($sql, $link)) {
						$sql = '';
					} else {
						die('Could not execute SQL file: ' . mysql_error()); 
					}
				}
			}
		}
		
		$result = mysql_query("SELECT * FROM wp_options WHERE option_name = 'siteurl'", $link);
		$row = mysql_fetch_assoc($result);
		$oldUrl = $row['option_value'];
		
		$uploadPath = getcwd() . '/' . $uploadDir;
		$currentUrl = 'http://' . $to['host'];
		mysql_query("UPDATE wp_options SET option_value = '$currentUrl' WHERE option_name in ('siteurl', 'home')", $link);
		mysql_query("UPDATE wp_options SET option_value = '$uploadPath' WHERE option_name = 'upload_path'", $link);
		mysql_query("UPDATE wp_posts SET post_content = REPLACE(post_content, '$oldUrl/', '$currentUrl/') WHERE post_content LIKE '%$oldUrl/%'", $link);
		
		mysql_close($link);

		// delete relevant files
		unlink($dbFilename);
		unlink($zipFilename);
		
		echo 'Backup & deployment successful!';
		break;
	default:
		echo "$host does not match any environment defined in this script!";
		break;
}
?>