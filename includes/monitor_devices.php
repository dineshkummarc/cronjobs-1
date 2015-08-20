<?php
function monitorDevices($linkmonitor) {
	$mysql = 'SELECT `ha_mf_devices`.`id` AS `deviceID` , `ha_mf_devices`.`monitortypeID` AS `monitortypeID` , `ha_mf_monitor_link`.`linkmonitor` AS `linkmonitor` , '.
			'`ha_mf_monitor_link`.`pingport` AS `pingport` FROM ha_mf_devices '.
			' LEFT JOIN `ha_mf_monitor_link` ON `ha_mf_devices`.`id` = `ha_mf_monitor_link`.`deviceID` '.
			' WHERE (`ha_mf_devices`.`inuse` = 1 AND `ha_mf_devices`.`monitortypeID` > 1 AND `linkmonitor` = "'.$linkmonitor.'")';
	
	if (!$reslinks = mysql_query($mysql)) {
		mySqlError($mysql); 
		return false;
	}
	while ($rowlinks = mysql_fetch_assoc($reslinks)) {	
		monitorDevice($rowlinks['deviceID'],$rowlinks['pingport'],$rowlinks['monitortypeID']);
	}
}

function monitorDevicesTimeout() {
	$mysql = 'SELECT `ha_mf_devices`.`id` AS `deviceID` , `ha_mf_devices`.`monitortypeID` AS `monitortypeID` , `ha_mf_monitor_link`.`linkmonitor` AS `linkmonitor` , '.
			'`ha_mf_monitor_link`.`pingport` AS `pingport` FROM ha_mf_devices '.
			' LEFT JOIN `ha_mf_monitor_link` ON `ha_mf_devices`.`id` = `ha_mf_monitor_link`.`deviceID` '.
			' WHERE (`ha_mf_devices`.`monitortypeID` > 1 AND `linkmonitor` = "INTERNAL" OR `linkmonitor` = "MONSTAT")';
	
	if (!$reslinks = mysql_query($mysql)) {
		mySqlError($mysql); 
		return false;
	}
	while ($rowlinks = mysql_fetch_assoc($reslinks)) {	
		$feedback = UpdateLink(array('caller' => $callerparams, 'deviceID' => $rowlinks['deviceID'], 'link' => LINK_TIMEDOUT));
	}
}

function monitorDevice($deviceID, $pingport, $montype) {
	$mysql = 'SELECT `ip`, `name` FROM `ha_mf_device_ipaddress` i JOIN `ha_mf_devices` d ON d.ipaddressID = i.id WHERE d.`id` = '.$deviceID;
	if (!$resip = mysql_query($mysql)) {
		mySqlError($mysql); 
		return false;
	}
	$rowip = mysql_fetch_assoc($resip);
	$status = false;
	if ($rowip['ip'] != NULL) {
		if ($pingport>0) {
			$status = pingip ($rowip['ip'],$pingport,2);
		} else {
			$status = pingtcp ($rowip['ip'],100);
		}
	}
	if ($status) {
		$curlink = LINK_UP;
		$statverb = "Online";
	} else {
		$curlink = LINK_DOWN;
		$statverb = "Offline";
	}

	echo date("Y-m-d H:i:s").": ".$rowip['name']." ".$rowip['ip']." is $statverb, Device: $deviceID".CRLF;
	UpdateLink (array('caller' => $callerparams, 'deviceID' => $deviceID, 'link' => $curlink, 'commandID' => COMMAND_PING));
}

function pingip($host, $port, $timeout)
{ 
	$tB = microtime(true); 
	$fP = @fSockOpen($host, $port, $errno, $errstr, $timeout); 
	if (is_resource($fP)) return true;
	return false; 
	//$tA = microtime(true); 
	//return round((($tA - $tB) * 1000), 0)." ms"; 
	//return true;
}

function pingtcp($host, $timeout)
{ 
	$tB = microtime(true); 
	$fP = exec("fping -t$timeout $host", $output, $status);
	/* print_r ($output);
	echo "</br>TCP status: $status</br>";
	echo "</br>TCP status: $fP</br>"; */
	if ($status==0) return true;
	return FALSE; 
}
?>
