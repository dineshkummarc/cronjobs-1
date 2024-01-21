<?php
function sendCommand(&$thiscommand) {
	debug($thiscommand, 'thiscommand');

	$exittrap=false;
	$callerparams = (array_key_exists('caller', $thiscommand) ? $thiscommand['caller'] : Array());
	$thiscommand['loglevel'] = (array_key_exists('loglevel', $thiscommand['caller']) ? $thiscommand['caller']['loglevel'] : Null);
	$thiscommand['deviceID'] = (array_key_exists('deviceID', $thiscommand) ? $thiscommand['deviceID'] : Null);
	$thiscommand['deviceID'] = ($thiscommand['deviceID'] == DEVICE_CALLING_DEVICE_ID ? $callerparams['deviceID'] : $thiscommand['deviceID']);
	if (IsNullOrEmptyString($thiscommand['deviceID'])) $thiscommand['deviceID'] = Null;
	$thiscommand['commandID'] = (array_key_exists('commandID', $thiscommand) ? $thiscommand['commandID'] : Null);
	$thiscommand['commandvalue'] = (isset($thiscommand['commandvalue']) ? $thiscommand['commandvalue'] : "");
	$thiscommand['timervalue'] = (array_key_exists('timervalue', $thiscommand) ? $thiscommand['timervalue'] : 0);
	$thiscommand['schemeID'] = (array_key_exists('schemeID', $thiscommand) ? $thiscommand['schemeID'] : Null);
	$thiscommand['alert_textID'] = (array_key_exists('alert_textID', $thiscommand) ? $thiscommand['alert_textID'] : Null);
	$thiscommand['priorityID']  = (array_key_exists('priorityID', $thiscommand) ? $thiscommand['priorityID'] : 'NULL');

	$feedback = Array();
	$feedback['result'] = '';
	$feedback['commandstr'] = "";

	$exectime = -microtime(true);
	if ($thiscommand['commandID'] == null) {
		$feedback['error'] = "No Command given";
		debug($feedback, 'feedback');
		return $feedback;			// error abort
	}

//
//   Sends 1 single command to TCP, REST, EMAIL
//

	if (!empty($thiscommand['deviceID'])) {
		if ($thiscommand['deviceID'] == DEVICE_SELECTED_PLAYER) {
			$thiscommand['deviceID'] = $thiscommand['SESSION']['properties']['SelectedPlayer']['value'];
		}
		if (!$device = getDevice($thiscommand['deviceID'])) {
			$feedback['message'] = "Device: ".$thiscommand['deviceID']." not found or not active";
			debug($feedback, 'feedback');
			return $feedback;			// silent abort	
		}
		$thiscommand['device'] = (array_key_exists('device',$thiscommand) && array_key_exists('id', $thiscommand['device']) && $thiscommand['deviceID'] == $thiscommand['device']['id']? array_merge($thiscommand['device'], $device) : $device);
		$thiscommand['device']['previous_properties'] = getDeviceProperties(Array('deviceID' => $thiscommand['deviceID']));
		$commandclassID = $thiscommand['device']['commandclassID'];

	debug($thiscommand, 'thiscommand');

	// Do TOGGLE - DIM, we need to have propertyID, Status/Dim and previous value of property
		if (!array_key_exists('propertyID', $thiscommand)) $thiscommand['propertyID'] = PROPERTY_STATUS;
			$statusarr = search_array_key_value($thiscommand['device']['previous_properties'], 'propertyID', $thiscommand['propertyID']);
			if (!empty($statusarr)) {
				$status_key = $statusarr[0]['description'];
				$status = $thiscommand['device']['previous_properties'][$status_key]['value'];
				$rowmonitor = $thiscommand['device']['previous_properties'][$status_key];

				// Special handling for toggle
				if ($thiscommand['commandID']==COMMAND_TOGGLE) {
					if ($thiscommand['commandvalue'] > 0 && $thiscommand['commandvalue'] < 100) { // if dimvalue given then update dim, else toggle
						$thiscommand['commandID'] = COMMAND_ON;
					} else {
						if ($rowmonitor) {
							debug($status, 'Status Toggle status');
							$thiscommand['commandID'] = ($status == STATUS_ON ? COMMAND_OFF : COMMAND_ON); // toggle on/off
							// Check special commands for this property
							if (array_key_exists('propertyID', $thiscommand)) {
								$mysql = 'SELECT * FROM ha_mi_properties_commands where on_off = '.$thiscommand['commandID']. ' AND propertyID = '.$thiscommand['propertyID'];
								if ($rowpropcommand = FetchRow($mysql))  {								// Found command overwrite
									$thiscommand['commandID'] = $rowpropcommand['commandID'];
								}
							}
						} else {		// not status monitoring 
							$feedback['message'] = "No status to toggle found";
							debug($feedback, 'feedback');
							return $feedback;			// silent abort	
						}
					}
				}
				if ($thiscommand['commandID']==COMMAND_DIM || $thiscommand['commandID']==COMMAND_BRIGHTEN) {
					if ($status != STATUS_OFF) {
						if ($thiscommand['commandID']==COMMAND_DIM) {
							$thiscommand['commandvalue'] = $thiscommand['device']['previous_properties']['Level']['value'] - $thiscommand['commandvalue'];
							if ($thiscommand['commandvalue'] < 0) $thiscommand['commandID'] = COMMAND_OFF;
						} else {
							$thiscommand['commandvalue'] = $thiscommand['device']['previous_properties']['Level']['value'] + $thiscommand['commandvalue'];
							if ($thiscommand['commandvalue'] > 100) $thiscommand['commandvalue'] = 100;
						}
					} else {
						$feedback['message'] = "Light is off, skipping dimming";
						debug($feedback, 'feedback');
						return $feedback;			// silent abort	
					}
				}
				// Invert Status is set
				if (isset($rowmonitor) && $rowmonitor['invertstatus'] == "0") {  
					debug($status, 'Status Invert status');
					if ($thiscommand['commandID'] == COMMAND_OFF) {
						$thiscommand['commandID'] = COMMAND_ON;
					} elseif ($thiscommand['commandID'] == COMMAND_ON) {
						$thiscommand['commandID'] = COMMAND_OFF;
					}
				}
			}
		// }
		if (array_key_exists('previous_properties', $thiscommand['device'])) {
			$thiscommand['onlevel'] = 100;
			if (array_key_exists('On Level', $thiscommand['device']['previous_properties'])) $thiscommand['onlevel'] = $thiscommand['device']['previous_properties']['On Level']['value'];
			$thiscommand['dimmable'] = "NO";
			if (array_key_exists('Dimmable', $thiscommand['device']['previous_properties'])) $thiscommand['dimmable'] = strtoupper($thiscommand['device']['previous_properties']['Dimmable']['value']);
		}

	} else {
		$commandclassID = COMMAND_CLASS_GENERIC;
	}


	$mysql = "SELECT * FROM ha_mf_commands JOIN ha_mf_commands_detail ON ".
			" ha_mf_commands.id=ha_mf_commands_detail.commandID" .
			" WHERE ha_mf_commands.id =".$thiscommand['commandID']. " AND commandclassID = ".$commandclassID." AND `inout` IN (".COMMAND_IO_SEND.','.COMMAND_IO_BOTH.')';
	debug($mysql, 'mysql');

	if (!$rowcommands = FetchRow($mysql))  {			// No device specific command found, try generic, else exit
		$commandclassID = COMMAND_CLASS_GENERIC;
		$mysql = "SELECT * FROM ha_mf_commands JOIN ha_mf_commands_detail ON ".
				" ha_mf_commands.id=ha_mf_commands_detail.commandID" .
				" WHERE ha_mf_commands.id =".$thiscommand['commandID']. " AND commandclassID = ".$commandclassID." AND `inout` IN (".COMMAND_IO_SEND.','.COMMAND_IO_BOTH.')';
		if (!$rowcommands = FetchRow($mysql))  {
			$feedback['error'] = "No outgoing Command found";
			debug($feedback, 'feedback');
			return $feedback;			// error abort
		}
	}
	// Load Message Template
	if (!empty($thiscommand['alert_textID'])) {
		$rowtext = FetchRow("SELECT * FROM ha_alert_text where id =".$thiscommand['alert_textID']);
		debug($rowtext, 'Load alert textID rowtext');
		$thiscommand['mess_subject'] = trim(preg_replace('/\{.*?\}/', '' , replaceText($thiscommand, $rowtext['subject'])));;
		$thiscommand['mess_text'] = trim(preg_replace('/\{.*?\}/', '', replaceText($thiscommand,$rowtext['message'])));;
		if ($rowtext['priorityID'] != Null) $thiscommand['priorityID']= $rowtext['priorityID'];
		if (strlen($thiscommand['mess_text']) == 0) $thiscommand['mess_text'] = " ";
	}

	$thiscommand['command'] = $rowcommands;
	splitCommandvalue($thiscommand);
	debug($thiscommand, '***thiscommand');

	if (array_key_exists('device', $thiscommand) && $thiscommand['device']['connection']['targettype'] == 'NONE') $commandclassID = COMMAND_CLASS_GENERIC; // Treat command for devices with no outgoing as virtual, i.e. set day/night to on/off
	// debug(	"commandID ".$thiscommand['commandID']." commandclassID ".$commandclassID." commandvalue ".$thiscommand['commandvalue']." command ".$thiscommand['command']['command'].'Starting swich');


	switch ($commandclassID)
	{
	case COMMAND_CLASS_INSTEON:
		debug("COMMAND_CLASS_INSTEON","commandclassID");
		$feedback = sendInsteonCommand($thiscommand);
		break;
	case COMMAND_CLASS_3MFILTRETE:
	case COMMAND_CLASS_GENERIC:
	case COMMAND_CLASS_EMAIL:
	case COMMAND_CLASS_ADB:
	case COMMAND_CLASS_BULLET:	// Including ADB
		debug("COMMAND_CLASS_GENERIC/COMMAND_CLASS_3MFILTRETE/EMAIL","commandclassID");
		if ($thiscommand['command']['command'] == "exit") 
			$exittrap=true;
		else {
			$feedback = sendGenericPHP($thiscommand);
		}
		break;
	default:								// Everything else Ard/Sony/Cam/Irrigation/Virtual Devices
		debug("COMMAND_CLASS_OTHER","commandclassID");
		$feedback = sendGenericHTTP($thiscommand);
		break;
	}

	debug($feedback, 'feedback');

	if (!is_null($thiscommand['commandvalue']) && !is_array($thiscommand['commandvalue']) && trim($thiscommand['commandvalue'])!=='') {
		$text =  $thiscommand['commandvalue'];
	}

	if ($rowcommands['need_device']) {
		$feedback['parseProperties'] = parseDeviceResult($thiscommand, $feedback);
		$feedback['updateDeviceProperties'] = updateDeviceProperties($thiscommand);
	}
	// Check for errors first?
	if  (array_key_exists('error', $feedback)) {
		$thiscommand['commandvalue'] = $feedback['error']; // Commandvalue so it will end up in data for log
		$thiscommand['loglevel'] = LOGLEVEL_OVERWRITE;
	} elseif (array_key_exists('message', $feedback)) {
		$thiscommand['commandvalue'] = $feedback['message'];
	} elseif (array_key_exists('Name', $feedback)) {
		$thiscommand['commandvalue'] = $feedback['Name'];
	}
	$exectime += microtime(true);

	if  (!array_key_exists('commandstr', $feedback)) $feedback['commandstr'] ="Not set.";
	if (!array_key_exists('message', $feedback)) $feedback['message']='';
	$feedback['logTime'] = date("Y-m-d H:i:s");
	logEvent(array('inout' => COMMAND_IO_SEND, 'callerID' => $callerparams['callerID'], 'deviceID' => $thiscommand['deviceID'], 'commandID' => $thiscommand['commandID'], 'data' => $thiscommand['commandvalue'], 'message'=> $feedback['message'], 'result' => $feedback, 'loglevel' => $thiscommand['loglevel'], 'commandstr' => $feedback['commandstr'], 'exectime' => $exectime));
	if ($exittrap) exit($thiscommand['commandvalue']);

	debug($feedback, 'feedback');
	return $feedback;
}


// Public (Timers, Triggers, cameras)
function executeCommand($callerparams) {
	debug($callerparams, 'callerparams');
// New entry point for execute chain, from external i.e. remote
// This will store and keep original caller params

	/* Get the Keys Scheme or Device */
	//$callerparams['deviceID'] = (array_key_exists('deviceID', $callerparams) ? $callerparams['deviceID'] : Null);
	//if (IsNullOrEmptyString($callerparams['deviceID'])) $callerparams['deviceID'] = Null;
	$callerparams['schemeID'] = (array_key_exists('schemeID', $callerparams) ? $callerparams['schemeID'] : Null);
	$callerparams['remotekeyID'] = (array_key_exists('remotekeyID', $callerparams) ? $callerparams['remotekeyID'] : Null);
	$callerparams['commandID'] = (array_key_exists('commandID', $callerparams) ? $callerparams['commandID'] : Null);
	$callerparams['commandvalue'] = (array_key_exists('commandvalue', $callerparams) ? $callerparams['commandvalue'] : Null);
	$callerparams['selection'] = (array_key_exists('selection', $callerparams) ? $callerparams['selection'] : Null);
	$callerparams['mouse'] = (array_key_exists('mouse', $callerparams) ? $callerparams['mouse'] : Null);

	if (!headers_sent() && $callerparams['callerID'] == DEVICE_REMOTE) header('Content-type: application/json'); 

	$feedback['messagetypeID'] = $callerparams['messagetypeID'];

	debug($callerparams, 'callerparams');
			
	$feedback['show_result'] = true;
	switch ($callerparams['messagetypeID'])
	{
	case MESS_TYPE_REMOTE_KEY:    // Key pressed on remote
		foreach ($callerparams['keys'] AS $remotekeyID) {
			unset($callerparams['keys']);
			$rowkeys = FetchRow("SELECT * FROM ha_remote_keys where id =".$remotekeyID);
			$callerparams['schemeID'] = $rowkeys['schemeID'];
			$feedback['show_result'] = $rowkeys['show_result'];
			$callerparams['deviceID'] = $rowkeys['deviceID'];
		
			if ($callerparams['schemeID'] <=0) {  													// not a scheme, Execute
				if ($callerparams['commandID']===NULL) {
					if ($callerparams['mouse']=='down') { 
						$callerparams['commandID']=$rowkeys['commandIDdown'];
						if (is_null($callerparams['commandID'])) {
							debug("false", 'false');
							return false;
						}
					} else {
						$callerparams['commandID']=$rowkeys['commandID'];
						$callerparams['propertyID']=$rowkeys['propertyID'];
					}
				}
			} else {
				$callerparams['commandID'] = COMMAND_RUN_SCHEME;
			}
			if (!array_key_exists('caller', $callerparams)) $callerparams['caller'] = $callerparams;
			$feedback['SendCommand'][]=sendCommand($callerparams);
		}
		break;
	case MESS_TYPE_SCHEME:
		debug($callerparams['schemeID'], 'schemeID');
		$callerparams['commandID'] = COMMAND_RUN_SCHEME;
		$callerparams['caller'] = $callerparams;
		$feedback['SendCommand'][]=sendCommand($callerparams);
		break;
	case MESS_TYPE_COMMAND:
		// Comes either with deviceID or keys
		if (array_key_exists('keys', $callerparams)) {
			if ($callerparams['commandID'] == COMMAND_GET_VALUE) {
				foreach ($callerparams['keys'] AS $remotekeyID) {
					unset($callerparams['keys']);
					$rowkeys = FetchRow("SELECT * FROM ha_remote_keys where id =".$remotekeyID);
					if (!empty($rowkeys['deviceID']) && !empty($rowkeys['propertyID'])) {
						// $deviceID = $rowkeys['deviceID'] ;
						// Not going trough sendCommand, replace device here
						// echo $rowkeys['deviceID'].' -> ';
						$deviceID = ($rowkeys['deviceID'] == DEVICE_SELECTED_PLAYER ? $callerparams['SESSION']['properties']['SelectedPlayer']['value'] : $rowkeys['deviceID']);
						// echo $deviceID.CRLF;
						if (!is_null($rowkeys['propertyID'])) $propertyID = $rowkeys['propertyID'];
						$devicesprop[$deviceID.$propertyID]['deviceID'] = $deviceID;
						$devicesprop[$deviceID.$propertyID]['propertyID'] = $propertyID;
					}
				}
				$feedback[]['updateStatus'] = array();
				if (isset($devicesprop)) {
					foreach ($devicesprop as $devprop) {
						$feedback[]['updateStatus'] = getStatusLink($devprop);
					}
				}
			} else {
				foreach ($callerparams['keys'] AS $remotekeyID) {
					unset($callerparams['keys']);
					$rowkeys = FetchRow("SELECT * FROM ha_remote_keys where id =".$remotekeyID);
					$callerparams['deviceID'] = $rowkeys['deviceID'];
					if (!array_key_exists('caller', $callerparams)) $callerparams['caller'] = $callerparams;
					$feedback['SendCommand'][]=sendCommand($callerparams);
				}
			}
		} else {			
			if (array_key_exists('deviceID',$callerparams)) debug($callerparams['deviceID'], 'deviceID');
			debug($callerparams['commandID'], 'commandID');
			$callerparams['caller'] = $callerparams;
			$feedback['SendCommand'][]=sendCommand($callerparams);
		}
		break;
	}

	if ($callerparams['callerID'] == DEVICE_REMOTE) {
		$result = RemoteKeys($feedback, $callerparams);
		$encode = true;
	} elseif ($callerparams['callerID'] == DEVICE_REMOTE_TEXT) {
		$result = RemoteKeys($feedback, $callerparams, true);
		$encode = false;
	} else {
		$result = $feedback;
	}

	if (!empty($encode)) {
		$result = json_encode($result,JSON_UNESCAPED_SLASHES);
		switch (json_last_error()) {
			case JSON_ERROR_NONE:
				//echo ' - No errors';
			break;
			case JSON_ERROR_DEPTH:
				echo ' - Maximum stack depth exceeded';
			break;
			case JSON_ERROR_STATE_MISMATCH:
				echo ' - Underflow or the modes mismatch';
			break;
			case JSON_ERROR_CTRL_CHAR:
				echo ' - Unexpected control character found';
			break;
			case JSON_ERROR_SYNTAX:
				echo ' - Syntax error, malformed JSON';
			break;
			case JSON_ERROR_UTF8:
				echo ' - Malformed UTF-8 characters, possibly incorrectly encoded';
			break;
			default:
				echo ' - Unknown error';
			break;
		}
	}
	// echo "<pre>";

	debug($result, 'result');
	return 	$result;

}

function RemoteKeys($in, $params, $onlyfilter = false) {
	debug($in, 'in');
	debug($params, 'params');

	if ($in['show_result']) {
		$filterkeep = array( 'Status' => 1, 'DeviceID' => 1, 'PropertyID' => 1, 'result' => 1, 'message' => 1, 'Link' => 1,  'volume' => 1, 'error' => 1, 'Timer Remaining' => 1, 'redirect' => 1);
		doFilter($in, array( 'updateStatus' => 1,  'groupselect' => 1, 'message' => 1), $filterkeep, $result);
	} else {
		$filterkeep = array( 'Status' => 1, 'DeviceID' => 1, 'PropertyID' => 1, 'Link' => 1, 'error' => 1, 'Timer Remaining' => 1);
		doFilter($in, array( 'updateStatus' => 1,  'groupselect' => 1), $filterkeep, $result);
	}
	debug($result, 'result');
	if ($onlyfilter) {
		return $result;
	}
	if ($result != null) {
		$feedback = Array();
		foreach ($result as $key => $res) {
			if (array_key_exists('message', $res)) {
				if (is_array($feedback) && array_key_exists('message', $feedback)) {
					if (strlen(str_replace(' ', '', preg_replace( "/\r|\n/", "", $res['message']))) > 0) $feedback['message'].= $res['message'].' ';
				} else {
					if (strlen(str_replace(' ', '', preg_replace( "/\r|\n/", "", $res['message']))) > 0) $feedback['message'] = $res['message'].' ';
				}
			} else if (array_key_exists('result', $res)) {
				if (is_array($feedback) && array_key_exists('message', $feedback)) {
					if (!empty(trim($res['result']))) $feedback['message'].= trim($res['result']).' ';
				} else {
					if (!empty(trim($res['result']))) $feedback['message'] = trim($res['result']).' ';
				}
			} else if (array_key_exists('error', $res)) {
				if (is_array($feedback) && array_key_exists('error', $feedback) && !empty(trim($res['error']))) {
					$feedback['error'].= trim($res['error']).' ';
				} else {
					$feedback['error'] = trim($res['error']).' ';
				}
			} else {
				unset($node);
				if (array_key_exists('updateStatus', $res)) $node = 'updateStatus';
				if (array_key_exists('groupselect', $res)) $node = 'groupselect';
				if (isset($node) && array_key_exists('DeviceID',$res[$node])) {
					$wherestr = (array_key_exists('PropertyID', $res[$node]) ? ' AND propertyID ='.$res[$node]['PropertyID'] : ''); // Not getting propID for Link
					// 06/19/18 Next line was commented out, so broke something. This is needed for updating remote keys with current_player as device
					$deviceStr = (array_key_exists('SESSION', $params) && $res[$node]['DeviceID'] == $params['SESSION']['properties']['SelectedPlayer']['value'] ? $res[$node]['DeviceID'].','.DEVICE_SELECTED_PLAYER : $res[$node]['DeviceID']);
					//$deviceStr = $res[$node]['DeviceID'];
					// echo "<pre>";
					// var_dump($deviceStr);
					// echo "</pre>";
					// DEBUGPRT($deviceStr);
					$mysql = 'SELECT * FROM ha_remote_keys where deviceID IN ('.$deviceStr.') '.$wherestr;
					if ($rows = FetchRows($mysql)) {
						foreach ($rows as $rowkeys) {
							if ($rowkeys['inputtype']== "button" || $rowkeys['inputtype']== "btndropdown" || $rowkeys['inputtype']== "display") {
								$feedback[][$node] = true;
								$last_id=GetLastKey($feedback);
								$feedback[$last_id]["remotekey"] = $rowkeys['id'];
								if ($node == 'updateStatus') {
									$propertyID = (empty($rowkeys['propertyID']) ? '123' : $rowkeys['propertyID']);
									if (array_key_exists('Status', $res['updateStatus']) && $res['updateStatus']['PropertyID'] == $propertyID) {
										if ($res['updateStatus']['Status'] == STATUS_OFF) {    			// if monitoring status and command not off then new status is on (dim/bright)
											$feedback[$last_id]["status"]="off";
										} elseif ($res['updateStatus']['Status'] == STATUS_UNKNOWN) {
											$feedback[$last_id]["status"]="unknown";
										} elseif ($res['updateStatus']['Status'] == STATUS_ON) {
											$feedback[$last_id]["status"]="on";
										} elseif ($res['updateStatus']['Status'] == STATUS_ERROR) {
											$feedback[$last_id]["status"]="error";
										} else { 										// else assume a value
											$feedback[$last_id]["status"]="undefined";
										}
									}

									if (array_key_exists('Link',$res['updateStatus'])) {
										$feedback[$last_id]['link'] = ($res['updateStatus']['Link'] == LINK_UP ? '' : ($res['updateStatus']['Link'] == LINK_WARNING ? 'link-warning' : 'link-down'));
									}
									$starttext = getDisplayText($rowkeys);
									$text = $starttext;
									if($rowkeys['inputtype']== "btndropdown" || $rowkeys['inputtype']== "button") {
										if (array_key_exists('Timer Remaining',$res['updateStatus'])) 
			$text.='&nbsp;(<i class="icon-clock btn-icon-small"></i>'.$res['updateStatus']['Timer Remaining'].')';
									}
									// echo $rowkeys['id'].' '.$rowkeys['deviceID'].CRLF;
									$deviceID = ($rowkeys['deviceID'] == DEVICE_SELECTED_PLAYER ? 
									$params['SESSION']['properties']['SelectedPlayer']['value'] : $res[$node]['DeviceID']);
									// echo $deviceID.CRLF;
									$text = replacePropertyPlaceholders($text, Array('deviceID' => $res['updateStatus']['DeviceID']));
									if (!empty($text)) $feedback[$last_id]["text"] = $text;
								}
							}
						}
					}
				}
			}
		}
	} else { 
		$feedback['message'] = '';
	}
	return array_map("unserialize", array_unique(array_map("serialize", $feedback)));
}

?>
