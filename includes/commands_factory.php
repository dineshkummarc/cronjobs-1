<?php
//define( 'DEBUG_COMMANDS', TRUE );
if (!defined('DEBUG_COMMANDS')) define( 'DEBUG_COMMANDS', FALSE );

function monitorDevicesTimeout($params) {

	// Need to handle inuse and active
	$devs = getDevicesWithProperties(Array( 'properties' => Array("Link")));
	
	// echo "<pre>";
	// print_r($devs);
	$feedback = array();
	$params['callerID'] = $params['callerID'];
	foreach ($devs as $key => $props) {
		if (array_key_exists('linkmonitor', $props['Link'])) {
			if($props['Link']['linkmonitor'] == "INTERNAL" || $props['Link']['linkmonitor'] == "MONSTAT") {
				$params['deviceID'] = $key;
				$params['device']['previous_properties'] = $props;
				$properties['Link']['value'] = LINK_TIMEDOUT;
				$params['device']['properties'] = $properties;
		//		print_r($params);
				$feedback[] = updateDeviceProperties($params);
			}
		}
	}
	// print_r($feedback);
	// echo "</pre>";
	return $feedback;
}

function getGroup($params) {
	$groupID = $params['commandvalue'];
	$mysql = 'SELECT g.groupID as groupID, d.id as deviceID, typeID, inuse FROM ha_mf_device_group g 
					JOIN `ha_mf_devices` d ON g.deviceID = d.id 
					WHERE groupID = '.$groupID; 
	$groups = FetchRows($mysql);
	$feedback = array();
	foreach($groups as $device) {
		$feedback[]['groupselect']['DeviceID'] = $device['deviceID'];
	}
	return $feedback;
}

function createAlert($params) {

	$alert_textID = $params['alert_textID'];
	
	$params['priorityID']  = (array_key_exists('priorityID', $params) ? $params['priorityID'] : 'NULL');
	
	$rowtext = FetchRow("SELECT * FROM ha_alert_text where id =".$alert_textID);
	$subject = $rowtext['description'];
	$message  = $rowtext['message'];
	
if (DEBUG_ALERT) {
	echo "<pre>Alerts Params123: "; print_r($params); echo "</pre>";
}
	
	replaceText($subject, $message, $params);
		
	if ($params['priorityID'] != Null) $params['priorityID']= $rowtext['priorityID'];
	$params['caller']['deviceID'] = (array_key_exists('deviceID',$params['caller']) ? $params['caller']['deviceID'] : $params['caller']['callerID']);
	
	$feedback['message'] = 'AlertID: '.PDOInsert("ha_alerts", array('deviceID' => $params['caller']['deviceID'], 'description' => $subject, 'alert_date' => date("Y-m-d H:i:s"), 'alert_text' => $message, 'priorityID' => $params['priorityID'])).' created';
	
	return $feedback;
}

function executeMacro($params) {      // its a scheme, process steps. Scheme setup by a) , b) derived from remotekey

// Check conditions
	$schemeID = $params['schemeID'];
	$callerparams = $params['caller'];
	$loglevel = (array_key_exists('loglevel', $callerparams) ? $callerparams['loglevel'] : Null);
	$asyncthread = (array_key_exists('ASYNC_THREAD', $callerparams) ? $callerparams['ASYNC_THREAD'] : false);


	if (DEBUG_FLOW) echo "<pre>Enter executeMacro $schemeID".CRLF;
	if (DEBUG_FLOW) print_r($params);
	
	
	$mysql = 'SELECT * FROM `ha_remote_scheme_conditions` WHERE `schemesID` = '.$schemeID;
	
	if (!$rescond = mysql_query($mysql)) {
		mySqlError($mysql); 
		if (DEBUG_FLOW) echo "Exit executeMacro</pre>".CRLF;
		return false;
	}
	
	while ($rowcond = mysql_fetch_assoc($rescond)) {	
		$testvalue = array();
		switch ($rowcond['type'])
		{
		case SCHEME_CONDITION_DEVICE_PROPERTY_VALUE: 									// what a mess already :(
			if (DEBUG_FLOW) echo "SCHEME_CONDITION_DEVICE_PROPERTY_VALUE".CRLF;
			$condtype = "SCHEME_CONDITION_DEVICE_PROPERTY_VALUE";
			$testvalue[] = getDeviceProperties(Array('propertyID' => $rowcond['propertyID'], 'deviceID' => $rowcond['deviceID']))['value'];
			break;
		case SCHEME_CONDITION_GROUP_PROPERTY_AND:
		case SCHEME_CONDITION_GROUP_PROPERTY_OR:
			if (DEBUG_FLOW) echo "SCHEME_CONDITION_GROUP_PROPERTY_AND_OR".CRLF;
			$condtype = "SCHEME_CONDITION_GROUP_PROPERTY_AND_OR";
			if ($rowcond['type'] == SCHEME_CONDITION_GROUP_PROPERTY_AND) {
				$test = 1;
			} else {
				$test = 0;
			}
			$groups = getGroup(array('commandvalue' => $rowcond['groupID']));
			// [getGroup] => Array ([0] => Array ([groupselect] => Array ([DeviceID] => 1))
			foreach ($groups as $device) {
				if ($rowcond['type'] == SCHEME_CONDITION_GROUP_PROPERTY_AND) {
					$test = $test & getDeviceProperties(Array('deviceID' => $device['groupselect']['DeviceID'], 'propertyID' => $rowcond['propertyID']))['value'];
				} else {
					$test = $test | getDeviceProperties(Array('deviceID' => $device['groupselect']['DeviceID'], 'propertyID' => $rowcond['propertyID']))['value'];
				}
			}
			$testvalue[] = $test;
			break;
		case SCHEME_CONDITION_CURRENT_TIME: 
			if (DEBUG_FLOW) echo "SCHEME_CONDITION_CURRENT_TIME</p>";
			$condtype = "SCHEME_CONDITION_CURRENT_TIME";
			$testvalue[] = time();
			break;
		}

		if ($rowcond['value'] !== NULL) {
			switch (strtoupper($rowcond['value']))
			{
			case "ON":
				$testvalue[] = STATUS_ON;
				break;
			case "OFF":
				$testvalue[] = STATUS_OFF;
				break;
			default:
				switch ($rowcond['type'])
				{
				case SCHEME_CONDITION_CURRENT_TIME: 
					$temp = preg_split( "/([+-])/" , $rowcond['value'], -1, PREG_SPLIT_DELIM_CAPTURE);
					$temp[0] = strtoupper($temp[0]);
					if ($temp[0] == "DAWN" || $temp[0] == "DUSK") {
						if ($temp[0] == "DAWN") $temp[0] = getDawn();
						if ($temp[0] == "DUSK") $temp[0] = getDusk();
						if (isset($temp[1])) {
							$testvalue[] = strtotime("today $temp[0] $temp[1]$temp[2] minutes");
						} else {
							$testvalue[] = strtotime("today $temp[0]");
						}
					} else {
						$testvalue[] = strtotime("today $temp[0]");
					}
					break;
				default:
					$testvalue[] = $rowcond['value'];
					break;
				}
				break;
			}
		}
		switch ($rowcond['operator'])
		{
		case CONDITION_GREATER:
			if ($testvalue[0] <= $testvalue[1]) {
				if (DEBUG_FLOW) echo 'Fail: "'.$testvalue[0].'" > "'.$testvalue[1].'"'.CRLF;
				$feedback['Name'] = getSchemeName($schemeID);
				$feedback['message'] = $feedback['Name'].': Condition '.getProperty($rowcond['propertyID'])['description'].' Fail: "'.$testvalue[0].'" > "'.$testvalue[1].'" '.$condtype;
				if (DEBUG_FLOW) echo "Exit executeMacro</pre>".CRLF;
				return $feedback;
			}
			break;
		case CONDITION_LESS:
			if ($testvalue[0] >= $testvalue[1]) {
				if (DEBUG_FLOW) echo 'Fail: "'.$testvalue[0].'" < "'.$testvalue[1].'"'.CRLF;
				$feedback['Name'] = getSchemeName($schemeID);
				$feedback['message'] = $feedback['Name'].': Condition '.getProperty($rowcond['propertyID'])['description'].' Fail: "'.$testvalue[0].'" < "'.$testvalue[1].'" '.$condtype;
				if (DEBUG_FLOW) echo "Exit executeMacro</pre>".CRLF;
				return $feedback;
			}
			break;
		case CONDITION_EQUAL:
			if ($testvalue[0] != $testvalue[1]) {
				if (DEBUG_FLOW) echo 'Fail: "'.$testvalue[0].'" == "'.$testvalue[1].'"'.CRLF;
				$feedback['Name'] = getSchemeName($schemeID);
				$feedback['message'] = $feedback['Name'].': Condition '.getProperty($rowcond['propertyID'])['description'].' Fail: "'.$testvalue[0].'" == "'.$testvalue[1].'" '.$condtype;
				if (DEBUG_FLOW) echo "Exit executeMacro</pre>".CRLF;
				return $feedback;
			}
			break;
		}
		if (DEBUG_FLOW) echo "Condition Pass: condition value: ".$testvalue[0].", test for: ".$testvalue[1].CRLF;
	}
	
		
	//$callerparams['deviceID'] = (array_key_exists('deviceID', $callerparams) ? $callerparams['deviceID'] : $callerID);
	$mysql = 'SELECT ha_remote_schemes.name, ha_remote_schemes.runasync, ha_remote_scheme_steps.id, ha_mf_commands.description as commandName, ha_remote_scheme_steps.groupID, ha_remote_scheme_steps.deviceID, ha_remote_scheme_steps.commandID, ha_remote_scheme_steps.value,ha_remote_scheme_steps.runschemeID,ha_remote_scheme_steps.sort,ha_remote_scheme_steps.alert_textID 
	FROM ha_remote_schemes 
	JOIN ha_remote_scheme_steps ON ha_remote_schemes.id = ha_remote_scheme_steps.schemesID 
	LEFT JOIN ha_mf_commands ON ha_remote_scheme_steps.commandID = ha_mf_commands.id
	WHERE ha_remote_schemes.id ='.$schemeID.'.
	ORDER BY ha_remote_scheme_steps.sort';
	
	// Trap any async SCHEMES here
	if ($rowshemesteps = FetchRows($mysql)) {
		if (!$asyncthread && current($rowshemesteps)['runasync']) {
			$devstr = (array_key_exists('deviceID', $callerparams) ? "deviceID=".$callerparams['deviceID'] : "");
			$curlparams = "ASYNC_THREAD callerID=$callerparams[callerID] $devstr messagetypeID=MESS_TYPE_SCHEME schemeID=$schemeID";
			$cmd = 'nohup nice -n 10 /usr/bin/php -f '.getPath().'process.php '.$curlparams;
			$outputfile=  tempnam( sys_get_temp_dir(), 'async' );
			$pidfile=  tempnam( sys_get_temp_dir(), 'async' );
			exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $cmd, $outputfile, $pidfile));
			$feedback['Name'] = current($rowshemesteps)['name'];
			$feedback['error'] = "Spawned: ".$feedback['Name']."  Log:".$outputfile;
			if (DEBUG_FLOW) echo "Exit executeMacro</pre>".CRLF;
			return $feedback;		// GET OUT
		}
		foreach ($rowshemesteps as $step) {
			$feedback['Name'] = $step['name'];
			$feedback['executeMacro:'.$step['id'].'_'.$step['commandName']]=SendCommand(array( 'deviceID' => $step['deviceID'], 
						'commandID' => $step['commandID'], 'commandvalue' => $step['value'], 'schemeID' => $step['runschemeID'], 
						'alert_textID' => $step['alert_textID'], 'caller' => $callerparams));
			
		}
	} else {
		$feedback['error'] = 'No scheme steps found: '.$schemeID;
	}
	if (DEBUG_FLOW) echo "Exit executeMacro</pre>".CRLF;
	if (empty($feedback['message'])) unset($feedback['message']);
	return $feedback;
}

function getDuskDawn($params) {

	$station = $params['commandvalue'];
	
	$mydeviceID = DEVICE_DARK_OUTSIDE;
	ini_set('max_execution_time',30);

	$mydeviceID = array("USAL0594" => 196);
	//USAL0594

	$url = "https://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20weather.forecast%20where%20location%3D%22".$station.
	"%22%20and%20u%3D%22c%22&format=json&diagnostics=true&callback=";
	$get = restClient::get($url);
//	$response = file_get_contents($url);
	if (DEBUG_DUSKDAWN) echo "<pre>";
	//if (DEBUG_YAHOOWEATHER) echo "response: ".$response;
	$feedback['error'] = ($get->getresponsecode()==200 ? 0 : $get->getresponsecode());
    if (!$feedback['error']) {
		$result = json_decode($get->getresponse());
		$feedback['message'] =  json_encode(json_decode($get->getresponse(), true));
		//if (DEBUG_YAHOOWEATHER) print_r($result);
		if (DEBUG_DUSKDAWN) print_r($result);
		$result = $result->{'query'}->{'results'}->{'channel'};

		$tsr = date("H:i", strtotime($result->{'astronomy'}->{'sunrise'}));
		$tss = date("H:i", strtotime($result->{'astronomy'}->{'sunset'}));

		$device['previous_properties'] = getDeviceProperties(Array('deviceID' => DEVICE_DARK_OUTSIDE));

		$properties['Astronomy Sunrise']['value'] = $tsr;
		$properties['Astronomy Sunset']['value'] = $tss;
		$properties['Status']['value'] = $device['previous_properties']['Status']['value'];
		$properties['Link']['value'] = LINK_UP;
		$device['properties'] = $properties;
	} else {
		$properties['Status']['value'] = STATUS_ERROR;
		$properties['Link']['value'] = LINK_DOWN;
		$device['properties'] = $properties;
	}
	$feedback['updateDeviceProperties'] = updateDeviceProperties(array( 'callerID' => DEVICE_DARK_OUTSIDE, 'deviceID' => DEVICE_DARK_OUTSIDE, 'device' => $device));

	if (DEBUG_DUSKDAWN) echo "</pre>";
	return $feedback;
}

function setResult($params) {
	$feedback['message'] = $params['commandvalue'];
	break;
}


function setDevicePropertyCommand(&$params) {
	$tarr = explode("___",$params['commandvalue']);
	$text = $tarr[1];
	replacePlaceholder($text, Array('deviceID' => $params['deviceID']));
	if (strtoupper($text) == "TOGGLE") { 		// Toggle
		if ($params['device']['previous_properties'][$tarr[0]]['value'] == STATUS_ON) 
			$text = STATUS_OFF;
		else
			$text = STATUS_ON;
	}
	$params['device']['properties'][$tarr[0]]['value'] = $text;
	$feedback['Name'] = $tarr[0];
	return $feedback;
}

// Private
function NOP() {
	$feedback['message'] = "Nothing done";
	return $feedback;
}
?>
