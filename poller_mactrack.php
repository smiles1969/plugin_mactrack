#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2005 Larry Adams                                          |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
*/

/* do NOT run this script through a web browser */
if (!isset($_SERVER["argv"][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
die("<br><strong>This script is only meant to run at the command line.</strong>");
}

/* We are not talking to the browser */
$no_http_headers = true;

$dir = dirname(__FILE__);
chdir($dir);

if (substr_count(strtolower($dir), 'mactrack')) {
	chdir('../../');
}

/* Start Initialization Section */
include("./include/config.php");
include_once($config["base_path"] . "/lib/poller.php");
include_once($config["base_path"] . "/plugins/mactrack/lib/mactrack_functions.php");

/* get the mactrack polling cycle */
$max_run_duration = read_config_option("mt_collection_timing");

if (is_numeric($max_run_duration)) {
	/* let PHP a 5 minutes less than the rerun frequency */
	$max_run_duration = ($max_run_duration * 60) - 300;
	ini_set("max_execution_time", $max_run_duration);
}

/* get the max script runtime and kill old scripts */
$max_script_runtime = read_config_option("mt_script_runtime");
$delete_time = date("Y-m-d H:i:s", strtotime("-" . $max_script_runtime . " Minutes"));
db_execute("delete from mac_track_processes where start_date < '" . $delete_time . "'");

/* Disable Mib File Loading */
putenv("MIBS=RFC-1215");

if (read_config_option("mt_collection_timing") != "disabled") {
	/* initialize variables */
	$site_id = "";

	/* process calling arguments */
	$parms = $_SERVER["argv"];
	array_shift($parms);

	$debug = FALSE;
	$forcerun = FALSE;

	foreach($parms as $parameter) {
		@list($arg, $value) = @explode("=", $parameter);

		switch ($arg) {
		case "-sid":
			$site_id = $value;
			break;
		case "-d":
			$debug = TRUE;
			break;
		case "-h":
			display_help();
			exit;
		case "-f":
			$forcerun = TRUE;
			break;
		case "-v":
			display_help();
			exit;
		case "--version":
			display_help();
			exit;
		case "--help":
			display_help();
			exit;
		default:
			print "ERROR: Invalid Parameter " . $parameter . "\n\n";
			display_help();
			exit;
		}
	}

	mactrack_debug("About to enter MacTrack poller processing");
	$seconds_offset = read_config_option("mt_collection_timing");
	if (($seconds_offset <> "disabled") || $forcerun) {
		mactrack_debug("Into Processing.  Checking to determine if it's time to run.");
		$seconds_offset = $seconds_offset * 60;
		/* find out if it's time to collect device information */
		$base_start_time = read_config_option("mt_base_time");
		$database_maint_time = read_config_option("mt_maint_time");
		$last_run_time = read_config_option("mt_last_run_time");
		$last_db_maint_time = read_config_option("mt_last_db_maint_time");
		$previous_base_start_time = read_config_option("mt_prev_base_time");
		$previous_db_maint_time = read_config_option("mt_prev_db_maint_time");

		/* see if the user desires a new start time */
		mactrack_debug("Checking if user changed the start time");
		if (!empty($previous_base_start_time)) {
			if ($base_start_time <> $previous_base_start_time) {
				mactrack_debug("Detected that user changed the start time\n");
				unset($last_run_time);
				db_execute("DELETE FROM settings WHERE name='mt_last_run_time'");
			}
		}

		/* see if the user desires a new db maintenance time */
		mactrack_debug("Checking if user changed the maintenance time");
		if (!empty($previous_db_maint_time)) {
			if ($database_maint_time <> $previous_db_maint_time) {
				mactrack_debug("Detected that user changed the db maintenance time\n");
				unset($last_db_maint_time);
				db_execute("DELETE FROM settings WHERE name='mt_last_db_maint_time'");
			}
		}

		/* set to detect if the user cleared the time between polling cycles */
		db_execute("REPLACE INTO settings (name, value) VALUES ('mt_prev_base_time', '$base_start_time')");
		db_execute("REPLACE INTO settings (name, value) VALUES ('mt_prev_db_maint_time', '$database_maint_time')");

		/* determine the next start time */
		$current_time = strtotime("now");
		if (empty($last_run_time)) {
			if ($current_time > strtotime($base_start_time)) {
				/* if timer expired within a polling interval, then poll */
				if (($current_time - 300) < strtotime($base_start_time)) {
					$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time);
    			}else{
					$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time) + 3600*24;
				}
			}else{
				$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time);
			}
		}else{
			$next_run_time = $last_run_time + $seconds_offset;
		}
		$time_till_next_run = $next_run_time - $current_time;

		if ($time_till_next_run < 0) {
			mactrack_debug("The next run time has been determined to be NOW");
		}else{
			mactrack_debug("The next run time has been determined to be at '" . date("Y-m-d G:i:s", $next_run_time) . "'");
		}

		if (empty($last_db_maint_time)) {
			if (strtotime($database_maint_time) < $current_time) {
				$next_db_maint_time = strtotime(date("Y-m-d") . " " . $database_maint_time) + 3600*24;
			}else{
				$next_db_maint_time = strtotime(date("Y-m-d") . " " . $database_maint_time);
			}
		}else{
			$next_db_maint_time = $last_db_maint_time + 24*3600;
		}

		$time_till_next_db_maint = $next_db_maint_time - $current_time;
		if ($time_till_next_db_maint < 0) {
			mactrack_debug("The next database maintenance run time has been determined to be NOW");
		}else{
			mactrack_debug("The next database maintenance run time has been determined to be at '" . date("Y-m-d G:i:s", $next_db_maint_time) . "'");
		}

		if ($time_till_next_run < 0 || $forcerun == TRUE) {
			mactrack_debug("Either a scan has been forced, or it's time to check for macs");
			/* take time and log performance data */
			list($micro,$seconds) = split(" ", microtime());
			$start = $seconds + $micro;

			db_execute("REPLACE INTO settings (name, value) VALUES ('mt_last_run_time', '$current_time')");
			$running_processes = db_fetch_cell("SELECT count(*) FROM mac_track_processes");

			if ($running_processes) {
				cacti_log("ERROR: Can not start MAC Trackinging process.  There is already one in progress", TRUE);
			}else{
				collect_mactrack_data($start, $site_id);
				log_mactrack_statistics("collect");
			}
		}

		if ($time_till_next_db_maint < 0) {
			/* take time and log performance data */
			list($micro,$seconds) = split(" ", microtime());
			$start = $seconds + $micro;

			db_execute("REPLACE INTO settings (name, value) VALUES ('mt_last_db_maint_time', '$current_time')");
			perform_mactrack_db_maint();
			log_mactrack_statistics("maint");
		}
	}
}

/*	display_help - displays the usage of the function */
function display_help () {
	print "Network Mac Tracker Master Process Control Version 1.0, Copyright 2005 - Larry Adams\n\n";
	print "usage: poller_mactrack.php [-sid=site_id] [-d] [-h] [--help] [-v] [--version]\n\n";
	print "-sid=site_id  - the mac_track_sites site_id to scan\n";
	print "-f            - Force the execution of a collection process\n";
	print "-d            - Display verbose output during execution\n";
	print "-v --version  - Display this help message\n";
	print "-h --help     - display this help message\n";
}

function collect_mactrack_data($start, $site_id = 0) {
	global $max_run_duration, $config, $debug;

	/* reset the processes table */
	db_execute("TRUNCATE TABLE mac_track_processes");

	/* dns resolver binary */
	$resolver_launched = FALSE;

	if (read_config_option("mt_reverse_dns") == "on") {
		$dns_resolver_required = TRUE;
	}else{
		$dns_resolver_required = FALSE;
	}

	/* get php binary path */
	$command_string = read_config_option("path_php_binary");

	/* save the scan date information */
	$scan_date = date("Y-m-d H:i:s");
	db_execute("REPLACE INTO settings (name, value) VALUES ('mt_scan_date', '$scan_date')");

	/* just in case we've run too long */
	$exit_mactrack = FALSE;

	/* start mainline processing, order by site_id to keep routers grouped with switches */
	if ($site_id > 0) {
		$device_ids = db_fetch_assoc("SELECT device_id FROM mac_track_devices WHERE site_id='" . $site_id . "' and disabled=''");
	}else{
		$device_ids = db_fetch_assoc("SELECT device_id FROM mac_track_devices WHERE disabled='' ORDER BY site_id");
	}

	$total_devices = sizeof($device_ids);

	$concurrent_processes = read_config_option("mt_processes");

	if ($debug == TRUE) {
		$e_debug = " -d";
	}else{
		$e_debug = "";
	}

	/* add the parent process to the process list */
	db_process_add("-1");

	if ($total_devices > 0) {
		/* scan through all devices */
		$j = 0;
		$i = 0;
		$last_time = strtotime("now");
		$processes_available = $concurrent_processes;
		while ($j < $total_devices) {
			/* retreive the number of concurrent mac_track processes to run */
			/* default to 10 for now */
			$concurrent_processes = db_fetch_cell("SELECT value FROM settings WHERE name='mt_processes'");

			for ($i = 0; $i < $processes_available; $i++) {
				if (($j+$i) >= $total_devices) break;

				$extra_args = " -q " . $config["base_path"] . "/plugins/mactrack/mactrack_scanner.php -id=" . $device_ids[$i+$j]["device_id"] . $e_debug;
				mactrack_debug("CMD: " . $command_string . $extra_args);
				exec_background($command_string, $extra_args);
			}
			$j = $j + $i;

			/* launch the dns resolver if it hasn't been yet */
			if (($dns_resolver_required) && (!$resolver_launched)) {
				sleep(2);
				exec_background($command_string, " -q " . $config["base_path"] . "/plugins/mactrack/mactrack_resolver.php" . $e_debug);
				$resolver_launched = TRUE;
				mactrack_debug("DNS Resolver process launched");
			}

			mactrack_debug("A process cycle launch just completed.");

			/* wait the correct number of seconds for proccesses prior to
			   attempting to update records */
			sleep(2);
			$current_time = strtotime("now");
			if (($current_time - $last_time) > read_config_option("mt_dns_prime_interval")) {
				/* resolve some ip's to mac addresses to let the resolver knock them out */
				db_execute("UPDATE mac_track_temp_ports
							INNER JOIN mac_track_ips
							ON (mac_track_temp_ports.mac_address=mac_track_ips.mac_address
							AND mac_track_temp_ports.site_id=mac_track_ips.site_id)
							SET mac_track_temp_ports.ip_address=mac_track_ips.ip_address,
							mac_track_temp_ports.updated=1
							WHERE mac_track_temp_ports.updated=0");
				mactrack_debug("Interum IP addresses to MAC addresses association pass complete.");

				$last_time = $current_time;
			}

			$processes_running = db_fetch_cell("SELECT count(*) FROM mac_track_processes");

			if ($dns_resolver_required) {
				$processes_available = $concurrent_processes - $processes_running + 1;
			}else{
				$processes_available = $concurrent_processes - $processes_running;
			}

			/* take time to check for an exit condition */
			list($micro,$seconds) = split(" ", microtime());
			$current = $seconds + $micro;

			/* exit if we've run too long */
			if (($current - $start) > $max_run_duration) {
				$exit_mactrack = TRUE;
				cacti_log("ERROR: MacTracking timed out during main script processing.\n");
				db_process_remove("-1");
				break;
			}
		}

		/* wait for last process to exit */
		$processes_running = db_fetch_cell("SELECT count(*) FROM mac_track_processes WHERE device_id > 0");
		while (($processes_running > 0) && (!$exit_mactrack)) {
			$processes_running = db_fetch_cell("SELECT count(*) FROM mac_track_processes WHERE device_id > 0");

			/* wait the correct number of seconds for proccesses prior to
			   attempting to update records */
			sleep(2);
			$current_time = strtotime("now");
			if (($current_time - $last_time) > read_config_option("mt_dns_prime_interval")) {
				/* resolve some ip's to mac addresses to let the resolver knock them out */
				db_execute("UPDATE mac_track_temp_ports
							INNER JOIN mac_track_ips
							ON (mac_track_temp_ports.mac_address=mac_track_ips.mac_address
							AND mac_track_temp_ports.site_id=mac_track_ips.site_id)
							SET mac_track_temp_ports.ip_address=mac_track_ips.ip_address,
							mac_track_temp_ports.updated=1
							WHERE mac_track_temp_ports.updated=0");
				mactrack_debug("Interum IP addresses to MAC addresses association pass complete.");
			}

			/* take time to check for an exit condition */
			list($micro,$seconds) = split(" ", microtime());
			$current = $seconds + $micro;

			/* exit if we've run too long */
			if (($current - $start) > $max_run_duration) {
				$exit_mactrack = TRUE;
				cacti_log("ERROR: MacTracking timed out during main script processing.\n");
				break;
			}

			mactrack_debug("Waiting on " . $processes_running . " to complete prior to exiting.");
		}

		/* resolve some ip's to mac addresses to let the resolver knock them out */
		db_execute("UPDATE mac_track_temp_ports
					INNER JOIN mac_track_ips
					ON (mac_track_temp_ports.mac_address=mac_track_ips.mac_address
					AND mac_track_temp_ports.site_id=mac_track_ips.site_id)
					SET mac_track_temp_ports.ip_address=mac_track_ips.ip_address");
		mactrack_debug("Interum IP addresses to MAC addresses association pass complete.");

		/* populate the vendor_macs for this pass */
		db_execute("UPDATE mac_track_temp_ports SET vendor_mac=SUBSTRING(mac_address,1,8);");
		mactrack_debug("MAC addresses to Vendor MACS association pass complete.");

		/* update the vlan id's table */
		db_execute("UPDATE mac_track_vlans SET present='0'");
		db_execute("INSERT INTO mac_track_vlans (vlan_id, site_id, device_id, vlan_name, present) SELECT vlan_id, site_id, device_id, vlan_name, '1' AS present FROM mac_track_temp_ports ON DUPLICATE KEY UPDATE vlan_name=VALUES(vlan_name), present=VALUES(present);");
		db_execute("DELETE FROM mac_track_vlans WHERE present='0'");
		mactrack_debug("MAC VLAN's in VLAN Table Updated.");

		/* let the resolver know that the parent process is finished and then wait
		   for the resolver if applicable */
		db_process_remove("-1");

		while (!$exit_mactrack) {
			/* checking to see if the resolver is running */
			$resolver_running = db_fetch_row("SELECT * FROM mac_track_processes WHERE device_id = 0");

			if (sizeof($resolver_running) == 0) {
				break;
			}

			/* take time to check for an exit condition */
			list($micro,$seconds) = split(" ", microtime());
			$current = $seconds + $micro;

			/* exit if we've run too long */
			if (($current - $start) > $max_run_duration) {
				$exit_mactrack = TRUE;
				cacti_log("ERROR: MacTracking timed out during main script processing.\n");
				break;
			}
		}

		/* transfer temp port results into permanent table */
		db_execute("INSERT INTO mac_track_ports
					(site_id, device_id, hostname, dns_hostname, device_name,
					vlan_id, vlan_name, mac_address, vendor_mac, ip_address,
					port_number, port_name, scan_date)
					SELECT site_id, device_id, hostname, dns_hostname, device_name,
					vlan_id, vlan_name, mac_address, vendor_mac, ip_address,
					port_number, port_name, scan_date
					FROM mac_track_temp_ports");
		mactrack_debug("Finished transferring scan results to main table.");

		/* transfer the subnet information, although primative, into the ip_ranges table */
		$ip_ranges = db_fetch_assoc("SELECT SUBSTRING_INDEX(`ip_address`,'.',3) AS ip_range,
					site_id,
					count(ip_address) AS ips_current,
					scan_date AS ips_current_date
					FROM mac_track_temp_ports
					WHERE ip_address != ''
					GROUP BY ip_range, site_id");

		if (is_array($ip_ranges)) {
			foreach($ip_ranges as $ip_range) {
				$range_record = db_fetch_row("SELECT * FROM mac_track_ip_ranges WHERE ip_range='" . $ip_range["ip_range"] .
					"' AND site_id='" . $ip_range["site_id"] . "'");

				if (sizeof($range_record) == 0) {
					db_execute("REPLACE INTO `mac_track_ip_ranges`
						(ip_range, site_id, ips_current, ips_current_date)
						VALUES ('" .
						$ip_range["ip_range"] . "'," .
						$ip_range["site_id"] . ",'" .
						$ip_range["ips_current"] . "','" .
						$ip_range["ips_current_date"] . "')");
				}else{
					db_execute("UPDATE `mac_track_ip_ranges`
						SET ips_current='" . $ip_range["ips_current"] . "', " .
						"ips_current_date='" . $ip_range["ips_current_date"] . "'" .
						"WHERE ip_range='" . $range_record["ip_range"] . "' AND " .
						"site_id='" . $range_record["site_id"] . "'");
				}
			}
		}

		/* update the max values if required */
		db_execute("UPDATE `mac_track_ip_ranges`
					SET ips_max=ips_current, ips_max_date=ips_current_date
					WHERE ips_current > ips_max");

		/* collect statistics */
		if ($site_id == 0) {
			$stats = db_fetch_assoc("SELECT site_id,
							count(device_id) as total_devices,
							sum(ports_active) as total_oper_ports,
							sum(macs_active) as total_macs,
							sum(ports_total) as total_user_ports
							FROM mac_track_devices
							GROUP BY site_id");
		}else{
			$stats = db_fetch_assoc("SELECT site_id,
							count(device_id) as total_devices,
							sum(ports_active) as total_oper_ports,
							sum(macs_active) as total_macs,
							sum(ports_total) as total_user_ports
							FROM mac_track_devices
							WHERE site_id='$site_id'
							GROUP BY site_id");
		}

		/* collect total device errors */
		$errors = db_fetch_assoc("SELECT site_id, snmp_status, count(device_id) as total_device_errors
						FROM mac_track_devices
						GROUP BY site_id, snmp_status");

		$ips = array_rekey(db_fetch_assoc("SELECT site_id, count(ip_address) as total_ips
						FROM mac_track_ips
						GROUP BY site_id"), "site_id", "total_ips");

		foreach($errors as $error) {
			if (!isset($error_count[$error["site_id"]])) {
				$error_count[$error["site_id"]] = 0;
			}
			if ($error["snmp_status"] <> 3) {
				$error_count[$error["site_id"]] += $error["total_device_errors"];
			}
		}

		foreach($stats as $stat) {
			$num_ips = @$ips[$stat["site_id"]];
			if (empty($num_ips)) $num_ips = 0;

			$update_string = "UPDATE mac_track_sites SET " .
				"total_devices='" . $stat["total_devices"] . "', " .
				"total_ips='" . $num_ips . "', " .
				"total_macs='" . $stat["total_macs"] . "', " .
				"total_oper_ports='" . $stat["total_oper_ports"] . "', " .
				"total_user_ports='" . $stat["total_user_ports"] . "', " .
				"total_device_errors='" . $error_count[$stat["site_id"]] . "' " .
				"WHERE site_id='" . $stat["site_id"] . "'";

			db_execute($update_string);
		}
		mactrack_debug("Finished updating site table with collection statistics.");

		/* purge the ip address and temp port table */
		db_execute("TRUNCATE TABLE mac_track_temp_ports");
		db_execute("TRUNCATE TABLE mac_track_ips");
		db_execute("TRUNCATE TABLE mac_track_scan_dates");
		db_execute("REPLACE INTO mac_track_scan_dates (SELECT DISTINCT scan_date from mac_track_ports);");
	}else{
		cacti_log("NOTE: MACTRACK has no devices to process at this time\n");
	}
}

function log_mactrack_statistics($type = "collect") {
	global $start, $site_id;

	/* let's get the number of devices */
	if (is_numeric($site_id)) {
		$devices = db_fetch_cell("SELECT Count(*) FROM mac_track_devices WHERE site_id='$site_id'");
	}else{
		$devices = db_fetch_cell("SELECT Count(*) FROM mac_track_devices");
	}

	$concurrent_processes = read_config_option("mt_processes");

	/* take time and log performance data */
	list($micro,$seconds) = split(" ", microtime());
	$end = $seconds + $micro;

	if ($type == "collect") {
		$cacti_stats = sprintf(
			"Time:%01.4f " .
			"ConcurrentProcesses:%s " .
			"Devices:%s ",
			round($end-$start,4),
			$concurrent_processes,
			$devices);

		/* log to the database */
		db_execute("REPLACE INTO settings (name,value) VALUES ('stats_mactrack', '" . $cacti_stats . "')");

		/* log to the logfile */
		cacti_log("MACTRACK STATS: " . $cacti_stats ,true,"SYSTEM");
	}else{
		$cacti_stats = sprintf("Time:%01.4f", round($end-$start,4));

		/* log to the database */
		db_execute("REPLACE INTO settings (name,value) VALUES ('stats_mactrack_maint', '" . $cacti_stats . "')");

		/* log to the logfile */
		cacti_log("MACTRACK MAINT STATS: " . $cacti_stats ,true,"SYSTEM");
	}
}

?>