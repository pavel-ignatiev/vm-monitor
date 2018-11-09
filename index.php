<?php

# set debug options
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

# for time measurements
date_default_timezone_set('Europe/Berlin');

$wait = 1;

$msg_outfile = '/tmp/monitor-msg';

function my_exec( $cmd )
{
	exec( $cmd, $output, $return_value );
	if ( $return_value === 0) return TRUE;
	else return FALSE;
}

function select_cmd ( $service_label, $address, $wait )
{
	switch ($service_label) {
		case 'ping':
			return my_exec("ping -c 3 -W $wait $address");
			break;
		case 'ssh':
			return my_exec("nc -z -w $wait $address 22");
			break;
		case 'http':
			return my_exec("nc -z -w $wait $address 80");
			break;
		case 'mysql':
			return my_exec("nc -z -w $wait $address 3306");
			break;
		case 'backup':
			return TRUE;
			break;
		default:
			return FALSE;
			break;
	}
}

function check ( $service_label, $address, $wait, $target_state )
{
	$current_state = select_cmd( $service_label, $address, $wait );

	switch ($target_state) {

		# service must be responding
		case TRUE:
			if ($current_state) return array("state" => "up", "color" => "green");
			else return array("state" => "down", "color" => "red");
			break;

		# service must be inactive
		case FALSE:
			if ($current_state) return array("state" => "up", "color" => "red");
			else return array("state" => "down", "color" => "green");
			break;

		default:
			return 'ERROR';
	}	
}

function set_td_class ( $service_status_color )
{
	if ( $service_status_color === "green" ) return 'text-success text-uppercase';
	else return 'bg-danger text-white text-uppercase';
}

function check_msg ( $message, $file )
{
	if (file_exists($file)) $prev_msg = file_get_contents( $file );
	else $prev_msg = "";

	file_put_contents( $file, $message );

	$curr_lines = substr_count($message, "\n\r");
	$prev_lines = substr_count($prev_msg, "\n\r");

	# new errors found
	if ( $curr_lines > $prev_lines ) return TRUE;

	# shorter message but new errors found
	else if ( $curr_lines < $prev_lines AND strpos($prev_msg, $message) === false ) return TRUE;

	# everything OK
	else if ( $message === "" ) return FALSE;

	else return FALSE;

}

function send_mail ( $message, $address ) {
	$sender = 'monitor' . '@' . gethostname() . '.localdomain';
	$subject = strtok($message, "\n");	# first line of message
	mail( $address, $subject, $message, "From: VM monitor<$sender>");
}

include 'header.html';

# connect to MySQL database
$db = 'MYDB';
$dbhost = 'DBHOST';
$dsn = "mysql:dbname=$db;host=$dbhost";
$user = 'DBUSER';
$password = 'DBPASSWORD';

try {
	$pdo = new PDO($dsn, $user, $password);
} catch (PDOException $e) {
	echo '<div class="alert alert-danger">' . 'Connection failed: ' . $e->getMessage() . '</div>';
	include 'footer.html';
	die();
}

include 'table-header.html';

$message = "";

# loop over hosts
foreach ( $pdo->query("SELECT * FROM hosts;") as $row )
{
	$label = $row['label'];
	$address = $row['address'];

	echo '<tr>' . "\n\r";
	echo '	<td>' . $label . '</td>' . "\n\r";

	$services = array('ping', 'ssh', 'http', 'mysql', 'backup');

	# loop over services to be checked
	foreach( $services as $service_label ) {
		
		$target_state = filter_var( $row[$service_label], FILTER_VALIDATE_BOOLEAN );

		# make boolean values human readable
		if ($target_state) $target_state_view = 'UP';
		else $target_state_view = 'DOWN';

		# check if service available
		$service = check($service_label, $address, $wait, $target_state);

		# output html row
		echo '	<td class="' . set_td_class($service["color"]) . '">' . $service["state"] . '</td>' . "\n\r";

		# add message string
		if ($service["color"] === 'red') {
			$message .= $label . ": " . "Service " . $service_label . " is " . $service["state"] . ", should be " . $target_state_view . "\n\r";
		}
	}

	echo '</tr>' . "\n\r";
}

include 'table-footer.html';

# Send mail only if run from CLI
if ( php_sapi_name() === "cli" AND check_msg( $message, $msg_outfile ) ) {
		send_mail( $message, 'admin@email.address' );
}

include 'footer.html';

die();
