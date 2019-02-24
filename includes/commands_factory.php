<?php
// define( 'DEBUG_GRAPH', TRUE );
if (!defined('DEBUG_GRAPH')) define( 'DEBUG_GRAPH', FALSE );
define( 'MAX_DATAPOINTS', 1000 );

// @@TODO: DO NOT SEND MESSAGES TO KNOW OFFLINE DEVICES?
//
//	Command in:
// 		$params
//
//  Command out:
//		$feedback type Array
//			with keys: 
//						'Name'   		(String)	-> Name of executed command						REQUIRED
//						'result'		(Array)		-> Feedback nested calls						REQUIRED
//						'result_raw'	(Array)		-> result (Raw result for parsing last_result)
//						'message' 		(String)	-> To display on remote
//						'commandstr' 	(String)	-> for eventlog, actual command send
//      if error then	'error'			(String)	-> Error description
//						Nothing else allowed 
// function templateFunction(&$params) {

	// $feedback['Name'] = 'templateFunction';
	// $feedback['commandstr'] = "I send this";
	// $feedback['result'] = array();
	// $feedback['message'] = "all good";
	// if () $feedback['error'] = "Not so good";

	// if (DEBUG_COMMANDS) {
		// echo "<pre>".$feedback['Name'].': '; print_r($params); echo "</pre>";
	// }
	// return $feedback;
// }
function monitorDevicesTimeout($params) {
	// No Error checking

	// Need to handle inuse and active
	$devs = getDevicesWithProperties(Array( 'properties' => Array("Link")));

	// echo "<pre>";
	// print_r($devs);
	$feedback['Name'] = 'monitorDevicesTimeout';
	$feedback['result'] = array();
	$params['callerID'] = $params['callerID'];
	// $date = getdate();
	// $day = $date["wday"];	
	foreach ($devs as $key => $props) {
		// if (array_key_exists('linkmonitor', $props['Link']) && 
			// ($props['Link']['active'] == 1 || 
			// ($props['Link']['active'] == 2 && ($day > 0 && $day < 6 )) ||
			// ($props['Link']['active'] == 3 && ($day == 0 || $day == 6 )))) 
		// {
		if (array_key_exists('linkmonitor', $props['Link']) && 
			($props['Link']['active'] > 0)) 
		{
			if($props['Link']['linkmonitor'] == "INTERNAL" || $props['Link']['linkmonitor'] == "MONSTAT") {
				$params['deviceID'] = $key;
				$params['device']['previous_properties'] = $props;
				$properties['Link']['value'] = LINK_TIMEDOUT;
				$params['device']['properties'] = $properties;
				// print_r($params);
				$feedback['result'] = updateDeviceProperties($params);
			}
		}
	}
	// print_r($feedback);
	// echo "</pre>";
	return $feedback;
}

function createAlert($params) {

	$feedback['Name'] = 'createAlert';
	$feedback['result'] = array();
	if (DEBUG_COMMANDS) {
		echo "<pre>Alerts Params: "; print_r($params); echo "</pre>";
	}
	$feedback['commandstr'] = 'PDOInsert...';
	$params['caller']['deviceID'] = (array_key_exists('deviceID',$params['caller']) ? $params['caller']['deviceID'] : $params['caller']['callerID']);
	$feedback['result']['params'] = json_encode($params);
	$feedback['result'][] = 'AlertID: '.PDOInsert("ha_alerts", array('deviceID' => (empty($params['caller']['deviceID']) ? 0 : $params['caller']['deviceID']), 'description' => $params['mess_subject'], 'alert_date' => date("Y-m-d H:i:s"), 'alert_text' => $params['mess_text'], 'priorityID' => $params['priorityID'])).' created';
	if ($params['priorityID'] <= PRIORITY_HIGH) $feedback['result'][] = sendBullet($params);
	return $feedback;
}

function executeMacro($params) {      // its a scheme, process steps. Scheme setup by a) , b) derived from remotekey

// Check conditions
	$feedback['result'] = array();
	$schemeID = $params['schemeID'];
	$callerparams = $params['caller'];
	$loglevel = (array_key_exists('loglevel', $callerparams) ? $callerparams['loglevel'] : Null);
	$asyncthread = (array_key_exists('ASYNC_THREAD', $callerparams) ? $callerparams['ASYNC_THREAD'] : false);

	// Check if a commandvalue was given, if so save this for later use
	if (array_key_exists('commandvalue', $params) && !empty($params['commandvalue'])) {
		$params['macro___commandvalue'] = $params['commandvalue'];
	}

	if (DEBUG_COMMANDS) echo "<pre>Enter executeMacro $schemeID".CRLF;
	if (DEBUG_COMMANDS) print_r($params);

	$feedback['Name'] = getSchemeName($schemeID);

	$mysql = 'SELECT type as cond_type, groupID as cond_groupID, deviceID as cond_deviceID, propertyID as cond_propertyID,
				operator as cond_operator, value as cond_value 
				FROM `ha_remote_scheme_conditions` WHERE `schemesID` = '.$schemeID.' ORDER BY SORT';
	if ($rows = FetchRows($mysql)) {
		$feedback = checkConditions($rows, $params);
		// echo "<pre>Check cond result".CRLF;
		// print_r($rows);
		// print_r($feedback);
		if (!$feedback['result'][0]) {
			$feedback['Name'] = getSchemeName($schemeID);
			$feedback['message'] = $feedback['Name'].": ".$feedback['message'];
			return $feedback;
		}
	}


	//$callerparams['deviceID'] = (array_key_exists('deviceID', $callerparams) ? $callerparams['deviceID'] : $callerID);
	$mysql = 'SELECT sh.name, sh.runasync, st.id, c.description as commandName,  
				st.deviceID, st.propertyID, st.commandID, st.value,st.runschemeID, st.sort, st.alert_textID,
				st.cond_deviceID, st.has_condition as cond_type, NULL as cond_groupID, 
				"123" as cond_propertyID, st.cond_operator, st.cond_value,
				s2.runasync as step_async 
				FROM ha_remote_schemes sh
				JOIN ha_remote_scheme_steps st ON sh.id = st.schemesID 
				LEFT JOIN ha_mf_commands c ON st.commandID = c.id
				LEFT JOIN ha_remote_schemes s2 ON st.runschemeID = s2.id
				WHERE sh.id ='.$schemeID.'
				ORDER BY st.sort';
	//
	// Trap any async SCHEMES here 
	//		Trapping at first step after checking conditions. Nice for root level, however does not allow async in async
	//		Adding to check individual steps as well and spawn immediately
	//
	if ($rowshemesteps = FetchRows($mysql)) {
		if (!$asyncthread && current($rowshemesteps)['runasync']) {
			unset($values);
			$values['callerID'] = $callerparams['callerID'];
			$values['deviceID'] = (array_key_exists('deviceID', $callerparams) ? $callerparams['deviceID'] : "");
			$values['messagetypeID'] = "MESS_TYPE_SCHEME";
			$values['commandvalue'] = (array_key_exists('commandvalue', $callerparams) ? $callerparams['commandvalue'] : null);
			$values['schemeID'] = $schemeID;
			$getparams = http_build_query($values, '',' ');
			$cmd = 'nohup nice -n 10 /usr/bin/php -f '.getPath().'/process.php ASYNC_THREAD '.$getparams;
			$outputfile=  tempnam( sys_get_temp_dir(), 'async-M'.$schemeID.'-o-' );
			$pidfile=  tempnam( sys_get_temp_dir(), 'async-M'.$schemeID.'-p-' );
			exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $cmd, $outputfile, $pidfile));
			$feedback['message'] = "Initiated ".current($rowshemesteps)['name'].' sequence. Log: '.$outputfile;
			$feedback['commandstr'] = $cmd;
			if (DEBUG_COMMANDS) echo "Exit executeMacro</pre>".CRLF;
			return $feedback;		// GET OUT
		}
		
		foreach ($rowshemesteps as $step) {
			//$result = Array();

//			echo "<pre>Check cond result".CRLF;
//			print_r(array($step));
			$check_result = checkConditions(array($step), $params);
//			print_r($result);
//			echo "</pre>Check cond result".CRLF;
// echo '<pre>';
			if ($check_result['result'][0]) {
				$stepValue =  $step['value'];
				if (DEBUG_PARAMS) echo '<pre>StepValue: '.$stepValue.CRLF;
				if (DEBUG_PARAMS) echo 'last___message: '.(array_key_exists('last___message', $params) ? $params['last___message'] : 'Non-existent').CRLF;
				if (DEBUG_PARAMS) {
					if (array_key_exists('last___result', $params)) {
						echo 'last___result';
						var_dump($params['last___result']); 
					} else {
						echo 'last___result: Non-existent'.CRLF;
					}
				}
				$params['deviceID'] =  $step['deviceID'];
				$params['commandID'] = $step['commandID'];
				if (!empty($step['propertyID'])) $params['propertyID'] = $step['propertyID'];
				$params['schemeID'] = $step['runschemeID'];
				$params['alert_textID'] = $step['alert_textID'];

	 // {echo "<pre>before error ";print_r($params);echo "</pre>";}

				if ($params['deviceID'] == DEVICE_CURRENT_SESSION) {
					if (array_key_exists('SESSION', $params)) {
						$params['deviceID'] = $params['SESSION']['properties']['SelectedPlayer']['value'];
					} else if (array_key_exists('SESSION', $params['caller'])) {
						$params['deviceID'] = $params['caller']['SESSION']['properties']['SelectedPlayer']['value'];
					} else $params['SESSION']['properties']['SelectedPlayer']['value'] = DEVICE_DEFAULT_PLAYER;
				}

				$stepValue = replacePropertyPlaceholders($stepValue, $params);		// Replace placeholders in commandvalue
				if (DEBUG_PARAMS) echo 'StepValue after replacePropertyPlaceholders: '.$stepValue.CRLF;

				$stepValue = replaceCommandPlaceholders($stepValue, $params);		// Replace placeholders in commandvalue
				$params['commandvalue'] = $stepValue;
				splitCommandvalue($params);
				if (DEBUG_PARAMS) echo 'StepValue after replaceCommandPlaceholders: '.$params['commandvalue'].CRLF;
				if (DEBUG_PARAMS) echo 'Split after splitCommandvalue: '.print_r((array_key_exists('cvs',$params) ? $params['cvs'] : array("No params to split"))).CRLF;

				if ($step['step_async']) {			// Spawn it
					unset($values);
					$values['callerID'] = $callerparams['callerID'];
					$values['deviceID'] = (array_key_exists('deviceID', $callerparams) ? $callerparams['deviceID'] : "");
					$values['messagetypeID'] = "MESS_TYPE_SCHEME";
					$values['commandvalue'] = (array_key_exists('commandvalue', $params) ? $params['commandvalue'] : null);
					$values['schemeID'] = $params['schemeID'];
					$getparams = http_build_query($values, '',' ');
					
					$cmd = 'nohup nice -n 10 /usr/bin/php -f '.getPath().'/process.php ASYNC_THREAD '.$getparams;

					$outputfile=  tempnam( sys_get_temp_dir(), 'async' );
					$pidfile=  tempnam( sys_get_temp_dir(), 'async' );
					exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $cmd, $outputfile, $pidfile));
					$step_feedback['message'] = "Initiated ".$step['name'].' sequence. Log: '.$outputfile;
					$step_feedback['commandstr'] = $cmd;
					if (DEBUG_COMMANDS) echo "Spawned async step</pre>".CRLF;
				} else {
					$step_feedback = SendCommand($params);
				}
				// echo "****";print_r($params).CRLF;
				//echo "****";print_r($step_feedback).CRLF;
				if (array_key_exists('message',$step_feedback)) $params['last___message'] = $step_feedback['message'];
				if (array_key_exists('error',$step_feedback)) $params['last___message'] = $step_feedback['error'];
				if (array_key_exists('result_raw',$step_feedback)) $params['last___result'] = $step_feedback['result_raw'];
				if (array_key_exists('error',$step_feedback)) $params['last___result'] = $step_feedback['error'];
				if (DEBUG_PARAMS) echo 'Loaded last___message: >'.(array_key_exists('last___message', $params) ? $params['last___message'] : 'Non-existent').'<'.CRLF;
				if (DEBUG_PARAMS) {
					if (array_key_exists('last___result', $params)) {
						echo 'Loaded last___result';
						var_dump($params['last___result']); 
					} else {
						echo 'Non-existent'.CRLF;
					}
				}
				if (DEBUG_PARAMS) echo '</pre>';
			} 
			$feedback['result']['executeMacro:'.$step['id'].'_'.$step['commandName']] = $step_feedback;
		}
	} else {
		$feedback['error'] = 'No scheme steps found: '.$schemeID;
	}
	if (DEBUG_COMMANDS) echo "Exit executeMacro</pre>".CRLF;
	if (empty($feedback['message'])) unset($feedback['message']);
	// echo '</pre>';
	return $feedback;
}

function getDuskDawn(&$params) {

	$feedback['Name'] = 'getDuskDawn';
	$feedback['result'] = array();

	$station = $params['commandvalue'];
	ini_set('max_execution_time',30);
// echo "<pre>";
// print_r($params);

	$row = FetchRow("SELECT * FROM ha_mi_oauth20 where id ='YAHOO'");
	$credentials['method'] = $row['method'];
	$credentials['username'] = $row['clientID'];
	$credentials['password'] = $row['secret'];

	$url = "https://query.yahooapis.com/v1/yql";
	$args = array();
	$args["q"] = 'select * from weather.forecast where woeid in (12773052) and u="c"';
	// $args["diagnostics"] = "true";
	// $args["debug"] = "true";
	$args["format"] = "json";

	$get = RestClient::get($url,$args,$credentials,30);

	if (DEBUG_COMMANDS) echo "<pre>";
	//if (DEBUG_YAHOOWEATHER) echo "response: ".$response;
        $error = false;
        if ($get->getresponsecode()!=200) $error=true;
        if (!$error) {
                $result = json_decode($get->getresponse());
		if (DEBUG_COMMANDS) print_r($result);
                $feedback['result'] =  json_encode(json_decode($get->getresponse(), true),JSON_UNESCAPED_SLASHES);
                if (!isset($result->{'query'}->{'results'})) {
                        $error = true;
                } else {
			$result = $result->{'query'}->{'results'}->{'channel'};

			$tsr = date("H:i", strtotime(preg_replace("/:(\d) /",":0$1 ",$result->{'astronomy'}->{'sunrise'})));
			$tss = date("H:i", strtotime(preg_replace("/:(\d) /",":0$1 ",$result->{'astronomy'}->{'sunset'})));
			$properties['Astronomy Sunrise']['value'] = $tsr;
			$properties['Astronomy Sunset']['value'] = $tss;
			$properties['Link']['value'] = LINK_UP;
			$params['device']['properties'] = $properties;
			if (DEBUG_COMMANDS) print_r($params);
		}
	}
	if ($error) {
		$feedback['error'] = $get->getresponsecode();
 		$properties['Link']['value'] = LINK_DOWN;
		$params['device']['properties'] = $properties;
	}
	//$feedback['result']['updateDeviceProperties'] = updateDeviceProperties(array( 'callerID' => DEVICE_DARK_OUTSIDE, 'deviceID' => DEVICE_DARK_OUTSIDE, 'device' => $device));
	if (DEBUG_COMMANDS) echo "</pre>";
	return $feedback;
}

function setResult($params) {
	$feedback['Name'] = 'setResult';
	$feedback['result'] = array();
	$feedback['message'] = $params['commandvalue'];
	return;
}


function setDevicePropertyCommand(&$params) {
	$feedback['result'] = array();

	if (DEBUG_COMMANDS) { echo "<pre>"; print_r($params); echo "</pre>"; };

	calculateProperty($params) ;
	$tarr = explode("___",$params['commandvalue']);
	$text = $tarr[1];
	$text = replacePropertyPlaceholders($text, Array('deviceID' => $params['deviceID']));
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

function setSessionVar(&$params) {


	$feedback['result'] = array();
	
	// calculateProperty($params) ;
	
	$tarr = explode("___",$params['commandvalue']);
	$text = $tarr[1];
	$text = replacePropertyPlaceholders($text, Array('deviceID' => $params['deviceID']));
	if (strtoupper($text) == "TOGGLE") { 		// Toggle
		if ($params['device']['previous_properties'][$tarr[0]]['value'] == STATUS_ON) 
			$text = STATUS_OFF;
		else
			$text = STATUS_ON;
	}

	ini_set('session.use_only_cookies', false);
	ini_set('session.use_cookies', false);
	ini_set('session.use_trans_sid', false);
	ini_set('session.cache_limiter', null);	
	session_start();
	$_SESSION['properties'][$tarr[0]]['value'] = $text;
	session_write_close();

	$feedback['result'] = $_SESSION['properties'];
	$feedback['Name'] = $tarr[0];
	print_r($_SESSION);
	return $feedback;
}

function getNowPlaying(&$params) {

	$feedback['Name'] = 'getNowPlaying';
	$feedback['result'] = array();

 	$command['caller'] = $params['caller'];
	$command['callerparams'] = $params;
	$command['deviceID'] = $params['deviceID']; 
	$command['commandID'] = COMMAND_GET_VALUE;
	$result = sendCommand($command); 
	

	// echo "<pre>";	
	
	if (array_key_exists('error', $result)) {
		$properties['Playing']['value'] =  'Nothing';
		$properties['File']['value'] = '*';
		$properties['Artist']['value'] = '*';
		$properties['Title']['value'] =  '*';
		$properties['Thumbnail']['value'] = HOME."images/headers/offline.png?t=".rand();
		$properties['PlayingID']['value'] =  '0';
		$params['device']['properties'] = $properties;
		$feedback['error']='Error - Nothing playing';
	} else {
 // echo "<pre>";
 // print_r($result['result_raw']);
 		$result = $result['result_raw'];
		if (array_key_exists('artist', $result['result']['item']) && array_key_exists('0', $result['result']['item']['artist'])) {
			$properties['Playing']['value'] =  $result['result']['item']['artist'][0].' - '.$result['result']['item']['title'];
		} else {
			$properties['Playing']['value'] = substr($result['result']['item']['label'], 0, strrpos ($result['result']['item']['label'], "."));
		}
		if (!empty(trim($result['result']['item']['file']))) {
			$br = strpos( $properties['Playing']['value'] , ' - ');
			if ($br !== false) {
				$properties['Artist']['value'] = substr($properties['Playing']['value'], 0, $br);
				$properties['Title']['value'] =  substr($properties['Playing']['value'], $br + 3);
			}
			$properties['File']['value'] = $result['result']['item']['file'];
			$properties['Thumbnail']['value'] = $result['result']['item']['thumbnail'];
			$properties['PlayingID']['value'] =  $result['result']['item']['id'];
			$params['device']['properties'] = $properties;
			$feedback['message'] = $properties['Playing']['value'];
		} else {
			$properties['Playing']['value'] =  'Nothing';
			$properties['File']['value'] = '*';
			$properties['Artist']['value'] = '*';
			$properties['Title']['value'] =  '*';
			$properties['Thumbnail']['value'] = HOME."images/headers/offline.png?t=".rand();
			$properties['PlayingID']['value'] =  '0';
			$params['device']['properties'] = $properties;
			$feedback['error']='Error - Nothing playing';
		}
		// Handle KODI error
	}	
	return $feedback;
	// echo "</pre>";	
} 

function moveToRecycle($params) {

	$feedback['Name'] = 'moveToRecycle';
	$feedback['result'] = array();
	// echo "<pre>".$feedback['Name'].CRLF;
	// print_r($params);

	$cmvfile = mv_toLocal($params['commandvalue']);
	//$result = stat($infile);
	$fparsed = mb_pathinfo($cmvfile);
	$fparsed['dirname'] = rtrim($fparsed['dirname'], '/') . '/';
	$params['file'] = $fparsed ;
	$params['createbatchfile'] = 0;		// have to stay online, not returning $batchfile
	$params['movetorecycle'] = 1;
	$result = moveMusicVideo($params);
	$feedback['result'][] = $result;
	$command = array('callerID' => $params['caller']['callerID'], 
		'caller'  => $params['caller'],
		'deviceID' => $params['deviceID'], 
		'commandID' => COMMAND_RUN_SCHEME,
		'schemeID' => SCHEME_ALERT_KODI);
		
	if (!array_key_exists('error',$result)) {
		$command['macro___commandvalue'] = $result['message'];
	} else {
		$command['macro___commandvalue'] = $result['error'];
	}
	if (!array_key_exists('createbatchfile',$params)) $feedback['result'][] = sendCommand($command);
	return $feedback;
}
	
function moveMusicVideo($params) {

	$feedback['Name'] = 'moveMusicVideo';
	$feedback['result'] = array();
	$feedback['message'] = '';
	// echo "<pre>".$feedback['Name'].CRLF;
	// print_r($params);

	$file = $params['file'];
	if ($params['movetorecycle']) {
		$rand = rand(100000,999999);
		$file['moveto'] = LOCAL_MUSIC_VIDEOS.LOCAL_RECYCLE;
		$file['newname'] = $file['filename'].' ('.$rand.')';
	}
	if ($params['createbatchfile']) {
		$batchfile = $params['file']['batchfile'];
		if ($params['movetorecycle']) $batchfile .= '#Recycle'."\n";
		$batchfile .= '#From: '.$file['dirname'].$file['filename']."\n";
		$batchfile .= '#__To: '.$file['moveto'].$file['newname']."\n";
	}
	
	$matches = glob($file['moveto'].$file['newname'].'.*');
	if (strtolower($file['dirname'].$file['filename']) != strtolower($file['moveto'].$file['newname'])) {
		// echo "<pre>Matches ".$feedback['Name'].CRLF;
		// print_r($matches);
		if (!empty($matches)) {			// Assume we found an upgrade and move to recycle
			// Find vid match
			// echo "<pre>Found old one ".$feedback['Name'].' '.$dirname.$fparsed['basename'].CRLF;
			foreach ($matches as $match) {
				$fparsed = mb_pathinfo($match);
				if (!in_array($fparsed['extension'], Array("tbn", "nfo"))) {
					$feedback['message'] .= "***";
					$dirname = rtrim($fparsed['dirname'], '/') . '/';
					$params['commandvalue'] = mv_toPublic($dirname.$fparsed['basename']);
					$feedback[]['result'] = moveToRecycle($params);
				}
			}
		}
	}

	foreach (Array ($file['extension'], "tbn", "nfo") as $ext) {
		$filename = $file['filename'].'.'.$ext;
		$infile = $file['dirname'].$file['filename'].'.'.$ext;
		$tofile = $file['moveto'].$file['newname'].'.'.$ext;
		
		// echo "cp ".$infile.' '.$tofile.CRLF;
		if (!array_key_exists('error', $feedback)) {
			if (!$params['createbatchfile']) {
	// $cp=1;
				$copy = copy($infile, $tofile) ;
				// echo "$copy".CRLF;
				if ($copy) $unlink = unlink($infile);
				// echo "$unlink".CRLF;
				touch ($tofile);
				if (!in_array($ext, Array("tbn", "nfo"))) {
					if ($copy && $unlink) {
						$feedback['message'] .= $filename.'| moved to '.$tofile."\n";
						$mysql = 'SELECT mv.id FROM `xbmc_video_musicvideos` mv JOIN xbmc_path p ON mv.strPathID = p.id WHERE file = "'.mv_toPublic($infile).'";'; 
						// Remove from Kodi Lib
						if ($mvid = FetchRow($mysql)['id']) {
							$command['caller'] = $params['caller'];
							$command['callerparams'] = $params['caller'];
							$command['deviceID'] = 259;						// Will be back-end KODI now force Paul-PC
							$command['commandID'] = 374;					// removeMusicVideo
							$command['commandvalue'] = $mvid;
							$feedback[]['result'] = sendCommand($command);
						}
						// Refresh to directory
						if (!$params['movetorecycle']) {
							$command['caller'] = $params['caller'];
							$command['callerparams'] = $params;
							$command['deviceID'] = 		259;	// Will be back-end KODI now force Paul-PC
							$command['commandID'] = 	373;	// Scan Directory
							$command['commandvalue'] = mv_toPublic($file['moveto']);
							$result = sendCommand($command); 
							$feedback[]['result'] = $result;
						}
					} else {
						$feedback['error'] = 'Error moving '.$infile.' | to '.$tofile."\n";
					}
				}
			} else {
				if (strtolower($infile) != strtolower($tofile)) {
					$batchfile .= str_replace('`' ,"\`", 'mv -vn "'.$infile.'" "'.$tofile.'"'."\n");
				} else {
					// Trick to allow rename case (seems to be related to CIFS and duplicate inodes
					$batchfile .= str_replace('`' ,"\`", 'mv -vn "'.$infile.'" "'.$tofile.'.1"'."\n");
					$batchfile .= str_replace('`' ,"\`", 'touch "'.$tofile.'.1"'."\n");
					$batchfile .= str_replace('`' ,"\`", 'mv -vn "'.$infile.'.1" "'.$tofile.'"'."\n");
				}
				$feedback['batchfile'] = $batchfile;
			}
		}
	}
	$file = 'log/mv_videos.log';
	$log = file_get_contents($file);
	if (array_key_exists('error', $feedback)) 
		$log .= date("Y-m-d H:i:s").": Error: ".$feedback['error'];
	else 
		$log .= date("Y-m-d H:i:s").": Moved: ".$feedback['message'];
	file_put_contents($file, $log);
	return $feedback;
	// echo "</pre>";	
} 

function addToFavorites(&$params) {

//echo "<pre>";
//print_r($params);
	$feedback['Name'] = 'addToFavorites';
	$feedback['result'] = array();
 
	$file = LOCAL_PLAYLISTS.$params['macro___commandvalue'].'.m3u';
	$error = "";
	if (($playlist = file_get_contents($file)) !== false) {
		$playingfile = $params['device']['previous_properties']['File']['value'];
		$playing = $params['device']['previous_properties']['Playing']['value'];
		if (strpos($playlist, $playing) === false) {
			$playlist .= $playingfile."\n";
			if (file_put_contents($file, $playlist) === false) $error = "Could not write playlist ".$file.'|';
			$feedback['message'] = 'Added to - '.$params['macro___commandvalue'].'|'.$playing;
		} else {
			$feedback['message'] = 'Already part of - '.$params['macro___commandvalue'].'|'.$playing;
		}
	} else {
		$error = 'Could not open playlist: '.$params['macro___commandvalue'].'|';
	}
	if (!empty($error)) {
		$feedback['error'] = 'Could not open playlist - '.$params['macro___commandvalue'].'|';
	}
	return $feedback;
//echo "</pre>";	
} 

function fireTVreboot($params) {

	$feedback['Name'] = 'fireTVreboot';
	$feedback['result'] = array();
	$cmd = 'nohup nice -n 10 '.getPath().'/bin/fireTVreboot.sh';
	$outputfile=  tempnam( sys_get_temp_dir(), 'adb' );
	$pidfile=  tempnam( sys_get_temp_dir(), 'adb' );
	exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $cmd, $outputfile, $pidfile));
	$feedback['message'] = "Initiated ".$feedback['Name'].' sequence'.'  Log:'.$outputfile;
	return $feedback;		// GET OUT

} 

function fireTVnetflix($params) {

        $feedback['Name'] = 'fireTVnetflix';
        $feedback['result'] = array();
        $cmd = 'nohup nice -n 10 '.getPath().'/bin/fireTVnetflix.sh '.$params['device']['ipaddress']['ip'];
        $outputfile=  tempnam( sys_get_temp_dir(), 'adb' );
        $pidfile=  tempnam( sys_get_temp_dir(), 'adb' );
        exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $cmd, $outputfile, $pidfile));
        $feedback['message'] = "Initiated ".$feedback['Name'].' sequence'.'  Log:'.$outputfile;
        return $feedback;               // GET OUT

}

function fireTVkodi($params) {

        $feedback['Name'] = 'fireTVkodi';
        $feedback['result'] = array();
        $cmd = 'nohup nice -n 10 '.getPath().'/bin/fireTVkodi.sh '.$params['device']['ipaddress']['ip'];
        $outputfile=  tempnam( sys_get_temp_dir(), 'adb' );
        $pidfile=  tempnam( sys_get_temp_dir(), 'adb' );
        exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $cmd, $outputfile, $pidfile));
        $feedback['message'] = "Initiated ".$feedback['Name'].' sequence'.'  Log:'.$outputfile;
        return $feedback;               // GET OUT

}

function fireTVcamera($params) {

        $feedback['Name'] = 'fireTVcamera';
        $feedback['result'] = array();
        $cmd = 'nohup nice -n 10 '.getPath().'/bin/fireTVcamera.sh '.$params['device']['ipaddress']['ip'].' '.$params['commandvalue'];
        $outputfile=  tempnam( sys_get_temp_dir(), 'adb' );
        $pidfile=  tempnam( sys_get_temp_dir(), 'adb' );
        exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $cmd, $outputfile, $pidfile));
        $feedback['message'] = "Initiated ".$feedback['Name'].' sequence'.'  Log:'.$outputfile;
        return $feedback;               // GET OUT

}

function fireTVsleep($params) {

        $feedback['Name'] = 'fireTVsleep';
        $feedback['result'] = array();
        $cmd = 'nohup nice -n 10 '.getPath().'/bin/fireTVsleep.sh '.$params['device']['ipaddress']['ip'].' '.$params['commandvalue'];
        $outputfile=  tempnam( sys_get_temp_dir(), 'adb' );
        $pidfile=  tempnam( sys_get_temp_dir(), 'adb' );
        exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $cmd, $outputfile, $pidfile));
        $feedback['message'] = "Initiated ".$feedback['Name'].' sequence'.'  Log:'.$outputfile;
        return $feedback;               // GET OUT

}

function copyFile($params) {

        $feedback['Name'] = 'copyFile';
        $feedback['result'] = array();

        // echo "<pre>";
		// print_r($params['cvs']);
		//echo getcwd() ;
		//echo $_SERVER['DOCUMENT_ROOT'];
        // echo "</pre>";
		// copy(getPath().'/includes/offline.jpg', LASTIMAGEDIR. );
		if (!copy($params['cvs'][0], $params['cvs'][1])) {
			$errors= error_get_last();
			$feedback['error'] = "Error during copy: ".$errors['type']." ".$errors['message'];
		}

        $feedback['message'] = "Copy ".$params['cvs'][0].' to '.$params['cvs'][1];
        return $feedback;

}

function storeCamImage($params) {


	$feedback['result'] = array();
    $feedback['Name'] = 'storeCamImage';
 	$command['caller'] = $params['caller'];
	$command['callerparams'] = $params;
	$command['deviceID'] = $params['deviceID']; 
	$command['commandID'] = COMMAND_SNAPSHOT;
	$feedback['result'] = sendCommand($command); 
	
	if (isCLI()) {
		$offline = LASTIMAGEDIR.'/offline.jpg';
		$file = LASTIMAGEDIR.'/'.$params['device']['description'].'.jpg';
	} else {
		$offline = SERVER_LASTIMAGEDIR.'/offline.jpg';
		$file = SERVER_LASTIMAGEDIR.'/'.trim($params['device']['description']).'.jpg';
		$public_file = PUBLIC_LASTIMAGEDIR.'/'.urlencode(trim($params['device']['description']).'.jpg');
	}

	// echo "<pre>";
	// echo $public_file;
	// // echo $feedback['result']['result_raw'];
	// echo "</pre>";
	copy($offline,$file);

	if (file_put_contents($file, $feedback['result']['result_raw']) === false) {
		$feedback['error'] = "Error during copy: ".$errors['type']." ".$errors['message'];	
	}
    $feedback['result_raw'] = array('filename' => $public_file);
    $feedback['message'] = "Copy ".$params['command']['command'].' to '.$file;
	unset($feedback['result']['result'][0]);
	unset($feedback['result']['result_raw']);
	// echo "<pre>";
	// print_r($feedback);
	// // // echo $feedback['result']['result_raw'];
	// echo "</pre>";
    return $feedback;

}

function sendEmail(&$params) {

//echo "<pre>Email ";
//print_r($params);
//echo "</pre>";

	$feedback['Name'] = 'sendmail';
	$feedback['result'] = array();
	$feedback['result']['params'] = json_encode($params);
	$to = $params['device']['previous_properties']['Address']['value'];
	$fromname = SITE_NAME; 

	$headers = 'MIME-Version: 1.0' . "\r\n".
    'From: '.$fromname. "\r\n" .
    'Reply-To: '.'myvlohome@gmail.com'. "\r\n" .
    'X-Mailer: PHP/' . phpversion() . "\r\n" ;
	
	if(strlen($params['mess_text']) != strlen(strip_tags($params['mess_text']))) {
		$headers.= "Content-Type: text/html; \r\n"; 
	}
	
	$feedback['commandstr'] = 'mail('.$to.', '.$params['mess_subject'].',  '.$params['mess_text'].', '.$headers.')';
	if(!mail($to, $params['mess_subject'],  $params['mess_text'], $headers)) {
	    $feedback['error'] = "Mailer - error";
	    return $feedback;
	}
	else {
	    $feedback['message'] = 'Email to: '.$to.' Subj:'.$params['mess_subject'];
		return $feedback;
	}
}

function sendBullet(&$params) {

	$feedback['Name'] = 'sendBullet';
	$feedback['result'] = array();

// echo "<pre>";
// print_r($params);
// echo "</pre>";	

	if(strlen($params['mess_text']) != strlen(strip_tags($params['mess_text']))) {

		// $str = 'My long <a href="http://example.com/abc" rel="link">string</a> has any
			// <a href="/local/path" title="with attributes">number</a> of
			// <a href="#anchor" data-attr="lots">links</a>.';

		$dom = new DomDocument();
		$dom->loadHTML($params['mess_text']);
		$output = array();
		foreach ($dom->getElementsByTagName('a') as $item) {
		   $output[] = array (
			  'str' => $dom->saveHTML($item),
			  'href' => $item->getAttribute('href'),
			  'anchorText' => $item->nodeValue
		   );
		}
		$text = strip_tags($params['mess_text']);
	}

	// echo "<pre>";
	// print_r($params);
	
	// $feedback['message'] = 'Temp disabled';
	// return $feedback;

	try {
		$pb = new Pushbullet\Pushbullet(PUSHBULLET_TOKEN);
		if (!empty($output)) 
			$pb->channel(PUSH_CHANNEL)->pushLink($params['mess_subject'], $output[0]['href'], $text);
		elseif (!empty($params['cvs'][2])) { // No image dir
			$pb->channel(PUSH_CHANNEL)->pushFile($params['cvs'][2],null,$params['cvs'][0],$params['cvs'][1]);
		} else {
			$pb->channel(PUSH_CHANNEL)->pushNote($params['mess_subject'], $params['mess_text']);
		}
		

	} catch (Exception $e) {
		$feedback['error'] = 'Error: '.$e->getMessage();
		echo $e->getMessage().CRLF;
	}
	// echo "</pre>";
    return $feedback;

}

function sendInsteonCommand(&$params) {

	$feedback['Name'] = 'sendInsteonCommand';
	$feedback['result'] = array();

//echo "<pre>";
//print_r($params);
//echo "</pre>";

	global $inst_coder;
	if ($inst_coder instanceof InsteonCoder) {
	} else {
		$inst_coder = new InsteonCoder();
	}
	if (DEBUG_DEVICES) echo "commandvalue a".$params['commandvalue'].CRLF;
	if ($params['commandID'] == COMMAND_ON && $params['commandvalue'] == NULL) $params['commandvalue']= $params['onlevel'];
	if (DEBUG_DEVICES) echo "commandvalue b".$params['commandvalue'].CRLF;
	if ($params['commandvalue']>100) $params['commandvalue']=100;
	if (DEBUG_DEVICES) echo "commandvalue c".$params['commandvalue'].CRLF;
	if ($params['commandvalue']>0) $params['commandvalue']=255/100*$params['commandvalue'];
	if (DEBUG_DEVICES) echo "commandvalue d".$params['commandvalue'].CRLF;
	if ($params['commandvalue'] == NULL && $params['commandID'] == COMMAND_ON) $params['commandvalue']=255;		// Special case so satify the replace in on command
	$cv_save = $params['commandvalue'];
	$params['commandvalue'] = dec2hex($params['commandvalue'],2);
	if (DEBUG_DEVICES) echo "commandvalue ".$params['commandvalue'].CRLF;

	$tcomm = replaceCommandPlaceholders($params['command']['command'],$params);
	$params['commandvalue'] = $cv_save;

	if (DEBUG_DEVICES) echo "Rest deviceID ".$params['deviceID']." commandID ".$params['commandID'].CRLF;
	$url = setURL($params);
	$feedback['commandstr'] = $url.$tcomm.'=I=3';
	if (DEBUG_DEVICES) echo $url.CRLF;



	$numberOfAttempts = 10;
	$retry = 0;
	do {
        	if (PDOUpdate('ha_mi_connection', array('semaphore' => 1) , array('id' => $params['device']['connection']['id']))) {         // 1 = success got it
                	//echo "Got semaphore, get out".CRLF;
	                break;
	        } else {        // 0 = busy
	        	//echo "No semaphore, retry".CRLF;
        	        usleep(INSTEON_SLEEP_MICRO);
	                $retry ++;
	        }
	} while( $retry < $numberOfAttempts);

	// Send it anyway
    usleep(INSTEON_SLEEP_MICRO);
	$curl = restClient::get($url.$tcomm.'=I=3',null, setAuthentication($params['device']), $params['device']['connection']['timeout']);
	if ($curl->getresponsecode() != 200 && $curl->getresponsecode() != 204) 
		$feedback['error'] = $curl->getresponsecode().": ".$curl->getresponse();
	else
		$feedback['result'][] = $curl->getresponse();

	// reset SEMAPHORE
        PDOUpdate('ha_mi_connection', array('semaphore' => 0) , array('id' => $params['device']['connection']['id']));


	if (array_key_exists('error', $feedback)) {
		if ($params['dimmable'] == "YES") {
			if (!is_null($params['commandvalue'])) $params['device']['properties']['Level']['value'] = $params['commandvalue'];
		}
	}
	return $feedback;
}

function sendGenericPHP(&$params) {
// TODO: Result not arrays?
	//$feedback['result'] = array();

	$func = $params['command']['command'];
	if ($func == "sleep") {
		$feedback['result'][] = $func($params['commandvalue']);
	} else {
		$feedback = $func($params);
	}
	return $feedback;
}


function sendGenericHTTP(&$params) {

	$targettype = $params['device']['connection']['targettype'];
	$feedback['Name'] = 'sendGenericHTTP - '.$targettype;
	$feedback['result'] = array();

	if (DEBUG_COMMANDS) echo "<pre>Generic HTTP >";
	if (DEBUG_COMMANDS) print_r($params);
	if (DEBUG_COMMANDS) echo "</pre>".CRLF;

	switch ($params['command']['http_verb'])
	{
	case "GET": 
		$targettype = "GET";
		break;
	case "PUT": 
		$targettype = "PUT";
	case "POST":
		break;
	case "PATCH":
		$targettype = "PUT";
	case "DELETE":
		$targettype = "DELETE";
		break;
	default: 
		break;
	}		
	
	
	switch ($targettype)
	{
	case "POSTAPP":          // PHP - vlosite
	case "POSTTEXT":         // Yahama AV & IrrigationCaddy at the moment
	case "POSTURL":          // Web Arduino/ESP8266
	case "JSON":             // Wink
	case "PUT":           // Dexa
	case "DELETE":           // Dexa
		if (DEBUG_COMMANDS) echo $targettype."</p>";
		$tcomm = replaceCommandPlaceholders($params['command']['command'],$params);
		$tmp1 = explode('?', $tcomm);
		$morepage = null;
		if (array_key_exists('1', $tmp1)) { 	// found '?' inside command then take page from command string and add to url
			$morepage = $tmp1[0];
			array_shift($tmp1);
			$tcomm = implode('?',$tmp1);
		} 
		$url = setURL($params, $morepage);
		if (DEBUG_COMMANDS) echo $url." Params: ".htmlentities($tcomm).CRLF;
		if ($targettype == "POSTTEXT") { 
			$feedback['commandstr'] = $url.' '.htmlentities($tcomm);
			$curl = restClient::post($url, $tcomm, setAuthentication($params['device']), "text/plain", $params['device']['connection']['timeout']);
		} elseif ($targettype == "POSTAPP") {
			$feedback['commandstr'] = $url.' '.$tcomm;
			$curl = restClient::post($url, $tcomm, setAuthentication($params['device']), "application/x-www-form-urlencoded", $params['device']['connection']['timeout']);
		} elseif ($targettype == "JSON") {
			//parse_str($tcomm, $params);
			$postparams = $tcomm;
			if (DEBUG_COMMANDS) echo $url." Params: ".$postparams.CRLF;
			$feedback['commandstr'] = $url.' '.$postparams;
			$curl = restClient::post($url, $postparams, setAuthentication($params['device']), "application/json" , $params['device']['connection']['timeout']);
		} elseif ($targettype == "PUT") {
			//parse_str($tcomm, $params);
			$postparams = $tcomm;
			if (DEBUG_COMMANDS) echo $url." Params: ".$postparams.CRLF;
			//echo $url." Params: ".$postparams.CRLF;
			$feedback['commandstr'] = $url.' '.$postparams;
			$curl = restClient::put($url, $postparams, setAuthentication($params['device']), "application/json" , $params['device']['connection']['timeout']);
		} elseif ($targettype == "DELETE") {
			//parse_str($tcomm, $params);
			$postparams = $tcomm;
			if (DEBUG_COMMANDS) echo $url." Params: ".$postparams.CRLF;
			//echo $url." Params: ".$postparams.CRLF;
			$feedback['commandstr'] = $url.' '.$postparams;
			$curl = restClient::delete($url, null, setAuthentication($params['device']), "application/json" , $params['device']['connection']['timeout']);
		} else { 
			$feedback['commandstr'] = $url.$tcomm;
			$curl = restClient::post($url.$tcomm ,"" ,setAuthentication($params['device']) ,"" ,$params['device']['connection']['timeout']);
		}
		if ($curl->getresponsecode() != 200 && $curl->getresponsecode() != 201 && $curl->getresponsecode() != 204) 
			$feedback['error'] = $curl->getresponsecode().": ".$curl->getresponse();
		else 
			if ($targettype == "JSON" || $targettype == "PUT") {
				$feedback['result_raw'] = json_decode($curl->getresponse(), true);
				$feedback['result'][] = json_decode($curl->getresponse(), true);
			} else {
				$feedback['result_raw'] = $curl->getresponse();
				$feedback['result'][] = htmlentities($feedback['result_raw']);
			}
			if (!is_array($feedback['result'])) $feedback['result'][] = $feedback['result'];
			//if (array_key_exists('message',$feedback) && $feedback['message'] == "\n[]") unset($feedback['message']); //  TODO:: Some crap coming back from winkapi, fix later
		break;
	case "GET":          // Sony Cam at the moment
		if (DEBUG_COMMANDS) echo "GET</p>";
		$tcomm = replaceCommandPlaceholders($params['command']['command'],$params);
		$url= setURL($params);
		if (DEBUG_COMMANDS) echo $url.$tcomm.CRLF;
		$feedback['commandstr'] = $url.implode('/', array_map('rawurlencode', explode('/', $tcomm)));
		// echo "<pre>";
		// echo $url.implode('/', array_map('rawurlencode', explode('/', $tcomm)));
		// echo "</pre>";
		$curl = restClient::get($url.$tcomm, null, setAuthentication($params['device']), $params['device']['connection']['timeout']);
		if ($curl->getresponsecode() != 200 && $curl->getresponsecode() != 204)
			$feedback['error'] = $curl->getresponsecode().": ".$curl->getresponse();
		else 
			$feedback['result_raw'] = $curl->getresponse();
			$feedback['result'][] = $curl->getresponse();
		break;
	case "TCP":              // iTach (Only \r)
		if (DEBUG_COMMANDS) echo "TCP_IR</p>";
		//print_r($params);
		$url = setURL($params);
		$feedback['commandstr'] = $url.$params['command']['command']."\r";
		if (empty($params['device']['connection']['targetaddress'])) {
			$ipaddress = $params['device']['ipaddress']['ip'];
		} else {
			$ipaddress = $params['device']['connection']['targetaddress'];
		}
		if (DEBUG_COMMANDS) echo $ipaddress.':'.$params['device']['connection']['targetport'].' - '.$feedback['commandstr'].CRLF;
		// open a client connection
		$client = stream_socket_client('tcp://'.$ipaddress.':'.$params['device']['connection']['targetport'], $errno, $errorMessage, $params['device']['connection']['timeout']);
		if ($client === false) {
			echo $errno.' '.$errorMessage;
			$feedback['error'] = "Failed to connect: $errorMessage";
		} else {
			stream_set_timeout($client, $params['device']['connection']['timeout']);
			if ($targettype == "TCP") { 
				$binout = $feedback['commandstr'];
			} else {
				$binout = base64_decode($feedback['commandstr']);
			}
			fwrite($client, $binout);
			$feedback['result'][] = stream_get_line ( $client , 1024 , "\r" );	
			fclose($client);
			//echo "**>**".print_r($feedback['result'])."**<**";
		}
		// TODO:: Error handling (GCache errors)
		// completeir,1:1,2
		//if ($feedback['result'] != "ERR", or busy...
		break;
	case "TCP64":          // TP-Link
		if (DEBUG_COMMANDS) echo "TP-Link</p>";
		if (empty($params['device']['connection']['targetaddress'])) {
			$ipaddress = $params['device']['ipaddress']['ip'];
		} else {
			$ipaddress = $params['device']['connection']['targetaddress'];
		}
		if (DEBUG_COMMANDS) echo $ipaddress.':'.$params['device']['connection']['targetport'].' - '.$params['command']['command'].CRLF;
		// open a client connection
//		$p="AAA0QcNAAEAGY0DAqAoawKgKVicP0VZB4a7Q3VhsD4ARC1AJYAAAAQEICgAP780AL6k=";
		//decodeTPLink(base64_decode($feedback['commandstr']));
		$feedback['commandstr'] = "tcp64://".$ipaddress.":".$params['device']['connection']['targetport']."/".$params['command']['command'];
		$feedback['result'][] = sendtoplug($ipaddress, $params['device']['connection']['targetport'], $params['command']['command'], $params['device']['connection']['timeout']);
//		$feedback['result'][] = sendtoplug($ipaddress, $params['device']['connection']['targetport'], $p, $params['device']['connection']['timeout']);
//		print_r($feedback['result']);
		break;
	case null:
	case "NONE":          // Virtual Devices
		if (DEBUG_COMMANDS) echo "DOING NOTHING</p>";
		break;
	}
	
	foreach ($feedback['result'] as $key => $value) {	
		if (!is_array($value) && strtoupper($value) == "OK") $feedback['result'][$key]= "";
	}
//	 echo "<pre>";
//	 echo "***";
//	 print_r($feedback);
//	 echo "</pre>";
	
	return $feedback;
}

function calculateProperty(&$params) {

	$feedback['Name'] = 'calculateProperty';
	//$feedback['commandstr'] = "I send this";
	$feedback['result'] = array();

	if (DEBUG_COMMANDS) {echo "<pre>".$feedback['Name'].': '; print_r($params); echo "</pre>";}

	// 	{calculate___{property_position}+1
	if (preg_match("/\{calculate___(.*?)\}/", $params['commandvalue'], $matches)) {
		if (DEBUG_COMMANDS) {echo "<pre> calculate "; print_r ($matches); echo "</pre>";}
		$calcvalue = eval('return '.$matches[1].';');
		$params['commandvalue'] = str_replace($matches[0], $calcvalue, $params['commandvalue']);
	}

	// $feedback['message'] = "all good";
	// if () $feedback['error'] = "Not so good";

	return $feedback;
}

// Private
function NOP() {
	$feedback['result'] = "Nothing done";
	return $feedback;
}

function graphCreate($params) {
	if (DEBUG_GRAPH) echo "<pre>params: ";
	if (DEBUG_GRAPH) print_r($params);
	parse_str(urldecode($params['commandvalue']), $fparams);
	if (DEBUG_GRAPH) print_r($fparams);

	if (!array_key_exists('0', $fparams['fabrik___filter']['list_231_com_fabrik_231']['value'])) {
		$feedback['error']="No Device selected";
		return $feedback;
	}
	$devices = implode(",",$fparams['fabrik___filter']['list_231_com_fabrik_231']['value']['0']);
	foreach ($fparams['fabrik___filter']['list_231_com_fabrik_231']['value']['0'] as $deviceID) {
		$caption[] = FetchRow('select description from ha_mf_devices where id='.$deviceID)['description'];
	}
	if (!array_key_exists('1', $fparams['fabrik___filter']['list_231_com_fabrik_231']['value'])) {
		//$result['error']="No Device selected";
		//return $result;
		$result = listDeviceProperties($devices);
		$result = array_unique($result, SORT_NUMERIC);
		if (DEBUG_GRAPH) print_r($result);
		$properties = implode(",", $result);
	} else {
		$properties = implode(",",$fparams['fabrik___filter']['list_231_com_fabrik_231']['value']['1']);
	}

	if (empty($fparams['fabrik___filter']['list_231_com_fabrik_231']['value']['2']['0'])) {
		$startdate = date( 'Y-m-d 00:00:00', strtotime("-1 days"));
		$enddate = date( 'Y-m-d 23:59:59', strtotime("tomorrow"));
	} else {
		$startdate = $fparams['fabrik___filter']['list_231_com_fabrik_231']['value']['2']['0'];
		$enddate = $fparams['fabrik___filter']['list_231_com_fabrik_231']['value']['2']['1'];
		$startdate = date( 'Y-m-d 00:00:00', strtotime($startdate));
		$enddate = date( 'Y-m-d 23:59:59', strtotime($enddate));
	}

	$debug = 1;
	if (DEBUG_GRAPH) {
		echo $devices.CRLF;
		echo $properties.CRLF;
		echo $startdate.CRLF; 
		echo $enddate.CRLF; 
	}

	//call sp_properties( '60,114,201', '138,126,123,127,124', "2015-09-25 00:00:00", "2015-09-27 23:59:59", 1) 
	$mysql='call sp_properties( "'.$devices.'", "'.$properties.'", "'.$startdate.'" , "'.$enddate.'",'.(DEBUG_GRAPH ? 1 : 0).');';
	if (DEBUG_GRAPH) echo $mysql.CRLF;
	$feedback['result'] = array();
	$feedback['message'] = '</div>';	// close system message frame
	if ($rows = FetchRows($mysql)) {
		//print_r($rows);

		// global $mysql_link;
		// mysql_close($mysql_link);
		// openMySql();


		$mysql='SELECT * FROM ha_mi_properties_graph WHERE propertyID IN ('.$properties.');';
		$rowsprops = FetchRows($mysql);

		$tablename="graph_0";
		$hidden = (DEBUG_GRAPH ? '' : ' hidden ');

		if (count($rows)> MAX_DATAPOINTS) {
		//
		// Average datapoint 
		// 		Average point 
// echo "<pre>";
// echo count($rows)."   ".round(count($rows)/MAX_DATAPOINTS);
// print_r($rows);
			$average_count = round(count($rows)/MAX_DATAPOINTS);
			$avg_loop = 0;
			$avg_temp = array();
			foreach($rows as $item)
			{
				if ($avg_loop < $average_count) {	// Store value
					$avg_temp[] = $item;
					$avg_loop++;
				} else {							// Average and add to output
// print_r($avg_temp);
					$avgArray = array();
					foreach ($avg_temp as $k=>$subArray) {		// Sum in $avgArray
						foreach ($subArray as $key=>$value) {
							if ($key == 'id') {
								$avgArray[$key] = $value;
								$avgCount[$key] = 0;
							} elseif ($key == 'Date') {
								$avgArray[$key] = $value;
								$avgCount[$key] = 0;
							} else {
								if (strlen(trim($value))) {			// Non empty 
									if (array_key_exists($key, $avgArray)) {
											$avgArray[$key] = $avgArray[$key] + $value;
											$avgCount[$key]++;
									} else {
										$avgArray[$key] = $value;
										$avgCount[$key] = 1;
									}
								} else {
									if (!array_key_exists($key, $avgArray)) {		// if first one found then store space
										$avgArray[$key] = $value;
										$avgCount[$key] = 0;
									}
								}
							}
						}
					}
					foreach ($avgArray as $key=>$value) {
						if ($avgCount[$key]==0) {
							$avgArray[$key] = $value;
						} else {
							$avgArray[$key] = ($value/$avgCount[$key]);
						}
					}
// print_r($avgArray);
// print_r($avgCount);
					$avg_rows[] = $avgArray;
					$avg_temp = array();
					$avg_loop = 0;
				}
			}
// print_r($avg_rows);
// echo "</pre>";
			// $rows = array_slice($rows, -MAX_DATAPOINTS, count($rows) ); 
			// $s = $rows[0]['Date'];
			// $e = $rows[count($rows)-1]['Date'];
			$feedback['message'] .= '<p class="badge badge-info">Too much data; Averaging over: '.$average_count.' rows<p>';
			$rows = $avg_rows;
		}
		$s = $rows[0]['Date'];
		$e = $rows[count($rows)-1]['Date'];
		//	echo (int)(abs(strtotime($e)-strtotime($s))/300); //60*5 min intervals = 300?
		$tickinterval=(abs(strtotime($e)-strtotime($s)))*100; 
		//var_dump ($tickinterval);
		$tickinterval=roundUpToAny(abs(strtotime($e)-strtotime($s))*50,3600000);
		//var_dump($tickinterval);
		//
		//echo $tickinterval.CRLF;
		//echo count($rows).CRLF;
		$feedback['message'] .= '<table id="'.$tablename.'" class="'.$tablename.$hidden.' table table-striped table-hover" data-graph-xaxis-type="datetime" data-graph-yaxis-2-opposite="1" 
				data-graph-xaxis-tick-interval="'.$tickinterval.'" data-graph-xaxis-align="right" data-graph-xaxis-rotation="270" data-graph-type="spline" 
				data-graph-container-before="1" data-graph-zoom-type="x" data-graph-height="500" >';
		//data-graph-xaxis-type="datetime"

		if (DEBUG_GRAPH) {echo "RowsProps:"; print_r($rowsprops);}

		$feedback['message'] .= '<caption>'.implode(", ",$caption).'</caption>';
		$feedback['message'] .= '<thead><tr class="fabrik___heading">';
		foreach($rows[0] as $header=>$value){
			if ($header != "id") {
				$datastr="";
				if ($header != "Date") {
					//print_r($rows[0]);
					$t = explode('`',$header);
					$propID = getProperty($t[0])['id'];
					if (($prodIdx = findByKeyValue($rowsprops,'propertyID',$propID)) !== false) {
						//echo "Found: ".$rowsprops[$prodIdx]['description'].CRLF;
						if (!empty($rowsprops[$prodIdx]['color'])) {
							if (strpos($rowsprops[$prodIdx]['color'],",")>0) { 	// First time  read RGB from DB
								$rowsprops[$prodIdx]['color'] = rgb2hex(explode(",",$rowsprops[$prodIdx]['color']));
							}
							$rowsprops[$prodIdx]['color'] = colorDuplicateProperty($rows[0], $header, $rowsprops[$prodIdx]['color']);
							$datastr.='data-graph-color="'.$rowsprops[$prodIdx]['color'].'" ';
						}
						if (!empty(trim($rowsprops[$prodIdx]['dash_style']))) $datastr.='data-graph-dash-style="'.$rowsprops[$prodIdx]['dash_style'].'" ';
						if ($rowsprops[$prodIdx]['hidden']==1) $datastr.='data-graph-hidden="'.$rowsprops[$prodIdx]['hidden'].'" ';
						if ($rowsprops[$prodIdx]['skip']) $datastr.='data-graph-skip="'.$rowsprops[$prodIdx]['skip'].'" ';
						if ($rowsprops[$prodIdx]['stack_group']) $datastr.='data-graph-stack-group="'.$rowsprops[$prodIdx]['stack_group'].'" ';
						$datastr.='data-graph-yaxis="'.$rowsprops[$prodIdx]['yaxis'].'" ';
						if ($rowsprops[$prodIdx]['type']) $datastr.='data-graph-type="'.$rowsprops[$prodIdx]['type'].'" ';
						if ($rowsprops[$prodIdx]['value_scale']) $datastr.='data-graph-value-scale="'.$rowsprops[$prodIdx]['value_scale'].'" ';
						if ($rowsprops[$prodIdx]['datalabels_enabled']) $datastr.='data-graph-datalabels-enabled="'.$rowsprops[$prodIdx]['datalabels_enabled'].'" ';
						if ($rowsprops[$prodIdx]['datalabels_color']) $datastr.='data-graph-datalabels-color="'.$rowsprops[$prodIdx]['datalabels_color'].'" ';
						if ($rowsprops[$prodIdx]['markers_enabled']) $datastr.='data-graph-markers-enabled="'.$rowsprops[$prodIdx]['markers_enabled'].'" ';
					} else {
						$feedback['message'] .= "Property $header not found!!!: $prodIdx".CRLF;
					}
				}
				$feedback['message'] .= '<th id="'.$tablename.'_'.$header.'_header" '.$datastr;
				$feedback['message'] .= '>' . str_replace('`',' ',$header). '</th>';
			}
//			if ($value == " ") {
//				$rows[0][$header]=" ";
//			}
		}
		$feedback['message'] .= '</tr></thead>';
		$feedback['message'] .= '<tbody>';
		$x=0;
		if (DEBUG_GRAPH) echo "</pre>";

		foreach($rows as $key=>$row) {
			$feedback['message'] .= '<tr id="'.$tablename.'_row_'.$row['id'].'">';
			foreach($row as $key2=>$value2){
				if ($key2 != "id") {
					if ($value2 != " ") {
						if ($key2 != "Date") {
							$feedback['message'] .= '<td id="'.$tablename.'_'.$key2.'_'.$row['id'].'" data-graph-x="'.$x.'">' . $value2 . '</td>';
						} else {
							$feedback['message'] .= '<td id="'.$tablename.'_'.$key2.'_'.$row['id'].'">' . $value2 . '</td>';
						}
					} else {
						$feedback['message'] .= '<td id="'.$tablename.'_'.$key2.'_'.'Null'.'">' . $value2 . '</td>';
					}
				}
			}
			$feedback['message'] .= '</tr>';
			$x++;
		}
		$feedback['message'] .= '</tbody>';
		$feedback['message'] .= '</table>';
	}
//	$feedback['message'] = "";
	return $feedback;

}

function sendEchoBridge($params) {


	$vcIDs = explode(",", $params['commandvalue']);

	foreach ($vcIDs as $vcID) {
		// echo "vcID ".$vcID.CRLF;
		$mysql = 'SELECT d.id, description, bridge_id, url_type, url FROM `ha_voice_devices` d LEFT JOIN  ha_voice_devices_urls u ON d.id = u.vdeviceID 
			WHERE(((d.id) = '.$vcID.') AND active = 1) ORDER BY u.url_type';


		$vlosite = VLO_SITE."process.php?";
		if ($in_commands = FetchRows($mysql)) {
		// print_r($in_commands);
			unset($send_params);
			$send_params['id'] = (empty($in_commands[0]['bridge_id']) ? null : $in_commands[0]['bridge_id']);
			$send_params['name'] = $in_commands[0]['description'];
			$send_params['deviceType'] = "TCP";
			$send_params['targetDevice'] = "Encapsulated";
			foreach ($in_commands as $in_command) {
				if ($in_command['url_type'] == 1) {		// On
					$send_params['onUrl'] = json_encode(Array(Array('item' => $vlosite.$in_command['url'], 'type' => 'httpDevice')));
				} elseif ($in_command['url_type'] == 2) {		// Dim
					$send_params['dimUrl'] = json_encode(Array(Array('item' => $vlosite.$in_command['url'], 'type' => 'httpDevice')));
				} elseif ($in_command['url_type'] == 3) {		// Off
					$send_params['offUrl'] = json_encode(Array(Array('item' => $vlosite.$in_command['url'], 'type' => 'httpDevice')));
				}
			}
		}
	//echo "<pre>";print_r($send_params);echo "</pre>";

/*
Add Device - Post
{"id":null,"name":"Kitchen Recess1","deviceType":"TCP","targetDevice":"Encapsulated","offUrl":"http:
//192.168.2.101/process.php?callerID=264&deviceID=9&messagetypeID=MESS_TYPE_COMMAND&commandID=20","dimUrl"
:"http://192.168.2.101/process.php?callerID=264&deviceID=9&messagetypeID=MESS_TYPE_COMMAND&commandID
=145&commandvalue=${intensity.percent}","onUrl":"http://192.168.2.101/process.php?callerID=264&deviceID
=9&messagetypeID=MESS_TYPE_COMMAND&commandID=17"}


Response
[{"id":"78892528","name":"Kitchen Recess3","deviceType":"TCP","targetDevice":"Encapsulated","offUrl"
:"http://192.168.2.101/process.php?callerID\u003d264\u0026deviceID\u003d9\u0026messagetypeID\u003dMESS_TYPE_COMMAND
\u0026commandID\u003d20","dimUrl":"http://192.168.2.101/process.php?callerID\u003d264\u0026deviceID\u003d9
\u0026messagetypeID\u003dMESS_TYPE_COMMAND\u0026commandID\u003d145\u0026commandvalue\u003d${intensity
.percent}","onUrl":"http://192.168.2.101/process.php?callerID\u003d264\u0026deviceID\u003d9\u0026messagetypeID
\u003dMESS_TYPE_COMMAND\u0026commandID\u003d17"}]

Update - Put
{"id":"910143788","name":"Kitchen Recess","deviceType":"TCP","targetDevice":"Encapsulated","offUrl":"http
://192.168.2.101/process.php?callerID=264&deviceID=9&messagetypeID=MESS_TYPE_COMMAND&commandID=20","dimUrl"
:"http://192.168.2.101/process.php?callerID=264&deviceID=9&messagetypeID=MESS_TYPE_COMMAND&commandID
=145&commandvalue=${intensity.percent}","onUrl":"http://192.168.2.101/process.php?callerID=264&deviceID
=9&messagetypeID=MESS_TYPE_COMMAND&commandID=17"}
*/

		$tcomm = replaceCommandPlaceholders($params['command']['command'],$params);
		$tmp1 = explode('?', $tcomm);
		if (array_key_exists('1', $tmp1)) { 	// found '?' inside command then take page from command string and add to url
			$params['device']['connection']['page'] .= $tmp1[0];
			$tcomm = $tmp1[1];
		} 
		$url=setURL($params);

		$postparams = json_encode($send_params,JSON_UNESCAPED_SLASHES);
		$feedback['commandstr'] = $url.' '.$postparams;

		
		if (!empty($send_params['id'])) {		// Update - PUT
			$url .= '/'.$send_params['id'];
			if (DEBUG_DEVICES) echo $url." PUT-Params: ".htmlentities($postparams).CRLF;
			$curl = restClient::put($url, $postparams, setAuthentication($params['device']), "application/json" , $params['device']['connection']['timeout']);
			if ($curl->getresponsecode() == 200 || $curl->getresponsecode() == 201) {
				$result = $curl->getresponse();
				$feedback['result'][] = $result;
			} elseif ($curl->getresponsecode() == 400) { // Try Adding device does not exist (400: {"message":"Could not save an edited device, Device Id not found: 402389166 "} 
				$send_params['id'] = "";
				$postparams = json_encode($send_params,JSON_UNESCAPED_SLASHES);
			} else {
				$feedback['error'] = $curl->getresponsecode().": ".$curl->getresponse();
			}
		} 
		if (empty($send_params['id'])) {				// Add - Post
			if (DEBUG_DEVICES) echo $url." POST-Params: ".htmlentities($postparams).CRLF;
			$curl = restClient::post($url, $postparams, setAuthentication($params['device']), "application/json" , $params['device']['connection']['timeout']);
			if ($curl->getresponsecode() != 200 && $curl->getresponsecode() != 201) {
				$feedback['error'] = $curl->getresponsecode().": ".$curl->getresponse();
			} else {
				$result = $curl->getresponse();
				$feedback['result'][] = $result;
				$result = json_decode($result,true);
				// print_r($result);
				PDOupdate("ha_voice_devices", array('bridge_id' => $result[0]['id']), array( 'id' => $vcID));
			}
		}
		// echo "</pre>";
	}

	return $feedback;
}

function sendtoplug ($ip, $port, $payload, $timeout) {
	$client = stream_socket_client('tcp://'.$ip.':'.$port, $errno, $errorMessage, $timeout);
	if ($client === false) {
		return "Failed to connect: $errno $errorMessage";
	} else {
		stream_set_timeout($client, $timeout);
		fwrite($client, base64_decode($payload));
//		return base64_encode(stream_get_line ( $client , 1024 ));	
		return decodeTPLink(stream_get_line ( $client , 1024 ));	
		fclose($client);
	}
}

function decodeTPLink($raw) {
/*int main(int argc, char *argv[])
{
  int c=getchar();
  int skip=4;
  int code=0xAB;
  while(c!=EOF)
  {
    if (skip>0)
    {
      skip--;
      c=getchar();
    }
    else
    {
     putchar(c^code);
     code=c;
     c=getchar();
    }
 }
 printf("\n");
}*/
//echo "<pre>";
//echo "instring:".base64_encode($raw).CRLF;
$code = chr(0xAB);
$decoded = "";
$c = substr( $raw, 4, 1 );
for( $i = 5; $i <= strlen($raw); $i++ ) {
    $decoded.=$c^$code;
    $code = $c;
    $c = substr( $raw, $i, 1 );
}
//echo "decoded:".$decoded.CRLF;
//print_r(json_decode($decoded));
//echo "</pre>";
return json_decode($decoded,TRUE);
}

function getStereoSettings(&$params) {

	$feedback['result'][] = array();
	$feedback['Name'] = 'getStereoSettings';
 	$command['caller'] = $params['caller'];
	$command['callerparams'] = $params;
	$command['deviceID'] = $params['deviceID']; 
	$command['commandID'] = COMMAND_GET_VALUE;
	$result = sendCommand($command); 
	
	// echo "<pre>";	
   	$main = new SimpleXMLElement($result['result_raw']);

    
	// if (!is_numeric((string)$flexresponse->code)) {
    		// echo date("Y-m-d H:i:s").": "."Error: ".$xml->code."<br/>\r\n on: '".$url; 
	    	// die();
    	// }

	// print_r($params);
	if (array_key_exists('error', $result)) {
		// $properties['Playing']['value'] =  'Nothing';
		// $properties['File']['value'] = '*';
		// $properties['Artist']['value'] = '*';
		// $properties['Title']['value'] =  '*';
		// $properties['Thumbnail']['value'] = "https://xxxx/images/headers/offline.png?t=".rand();
		// $properties['PlayingID']['value'] =  '0';
		// $params['device']['properties'] = $properties;
		// $feedback['error']='Error - Nothing playing';
	} else {
		$tcomm = replaceCommandPlaceholders("{commandvalue}",$params);
		$properties['Status']['value'] =  ((string)$main->{$tcomm}->Basic_Status->Power_Control->Power == "Standby" ? "Off" : (string)$main->{$tcomm}->Basic_Status->Power_Control->Power);
		$properties['Input']['value'] =  (string)$main->{$tcomm}->Basic_Status->Input->Input_Sel_Item_Info->Title;
		$properties['Volume']['value'] =  (string)(int)(((int)($main->{$tcomm}->Basic_Status->Volume->Lvl->Val) + 500) / 5  ) ;
		$properties['Muted']['value'] =  (string)$main->{$tcomm}->Basic_Status->Volume->Mute ;
		if (isset($main->{$tcomm}->Basic_Status->Surround->Program_Sel->Current->Enhancer )) $properties['Enhancer']['value'] =  (string)$main->{$tcomm}->Basic_Status->Surround->Program_Sel->Current->Enhancer ;
		if (isset($main->{$tcomm}->Basic_Status->Surround->Program_Sel->Current->Straight )) $properties['Straight']['value'] =  (string)$main->{$tcomm}->Basic_Status->Surround->Program_Sel->Current->Straight ;
		if (isset($main->{$tcomm}->Basic_Status->Surround->Program_Sel->Current->Sound_Program)) $properties['Sound_Program']['value'] =  (string)$main->{$tcomm}->Basic_Status->Surround->Program_Sel->Current->Sound_Program ;

		$params['device']['properties'] = $properties;
	}	
	$feedback['result'] = $result;
	return $feedback;

	// echo "</pre>";	
} 

function checkSyslog(&$params) {

	$feedback['Name'] = 'checkSyslog';
	$feedback['commandstr'] = "I send this";
	$feedback['result'] = array();
	$feedback['message'] = "";
	// if () $feedback['error'] = "Not so good";

	if (DEBUG_COMMANDS) {
		echo "<pre>".$feedback['Name'].': '; print_r($params); echo "</pre>";
	}

	//$devs = getDeviceProperties(array('description' => "Syslog Name"));
	
	// echo "<pre>".$feedback['Name'].': '; print_r($devs); echo "</pre>";


        $mysql = 'SELECT m.`deviceID`, s.`period`, s.`host`, sum(`sum`) as sum FROM `net_syslog_mapping` m 
                  RIGHT JOIN `net_syslog_stats` s ON s.host = m.fromhost 
                  WHERE DATE(s.`period`) = DATE(NOW() - INTERVAL 1 DAY) GROUP BY m.`deviceID`,s.`period`,s.`host` ';

	if ($rows = FetchRows($mysql)) {
		foreach ($rows as $key => $row) {
			$feedback['result']['debug'][]=$row;
			// $dev = search_array_key_value($devs, 'value', $row['host']);
			// echo "<pre>".$feedback['Name'].': '; print_r($dev); echo "</pre>";
			if (empty($row['deviceID'])) {		// Not found
				$feedback['result']['action'] = executeCommand(array('callerID' => $params['callerID'], 'messagetypeID' => MESS_TYPE_SCHEME, 'deviceID' => $params['callerID'],  'schemeID'=>SCHEME_ALERT_NORMAL, 'commandvalue'=>' - "'.$row['host'].'" - Missing Syslog device name'));
			} else {
				$props = getDeviceProperties(array('deviceID' => $row['deviceID'])); 
				$feedback['result']['debug'][]=$props;
				// echo "<pre>".$feedback['Name'].' Props: '; print_r($props); echo "</pre>";
				if (array_key_exists('Log Entries Critical Alert',$props) && $row['sum']>$props['Log Entries Critical Alert']['value']) {	// Critical
					$feedback['result']['action'] = executeCommand(array('callerID' => $params['callerID'], 'messagetypeID' => MESS_TYPE_SCHEME, 'deviceID' => $row['deviceID'],  'schemeID'=>SCHEME_ALERT_CRITICAL, 'commandvalue'=>' - Critical syslog messages: '.$row['sum']));
				} else if (array_key_exists('Log Entries High Alert',$props) && $row['sum']>$props['Log Entries High Alert']['value']) {	// High
					$feedback['result']['action'] = executeCommand(array('callerID' => $params['callerID'], 'messagetypeID' => MESS_TYPE_SCHEME, 'deviceID' => $row['deviceID'],  'schemeID'=>SCHEME_ALERT_HIGH, 'commandvalue'=>' - High syslog messages: '.$row['sum']));
				} 
			}
		}
	}

	return $feedback;
}

function executeQuery($params) {

	$mysql = $params['commandvalue'];
	$mysql=str_replace("{DEVICE_SOMEONE_HOME}",DEVICE_SOMEONE_HOME,$mysql);
	$mysql=str_replace("{DEVICE_ALARM_ZONE1}",DEVICE_ALARM_ZONE1,$mysql);
	$mysql=str_replace("{DEVICE_ALARM_ZONE2}",DEVICE_ALARM_ZONE2,$mysql);
	$mysql=str_replace("{DEVICE_DARK_OUTSIDE}",DEVICE_DARK_OUTSIDE,$mysql);
	$mysql=str_replace("{DEVICE_PAUL_HOME}",DEVICE_PAUL_HOME,$mysql);

	$feedback['result'][] =  PDOExec($mysql) ." Rows affected";
	return $feedback;
	
}

function readFlashAir(&$params) {

	$feedback['Name'] = 'readFlashAir';
	$feedback['result'] = array();
	$feedback['message'] = '';
	$feedback['error'] = "Error copying: ";
	
	echo "<pre>***".$feedback['Name'].CRLF;
	print_r($params);
	$lastfile="";

	// User callerID instead
	//$params['deviceID'] = $params['caller']['callerID'];
	
	$params['commandID'] = COMMAND_GET_LIST;
	$result =sendCommand($params);
	if (array_key_exists("error", $result)) {
		 $feedback['error'] .= $result['error']; 
	} else {
	$liststr = $result['result'][0]; 
	$list1 = explode("\n", $liststr);
	foreach($list1 as $value) {
		$split = explode(',', $value);
		if (array_key_exists('1',$split)) {
			$list[] = $split;
		}
	}

	// print_r($list);
	$numfiles = 0;
	$feedback['commandstr'] = "MyCopy";
	foreach($list as $file) {
		if ($file[1] > $params['device']['previous_properties']['Last File']['value']) {		// Ok copy this one
			$url = setURL($params, $dummy);
			$infile = $url.urlencode($file[0].'/'.$file[1]);
			$tofile = CAMERAS_ROOT.$params['device']['previous_properties']['Directory']['value'].'/'.$file[1];
			echo "cp ".$infile.' '.$tofile.CRLF;
			if (!mycopy($infile, $tofile)) {
				$feedback['error'] .= $infile.' '.$tofile.", Aborting";
				break;
			} else {
				$feedback['result'][] = $infile.' '.$tofile;
				if (!($numfiles % 3)) {
				copy($tofile,'/home/www/vlohome/images/lastimage/'.$params['device']['description'].'.jpg');
				$command = array('callerID' => $params['caller']['callerID'], 
					'caller'  => $params['caller'],
					'deviceID' => $params['deviceID'], 
					'commandID' => COMMAND_RUN_SCHEME,
					'schemeID' => SCHEME_ALERT_KODI,
					'macro___commandvalue' => 'Motion Detected|On '.$params['device']['description'].'|'.'https://192.168.2.11/'.'images/lastimage/'.$params['device']['description'].'.jpg?t='.rand(10000000,99999999));
					// 'macro___commandvalue' => 'Motion Detected|On '.$params['device']['description'].'|'.HOME.'images/lastimage/'.$params['device']['description'].'.jpg?t='.rand(10000000,99999999));
					$feedback['result'][] = sendCommand($command);
				}
				$numfiles++;
			}
			if ($file[1] > $lastfile) $lastfile = $file[1];
		}
	}

}
	// if () $feedback['error'] = "Not so good";
    if ($feedback['error'] == "Error copying: ") unset($feedback['error']);
	if ($numfiles) {
		$params['device']['properties']['Last File']['value'] = $lastfile;
	}
	// $feedback['result'][] = $params;
	$feedback['message'] = $numfiles." pictures copied";
	return $feedback;
	// echo "</pre>";	
} 

function mycopy($infile, $tofile) {

//Get the file
if (!$content = file_get_contents($infile)) {
 echo "MyCopy Error: reading $infile\n";
 return false;
} else {
   if (strlen($content) < 300) {
      echo "MyCopy Error: size ".strlen($content)." < 300\n";
     return false;
   } else {
      //Store in the filesystem.
      if (!$fp = fopen($tofile, "w")) {
        echo "MyCopy Error: Cannot open $tofile for write\n";
      } else {
         if ($bytes = !fwrite($fp, $content)) {
            echo "MyCopy Error: Cannot write $tofile\n";
            return false;
         }
         fclose($fp);
     }
  }
}
//echo $bytes."\n";
return true;
}

function extractVids($params) {

	$feedback['Name'] = 'extractVids';
	$feedback['commandstr'] = "readDir";

//	echo "<pre>".CRLF;
//	echo $params['commandvalue'].CRLF;

//        print_r($params);

        $dir = $_SERVER['DOCUMENT_ROOT'].CAMERASDIR.$params['device']['previous_properties']['Directory']['value'].'/';
//	echo "dir: $dir".CRLF;

	$params['importprocess'] = true;
	$result = readDirs($dir.LOCAL_IMPORT, $params['importprocess']);
	$feedback['result']['files'] = $result['result'];

//	print_r($files);

        foreach ($feedback['result']['files'] as $index => $file) {
		if (strtolower($file['extension'])=='mp4') {
//	 		print_r($file);
			$oldname = $file['dirname'].$file['basename'];
			$newname = $dir.'vids/'.$file['basename'];
//			echo "oldname: $oldname".CRLF;
//			echo "newname: $newname".CRLF;

			$fp = fopen($oldname, "r+");
			if (!flock($fp, LOCK_EX|LOCK_NB, $wouldblock)) {
			    if ($wouldblock) {
				$feedback['error'] = "Warning: $oldname is still locked";
			    } else {
				$feedback['error'] = "Warning: $oldname couldn't lock for another reason, e.g. no such file";
			    }
	  		   fclose($fp);
			} else {    // lock obtained
				fclose($fp);
				//
				// None of this detects any files being written to
				//
				// Use ftpwho? shell, capture output search for filename
				//
				sleep(10);
				$copy = copy($oldname, $newname) ;
				if ($copy) {
					$unlink = unlink($oldname);
					if ($copy && $unlink) {
						$feedback['result']['rename'] =  "Renamed from: $oldname to: $newname";
						// ffmpeg -i ARC20180204205454.mp4 -vf fps=3 out-%03d.jpg
						$cmd = 'nohup nice -n 10 /usr/bin/ffmpeg  -i '.$newname.' -vf fps=3 '.$dir.$file['basename'].'-%03d.jpg'; 
						$outputfile=  tempnam( sys_get_temp_dir(), 'ffm' );
						$pidfile=  tempnam( sys_get_temp_dir(), 'ffm' );
						exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $cmd, $outputfile, $pidfile));
						$feedback['message'] = "Initiated extraction".' sequence. Log: '.$outputfile;
	        			} else {
						unlink($newname);   // removed copied file
						$feedback['error'] = "Error during rename from: $oldname to: $newname";
					}
					break;	// Only 1 per run 
				}
			}
		}
	}

//	print_r($feedback);
//	echo "<pre>".CRLF;

	return $feedback;

}

function importDexas(&$params) {
//	Command in:
// 		$params
//
//  Command out:
//		$feedback type Array
//			with keys: 
//						'Name'   		(String)	-> Name of executed command						REQUIRED
//						'result'		(Array)		-> result (Going to log (Update Props or ...)	REQUIRED
//						'message' 		(String)	-> To display on remote
//						'commandstr' 	(String)	-> for eventlog, actual command send
//      if error then	'error'			(String)	-> Error description
//						Nothing else allowed 
// function templateFunction(&$params) {

	// $feedback['Name'] = 'templateFunction';
	// $feedback['commandstr'] = "I send this";
	// $feedback['result'] = array();
	// $feedback['message'] = "all good";
	// if () $feedback['error'] = "Not so good";

	// if (DEBUG_COMMANDS) {
		// echo "<pre>".$feedback['Name'].': '; print_r($params); echo "</pre>";
	// }
	// return $feedback;
// }

	$feedback['Name'] = 'importDexas';
	$feedback['commandstr'] = json_encode($params);
	$feedback['result'] = array();
	$feedback['message'] = "all good";


	// if (DEBUG_COMMANDS) {
		// echo "<pre>".$feedback['Name'].': '; print_r($params); echo "</pre>";
	// }

	$deviceID = $params['deviceID'];
        $thiscommand['loglevel'] = LOGLEVEL_COMMAND;
        $thiscommand['messagetypeID'] = MESS_TYPE_COMMAND;
        $thiscommand['caller'] = $params['caller'];
        $thiscommand['commandID'] = 1;
        $thiscommand['deviceID'] = $params['deviceID'];
        $thiscommand['device']['id'] =  $deviceID;
        $feedback['SendCommand']=sendCommand($thiscommand);
 //     echo "<pre>+++GetDexas";
 //     print_r($feedback);

	if (array_key_exists(0,$feedback['SendCommand']['result'])) {
		$dexaResponse = json_decode($feedback['SendCommand']['result'][0], true);


//print_r($dexaResponse);
 //     echo "</pre>===GetDexas";


        $dexasimported=0;
        $mysql = "TRUNCATE `pg_dexas`;";
        PDOExec($mysql);
        $mysql = "TRUNCATE `pg_configurations`;";
        PDOExec($mysql);
        $mysql = "TRUNCATE `pg_configurations_details`;";
        PDOExec($mysql);

	$dexas = $dexaResponse['data'];
        foreach ($dexas  as $dexa) {
                PDOinsert('pg_dexas', $dexa);

                $dexasimported++;

        }
	}
	$feedback['message'] = $dexasimported." Dexas imported";
		
        return $feedback;

}
function putDexas(&$params) {
//	Command in:
// 		$params
//
//  Command out:
//		$feedback type Array
//			with keys: 
//						'Name'   		(String)	-> Name of executed command						REQUIRED
//						'result'		(Array)		-> result (Going to log (Update Props or ...)	REQUIRED
//						'message' 		(String)	-> To display on remote
//						'commandstr' 	(String)	-> for eventlog, actual command send
//      if error then	'error'			(String)	-> Error description
//						Nothing else allowed 
// function templateFunction(&$params) {

	// $feedback['Name'] = 'templateFunction';
	// $feedback['commandstr'] = "I send this";
	// $feedback['result'] = array();
	// $feedback['message'] = "all good";
	// if () $feedback['error'] = "Not so good";

	// if (DEBUG_COMMANDS) {
		// echo "<pre>".$feedback['Name'].': '; print_r($params); echo "</pre>";
	// }
	// return $feedback;
// }

	$feedback['Name'] = 'importDexas';
	$feedback['commandstr'] = "I send this";
	$feedback['result'] = array();
	$feedback['message'] = "all good";

//echo "<pre>";
//print_r($params);
//echo "</pre>";

//exit;

	// if (DEBUG_COMMANDS) {
		// echo "<pre>".$feedback['Name'].': '; print_r($params); echo "</pre>";
	// }
        $dexasput=0;
//        $mysql = "TRUNCATE `pg_dexas`;";
//        PDOExec($mysql);

	$dexas = $params['commandvalue'];
        foreach ($dexas  as $dexa) {
		$deviceID = $params['deviceID'];
 	       	$thiscommand['loglevel'] = LOGLEVEL_COMMAND;
        	$thiscommand['messagetypeID'] = MESS_TYPE_COMMAND;
	        $thiscommand['caller'] = $params['caller'];
        	$thiscommand['commandID'] = 7;
	        $thiscommand['deviceID'] = $params['deviceID'];
        	$thiscommand['device']['id'] =  $deviceID;
	        $feedback['SendCommand']=sendCommand($thiscommand);
        }

	return $feedback;
 //     echo "<pre>+++GetDexas";
 //     print_r($feedback);
}


function reloadConfgss(&$params) {
//echo "<pre>";
$origData = $formModel->getOrigData()[0];
//print_r($origData);
//echo $origData->{'pg_dexas___dexaId'};

$result = executeCommand(Array('callerID'=>264,'messagetypeID'=>"MESS_TYPE_COMMAND",'deviceID' => 264, 'commandID'=>2,'commandvalue' => $origData->{'pg_dexas___dexaId'}));

//    echo "<pre>";
//    print_r($result);
//exit;
if (array_key_exists('error',$result['SendCommand'][0])) {
  JFactory::getApplication()->enqueueMessage($result['SendCommand'][0]['error'], 'error');
} elseif (array_key_exists(0,$result['SendCommand'][0]['result'])) {
  $dexaResponse = json_decode($result['SendCommand'][0]['result'][0], true);
//print_r($dexaResponse);
 //     echo "</pre>===GetDexas";
  $dexasimported=0;
  $configs = $dexaResponse['data'];
  foreach ($configs as $config) {
    PDOupsert('pg_configurations', $config, array('configurationId' => $config['configurationId']));
    $dexasimported++;
  }
}

JFactory::getApplication()->enqueueMessage($dexasimported." Configurations upserted");
}

?>
