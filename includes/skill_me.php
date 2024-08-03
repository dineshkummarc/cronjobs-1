<?php
define ("SAY_ASKIM", '<phoneme alphabet="ipa" ph="/ə\'ʃkom">askim</phoneme>');

$log = array();
//$debug = 5;

function handleRequest($alexaRequest) {
	debug($alexaRequest, 'alexaRequest');

	global $log;

	$commandMap = [ 
		"ShortAnswerIntent" =>           "409",
		"WhatsTemperatureIntent" =>      "410",
		"AnySecurityOpenIntent" =>       "411",
		"DeviceStatusIntent" =>          "412",
		"WhatsBedtimeIntent" =>          "413",
		"WhatsPlayingIntent" =>          "414",
		"DoingIntent" =>                 "415",
		"SystemReportIntent" =>          "416",
		"HelpIntent" =>                  "417",
		"StopIntent" =>                  "418",
		"CancelIntent" =>                "419",
		"LaunchRequest" =>               "420",
		"SessionEndedRequest"   =>       "421",
		"AlertsIntent"   =>              "422",
		"YesIntent"   =>                 "423",
		"ReportIntent"   =>              "446",
		"NoIntent"   =>                  "424"
	];

	$feedback = array();
	$exectime = -microtime(true); 
	
	$log['callerID'] = MY_DEVICE_ID;
	$log['inout'] = COMMAND_IO_RECV;

	
	if ($alexaRequest instanceof \Alexa\Request\IntentRequest) {
		$log['commandID'] = (array_key_exists($alexaRequest->intentName, $commandMap) ? $commandMap[$alexaRequest->intentName] : COMMAND_UNKNOWN);
		if (function_exists($alexaRequest->intentName)) {
			$fname= $alexaRequest->intentName;

			$response = new \Alexa\Response\Response;
			// Launched, dialog keep open and save Launched
			if (!$alexaRequest->session->new && isset($alexaRequest->session->attributes) && isset($alexaRequest->session->attributes->Launched)) {
				$response->sessionAttributes(Array("Launched" => true));
				$response->endSession(false);
			}
			$feedback = $fname($alexaRequest, $alexaRequest->session, $response);
		} else {
			$response = new \Alexa\Response\Response;
			$response->respond("<speak> I'm sorry I didn't understand that.</speak>")
			 ->reprompt("<speak> Try that again. You can say things like, " .
				"System status <break time=\"0.2s\" /> " .
				"Are there any windows open <break time=\"0.2s\" /> " .
				"What is Playing. Or you can say exit. " .
				"Now, what can I help you with? </speak>");

			$feedback['result'] = $response->ask();
			$feedback['error'] = "Did not understand.";
		}
	}

 	if ($alexaRequest instanceof \Alexa\Request\LaunchRequest) {
		$log['commandID'] = (array_key_exists('LaunchRequest', $commandMap) ? $commandMap['LaunchRequest'] : COMMAND_UNKNOWN);
		$response = new \Alexa\Response\Response;
		$feedback = LaunchRequest($alexaRequest, $alexaRequest->session, $response);
	}
	
 	if ($alexaRequest instanceof \Alexa\Request\SessionEndedRequest) {
		$log['commandID'] = (array_key_exists('SessionEndedRequest', $commandMap) ? $commandMap['SessionEndedRequest'] : COMMAND_UNKNOWN);
		$response = new \Alexa\Response\Response;
		$feedback = SessionEndedRequest($alexaRequest, $alexaRequest->session, $response);
	}

	$log['result'] = $feedback;
	if  (!array_key_exists('commandstr', $feedback['result'])) $feedback['result']['commandstr'] ="Not set.";
	$log['commandstr'] = $feedback['result']['commandstr'];
	$exectime += microtime(true);
	$log['exectime'] = $exectime;
	logEvent($log);
	debug($alexaRequest, 'alexaRequest');
	return;
}

function ShortAnswerIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;

	$finddate = strtolower((isset($request->slots->Date) ? $request->slots->Date : ""));
	$findelse = strtolower((isset($request->slots->CatchAll) ? $request->slots->CatchAll : ""));
	$orgIntent = "NotFound";
	if (!$session->new && isset($session->attributes) && isset($session->attributes->intentSequence)) {
		$orgIntent = $session->attributes->intentSequence;
	}
	
	$log['data'] = "Date: ".$finddate." CatchAll: ".$findelse." orgIntent: ".$orgIntent;
	
	if (empty($findelse) && empty($finddate)) {
		$response->respond("Sorry, I did not understand, please start over.");
		$response->tell();
	}

	switch ($orgIntent) {
		case "WhatsTemperatureIntent":
			$request->slots->TempDevice = $findelse;
			$request->intentName = $orgIntent;
			break;
		case "AnySecurityOpenIntent":
			$request->slots->Types = $findelse;
			$request->intentName = $orgIntent;
			break;
		case "DeviceStatusIntent":
		case "DeviceReportIntent":
			$request->slots->Device = $findelse;
			$request->intentName = $orgIntent;
			break;
		case "WhatsBedtimeIntent":
			$request->slots->Date = $finddate;
			$request->intentName = $orgIntent;
			break;
		case "AlertsIntent":
			$request->slots->Date = date("Y-m-d");
			$request->intentName = $orgIntent;
			break;
		case "WhatsPlayingIntent":
		case "NotFound":
		default:
			$response->respond("Sorry, I did not understand, please start over.");
			$feedback['result'] = $response->tell();
			$feedback['error'] = "Restart it";
			debug($feedback, 'feedback');
			return $feedback;
	}
	
	$fname= $request->intentName;
	$feedback['result'] = $fname($request, $session, $response);

	debug($feedback, 'feedback');
	return $feedback;
	
}


function WhatsTemperatureIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;

	$feedback['Name'] = 'WhatsTemperatureIntent';
	
	$responses = array ( "At the moment, the %s temperature is %s degrees.", "<speak>".SAY_ASKIM.", The %s temperature is %s degrees.</speak>", "%s, %s degrees.");

	$find = strtolower((isset($request->slots->TempDevice) ? $request->slots->TempDevice : "outside"));

	$log['data'] = "TempDevice: ".$find;

	$found = findDeviceByName($find);
	if (!empty($found)) { 
		$deviceID = $found[0]['deviceID'];	// Handle multiple matches?
		$log['deviceID'] = $deviceID;
		$log['typeID'] = getDevice($log['deviceID'])['typeID'];
	} else {
		$response->respond(sprintf("I do not know what the temperature is in the %s, do you want to try another device?",  $find))
		 ->reprompt("Sorry which device you want?");
		$response->sessionAttributes(Array("intentSequence"=>"WhatsTemperatureIntent"));
		$feedback['result'] = $response->ask();
		$feedback['error'] = "What device?";
		debug($feedback, 'feedback');
		return $feedback;
	}

	$propertyName = 'Temperature';
	$deviceProperties = getDeviceProperties(array('description'=>$propertyName, 'deviceID'=>$deviceID));
	
	debug($deviceProperties, 'deviceProperties');
	
	$answer = sprintf($responses[rand(0,count($responses)-1)], defaultFeedbackName($deviceID), round((float)$deviceProperties['value'],1) + 0);
	$response->respond($answer)->withCard(strip_tags($answer));


	$feedback['message'] = $answer;
	$feedback['result'] = $response->tell();
	debug($feedback, 'feedback');
	return $feedback;
	
}

function AnySecurityOpenIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;
	$feedback['Name'] = 'AnySecurityOpenIntent';

	$responses_all = array ("All %s are closed.");
	$responses_some = array ("The %s is open.");

	$find = strtolower(isset($request->slots->Types) ? $request->slots->Types : 'doors');
	$propertyID = getProperty('Status', false)['id'];
	$log['data'] = "Types: ".$find;

	
	$mysql = 'SELECT typeID, LOWER(description) as description FROM `ha_voice_names` WHERE typeID > 0'; 
	$voicenames = FetchRows($mysql);
	$found = search_array_key_value($voicenames, 'description' , $find);
	if (!empty($found)) { 
		$typeID = $found[0]['typeID'];
		$mysql = 'SELECT id as deviceID, typeID FROM `ha_mf_devices` WHERE typeID IN ('.$typeID.') and inuse = 1'; // Handle multiple matches?
		$devices = FetchRows($mysql);
		foreach ($devices as $key => $row) {
			$results[] = getStatusLink(array('deviceID'=>$row['deviceID'], 'propertyID'=>$propertyID));
		}
	} else {
		$response->respond("Sorry which device group you want?")
		 ->reprompt("Sorry which device group you want?");
		$response->sessionAttributes(Array("intentSequence"=>"AnySecurityOpenIntent"));
		$feedback['result'] = $response->ask();
		$feedback['error'] = "Cannot find device";
		debug($feedback, 'feedback');
		return $feedback;
	}
	
	$answer = "";
	$anyopen = 0;
	foreach ($results as $status) {
		if ($status['Status']) {
			$anyopen = true;
			$answer .= ($answer != "" ? ' and ' : '').sprintf($responses_some[rand(0,count($responses_some)-1)], defaultFeedbackName($status['DeviceID'])); 
		}
	}
	
	if (!$anyopen) {
		$answer = sprintf($responses_all[rand(0,count($responses_all)-1)], $find);
	} 
	

	$response->respond($answer)->withCard(strip_tags($answer));

	$feedback['message'] = $answer;
	$feedback['result'] = $response->tell();
	debug($feedback, 'feedback');
	return $feedback;

}

function SystemReportIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;
	$feedback['Name'] = 'SystemReportIntent';

	$responses = array ("The %s is %s", "The status of the %s is %s.");

	
	$findDevice = "system";
	$findProperty = "Status";

	$feedback['result'] = homeStatus($request, $session, $response);
	debug($feedback, 'feedback');
	return $feedback;

}

function DeviceStatusIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;
	$feedback['Name'] = 'DeviceStatusIntent';

	$responses = array ("The %s is %s", "The status of the %s is %s.");

	$findDevice = strtolower(isset($request->slots->Device) ? $request->slots->Device : "system");
	// $findProperty = (isset($request->slots->Status) ? $request->slots->Status : "Status");
	$findProperty = "Status";
	$log['data'] = "Device: ".$findDevice;

	$found = findDeviceByName($findDevice);
	if (!empty($found)) { 
		$deviceID = $found[0]['deviceID'];	// Handle multiple matches?
	} else {
		$response->respond("Sorry which device you want?")
		 ->reprompt("Sorry which device you want?");
		$response->sessionAttributes(Array("intentSequence"=>"DeviceStatusIntent"));
		$feedback['result'] = $response->ask();
		$feedback['error'] = "Cannot find device";
		debug($feedback, 'feedback');
		return $feedback;
	}

	$deviceProperties = getDeviceProperties(array('deviceID'=>$deviceID));
	debug($deviceProperties, 'deviceProperties');

	$found = findByKeyValue($deviceProperties, 'primary_status' , 1);
 
	if (array_key_exists('invertstatus', $deviceProperties[$found]) && $deviceProperties[$found]['invertstatus'] == "0") {  
		if ($deviceProperties[$found]['value'] == STATUS_OFF) {
			$deviceProperties[$found]['value'] == STATUS_ON;
		} else if ($deviceProperties[$found]['value'] == STATUS_ON) {
			$deviceProperties[$found]['value'] == STATUS_OFF;
		}
	}
	
	
	$status = getFeedbackStatus($deviceID, $deviceProperties[$found]['value']);

	$answer = sprintf($responses[rand(0,count($responses)-1)], defaultFeedbackName($deviceID), $status);
	$response->respond($answer)->withCard(strip_tags($answer));
	$feedback['message'] = $answer;
	$feedback['result'] = $response->tell();
	debug($feedback, 'feedback');
	return $feedback;

}

function DeviceReportIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;
	$feedback['Name'] = 'DeviceReportIntent';

	$responses = array ("<speak>Here is a full report on the %s");
	$responseProperty = "%s is %s";

	$findDevice = strtolower(isset($request->slots->Device) ? $request->slots->Device : "system");
	// $findProperty = (isset($request->slots->Status) ? $request->slots->Status : "Status");
	$log['data'] = "Device: ".$findDevice;

	$found = findDeviceByName($findDevice);
	if (!empty($found)) { 
		$deviceID = $found[0]['deviceID'];	// Handle multiple matches?
	} else {
		$response->respond("Sorry which device you want?")
		 ->reprompt("Sorry which device you want?");
		$response->sessionAttributes(Array("intentSequence"=>"DeviceReportIntent"));
		$feedback['result'] = $response->ask();
		$feedback['error'] = "Cannot find device";
		debug($feedback, 'feedback');
		return $feedback;
	}

	$deviceProperties = getDeviceProperties(array('deviceID'=>$deviceID));
	debug($deviceProperties, 'deviceProperties');


	$answer = ["" , ""];

	$answer = sprintf($responses[rand(0,count($responses)-1)], defaultFeedbackName($deviceID));

	$found = findByKeyValue($deviceProperties, 'primary_status' , 1);

	if ($deviceProperties[$found]['invertstatus'] == "0") {  
		if ($deviceProperties[$found]['value'] == STATUS_OFF) {
			$deviceProperties[$found]['value'] == STATUS_ON;
		} else if ($deviceProperties[$found]['value'] == STATUS_ON) {
			$deviceProperties[$found]['value'] == STATUS_OFF;
		}
	}
	$status = getFeedbackStatus($deviceID, $deviceProperties[$found]['value']);
	$answer = sprintf($responses[rand(0,count($responses)-1)], defaultFeedbackName($deviceID), $status);
	$answer .= " <break time=\"0.2s\" /> ";
	$answer .= sprintf($responseProperty, $found , $status);

	while ($found = findByKeyValue($deviceProperties, 'report_status' , 1)) {
		$answer .= "<break time=\"0.2s\" />";
		if ($deviceProperties[$found]['invertstatus'] == "0") {  
			if ($deviceProperties[$found]['value'] == STATUS_OFF) {
				$deviceProperties[$found]['value'] == STATUS_ON;
			} else if ($deviceProperties[$found]['value'] == STATUS_ON) {
				$deviceProperties[$found]['value'] == STATUS_OFF;
			}
		}
		$status = getFeedbackStatus($deviceID, $deviceProperties[$found]['value']);
		$answer .= sprintf($responseProperty, $found , $status);
		$deviceProperties[$found]['report_status'] = 0; // Do not find again
	}

	$answer .= "</speak>";
	
	$response->respond($answer)->withCard(strip_tags($answer));
	$feedback['message'] = $answer;
	$feedback['result'] = $response->tell();
	debug($feedback, 'feedback');
	return $feedback;

}

function WhatsBedtimeIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;

	$feedback['Name'] = 'WhatsBedtimeIntent';
		
	// Handle not getting date
	$find = strtolower(isset($request->slots->Date) ? $request->slots->Date :  date("Y-m-d"));
	$log['data'] = "Date: ".$find;

	if ($find > date("Y-m-d")) { 		// Future
		$date = new DateTime($find);
		$response->respond("Only past dates please, which day did you want to know about?")
				->reprompt("Which day did you want to know about.");
		$response->sessionAttributes(Array("intentSequence"=>"WhatsBedtimeIntent"));
		$feedback['result'] = $response->ask();
		$feedback['error'] = "What day.";
		debug($feedback, 'feedback');
		return $feedback;
	} else {
		// Yesterday should be day before, others should be normal?
		$fromdate = $find;
		$to_date = date("Y-m-d H:i", strtotime($fromdate." +24 hour"));
		$mysql = 'SELECT alert_date FROM `ha_alerts_log`  WHERE (description LIKE "%goodnight goodnight%" and `alert_date` > "'.$fromdate.'" and `alert_date` < "'. $to_date.'") order by alert_date desc limit 1';
		// echo $mysql;
		$answer = "Insufficient data. Please specify parameters.";
		if ($row = FetchRow($mysql)) {
			$date = new DateTime($row['alert_date']);
		} else { 
			$mysql = 'SELECT alert_date FROM `ha_alerts_log`  WHERE (description LIKE "%goodnight goodnight%") order by alert_date desc limit 1';
			if ($row = FetchRow($mysql)) {
				$date = new DateTime($row['alert_date']);
			}
		}
	}

	$datestr = dateToSpeach($date->format("Y-m-d"));
	// var_dump($datestr);
	$answer = sprintf("%s you went at %s to bed.", $datestr, $date->format('g:i'));
	$response->respond($answer)->withCard(strip_tags($answer));
	$feedback['message'] = $answer;
	$feedback['result'] = $response->tell();
	debug($feedback, 'feedback');
	return $feedback;

}

function WhatsPlayingIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;
	
	$feedback['Name'] = 'WhatsPlayingIntent';
	
	$responses = array("<speak>This is <break time=\"0.2s\" /> %s <break time=\"0.2s\" />by %s.</speak>","<speak>We're listening to <break time=\"0.2s\" /> %s  <break time=\"0.2s\" /> by %s </speak>", "<speak>%s  <break time=\"0.2s\" /> by %s.</speak>");

	$deviceProperties = getDeviceProperties(array('deviceID'=>getCurrentPlayer()));
	$log['deviceID'] = getCurrentPlayer();
	$log['typeID'] = getDevice($log['deviceID'])['typeID'];
	
	debug($deviceProperties, 'deviceProperties');
	
	$answer = sprintf($responses[rand(0,count($responses)-1)], $deviceProperties['Title']['value'], $deviceProperties['Artist']['value']);
	$response->respond($answer)->withCard(strip_tags($answer));
	$feedback['message'] = $answer;
	$feedback['result'] = $response->tell();
	debug($feedback, 'feedback');
	return $feedback;

}

function DoingIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;

	$feedback['Name'] = 'DoingIntent';
	
	$responses1 = array("Let me check on %s. <break time=\"3s\" />","Searching %s... <break time=\"3s\" />", "Looking for %s, Switching on cameras... <break time=\"3s\" />","Searching %s... <break time=\"3s\" />Still searching, checking under the couch now <break time=\"3s\" />");
	$responses2 = array("Yep, looks like it","I bet you!", "Hmm, No probably just resting the eyes!","Yep, what's new?","Yep, no suprise here!");
	$responses3 = array("No, off course not!","Nope, looks like you are completely wrong!", "Absolutely not! Really!, how dare you","Absolutely not, maybe you should check on yourself!","Nope, it could not be farther from the thruth!");

	$find = strtolower(isset($request->slots->People) ? $request->slots->People : "that");
	$log['data'] = "People: ".$find;
	$log['deviceID'] = MY_DEVICE_ID;
	$log['typeID'] = getDevice($log['deviceID'])['typeID'];

	if ($find == "paul" || $find == "the husband" || $find == "husband") {
		$answer = "<speak>".sprintf($responses1[rand(0,count($responses1)-1)], $find).$responses3[rand(0,count($responses2)-1)]."</speak>";
	} else {
		$answer = "<speak>".sprintf($responses1[rand(0,count($responses1)-1)], $find).$responses2[rand(0,count($responses2)-1)]."</speak>";
	}
	
	$response->respond($answer)->withCard(strip_tags($answer));

	$feedback['message'] = $answer;
	$feedback['result'] = $response->tell();
	debug($feedback, 'feedback');
	return $feedback;

}

function homeStatus($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;

	$feedback['Name'] = 'homeStatus';

	$responses_all = [ "All primary systems are on-line <break time=\"0.2s\" />", "All secondary systems functioning properly." ];
	$responses_some = [ "Following primary systems are off-line: <break time=\"0.2s\" />", "Following secondary systems are off-line: <break time=\"0.2s\" />" ];
	$responses_offline = ["%s <break time=\"0.2s\" />", "%s <break time=\"0.2s\" />"];


	$propertyID = getProperty('Link', false)['id'];
	$log['deviceID'] = MY_DEVICE_ID;
	$log['typeID'] = getDevice($log['deviceID'])['typeID'];

	$index = 0;
	$answer = ["" , ""];
	foreach (['17', '18'] as $groupID) {
		$mysql = 'SELECT deviceID, groupID FROM `ha_mf_device_group` WHERE groupID = '.$groupID; 
		$devices = FetchRows($mysql);
		$feedback = array();
		foreach ($devices as $row) {
			$feedback[] = getStatusLink(array('deviceID'=>$row['deviceID'], 'propertyID'=>$propertyID));
		}

		$anydown = 0;
		foreach ($feedback as $status) {
			if (array_key_exists('Link', $status) && $status['Link'] != "1") {
				$anydown = true;
				$answer[$index] .= ($answer[$index] != "" ? ' ; ' : $responses_some[$index].' ; ').sprintf($responses_offline[$index], defaultFeedbackName($status['DeviceID'])); 
			}
		}
		
		if (!$anydown) {
			$answer[$index] = sprintf($responses_all[$index]);
		} 
		$index++;
		
	}
	
	$mysql = 'SELECT count(id) as count FROM ha_alerts_log WHERE `priorityID` < '.PRIORITY_LOW.' AND DATE_FORMAT( NOW() , "%Y-%m-%d" ) = DATE_FORMAT(`alert_date`, "%Y-%m-%d" )';
	$alerts = FetchRow($mysql);
	if (!empty($alerts['count'])) {		// Alerts
		$answer = "<speak>".implode(". ", $answer)." You have ".$alerts['count']." alerts for today. Do you want to hear these?</speak>";
		$response->respond($answer)
			->reprompt("Do you want to listen to today's alerts?");
		$response->sessionAttributes(Array("intentSequence"=>"AlertsIntent"));
		$feedback['result'] = $response->ask();
		$feedback['message'] = "Want to listen to alerts??";
		debug($feedback, 'feedback');
		return $feedback;	
	}

	$answer = "<speak>".implode(". ", $answer)."</speak>";
	$response->respond($answer)->withCard(strip_tags($answer));

	$feedback['message'] = $answer;
	$feedback['result'] = $response->tell();
	debug($feedback, 'feedback');
	return $feedback;
}

function AlertsIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;
	
	$feedback['Name'] = 'AlertsIntent';
	$wherestr = '`priorityID` < '.PRIORITY_LOW.' ';
	$responsstr = "";
	
	$findDevice = strtolower(isset($request->slots->Device) ? $request->slots->Device : "");
	$findDate = (isset($request->slots->Date) ? $request->slots->Date : "");
	$findNumber = (isset($request->slots->Number) ? $request->slots->Number : "");
	// $catchAll = (isset($request->slots->CatchAll) ? $request->slots->CatchAll : "");

	if ($findDate == "" && empty($findNumber)) { $findNumber = 3; } // Whether device is set or not

	// Handle not getting date
	$log['data'] = "Device: ".$findDevice."Date: ".$findDate."Number: ".$findNumber;

	if ($findDate != '') {
		if ($findDate > date("Y-m-d")) { 		// Future
			$response->respond("Only past dates please, for which day?")
					->reprompt("Please give a day, or number.");
			$response->sessionAttributes(Array("intentSequence"=>"AlertsIntent"));
			$feedback['result'] = $response->ask();
			$feedback['error'] = "What day/number.";
			debug($feedback, 'feedback');
			return $feedback;
		} else {
			$wherestr .= ($wherestr != '' ? ' AND ' : '').'DATE_FORMAT( `alert_date` , "%Y-%m-%d" ) = "'.$findDate.'"';
		}
	}
	if ($findDevice != '') {
		$found = findDeviceByName($findDevice);
		if (empty($found)) {
			$response->respond(sprintf("I could not find device %s, do you want to try another device?",  $findDevice))
			 ->reprompt("Sorry which device you want?");
			$response->sessionAttributes(Array("intentSequence"=>"AlertsIntent"));
			$feedback['result'] = $response->ask();
			$feedback['error'] = "What device?";
			debug($feedback, 'feedback');
			return $feedback;
		} else {
			$wherestr .= ($wherestr != '' ? ' AND ' : '') .'deviceID = '.$found[0]['deviceID'];
		}
	}
	$wherestr .= ' ORDER BY `alert_date` DESC ';
	if ($findNumber != '') {
		$wherestr .=  'LIMIT '.$findNumber;
	}
	
	$mysql = 'SELECT deviceID, a.description, priorityID, alert_date, alert_text, p.description as priority FROM ha_alerts_log a
				LEFT JOIN ha_mi_priority p ON a.priorityID = p.id	
				WHERE '.$wherestr;
	$alerts = FetchRows($mysql);
	if (empty($alerts)) {
		$answer = 'You have no alerts for selected criteria.';
		$response->respond($answer)->withCard(strip_tags($answer));
		$feedback['message'] = $answer;
		$feedback['result'] = $response->tell();
		debug($feedback, 'feedback');
		return $feedback;
	}

	$answer = '';
	if ($findNumber != '') {
		$answer = 'Here are the last '.count($alerts).' alerts.';
	}
	if ($findDate != '') {
		$date = new DateTime($findDate);
		$datestr = dateToSpeach($date->format("Y-m-d"));
		$answer .= ($answer != '' ? ', ' : 'Here are your alerts for ' ).$datestr;
	}
	if ($findDevice != '') {
		$answer .= ($answer != '' ? ', For ' : 'Here are your alerts for ' ).$findDevice;
	}
	
	$lastdescr = "";
	foreach ($alerts as $alert) {
		$date = new DateTime($alert['alert_date']);
		$datestr = dateToSpeach($date->format("Y-m-d"));
		$answer .= "<break time=\"0.4s\" /> ";
		if ($lastdescr == $alert['description']) {
			$answer .= ' repeated at '.$date->format('g:i');
		} else {
			$answer .= $alert['priority'].' priority alert,';
			if ($findDate == '') {
				$answer .= ' '.$datestr;
			}
			$answer .= ' at '.$date->format('g:i');
			$answer .= ' '.$alert['description'].'.';
		}
		$lastdescr = $alert['description'];
	}
	$answer .= "<break time=\"0.3s\" /> End Report.";
		
	$answer = '<speak>'.$answer.'</speak>';
	$response->respond($answer)->withCard(strip_tags($answer));

	$feedback['message'] = $answer;
	$feedback['result'] = $response->tell();
	debug($feedback, 'feedback');
	return $feedback;

}

function HelpIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;

	$feedback['Name'] = 'HelpIntent';

	$log['deviceID'] = MY_DEVICE_ID;
	$log['typeID'] = getDevice($log['deviceID'])['typeID'];

	
    $response->respond("You can ask for the status of ".SITE_NAME." devices, recent alert" .
        "For example, is the front door locked or what the temperature in the coop. " .
        "Now, what can I help you with?")
     ->reprompt("<speak> I'm sorry I didn't understand that. You can say things like, " .
        "System status <break time=\"0.2s\" /> " .
        "Are there any windows open <break time=\"0.2s\" /> " .
        "What is Playing. Or you can say exit. " .
        "Now, what can I help you with? </speak>");

	$feedback['message'] = "Help";
	$feedback['result'] = $response->ask();
	debug($feedback, 'feedback');
	return $feedback;
}

function NoIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;

	$feedback['Name'] = 'NoIntent';

	$log['deviceID'] = MY_DEVICE_ID;
	$log['typeID'] = getDevice($log['deviceID'])['typeID'];

	$responses = array ("ok");
				
	$response->sessionAttributes(Array("Launched" => null));
	$response->endSession(true);
	$answer = $responses[rand(0,count($responses)-1)];
	$response->respond($answer);
	$feedback['message'] = $answer;
	$feedback['result'] = $response->tell();
	debug($feedback, 'feedback');
	return $feedback;
	
}

function StopIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;

	$feedback['Name'] = 'StopIntent';

	$log['deviceID'] = MY_DEVICE_ID;
	$log['typeID'] = getDevice($log['deviceID'])['typeID'];

	$feedback['result'] = CancelIntent($request, $session, $response);
	debug($feedback, 'feedback');
	return $feedback;
}

function CancelIntent($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;

	$feedback['Name'] = 'CancelIntent';

	$log['deviceID'] = MY_DEVICE_ID;
	$log['typeID'] = getDevice($log['deviceID'])['typeID'];

	$responses = array ("adiós"," adieu"," addio"," adeus","aloha","arrivederci","ciao","auf Wiedersehen","au revoir","bon voyage",
				"sayonara","tot ziens","Goodbye","Farewell","Have a good day","Take care","See you later","Hear you later","OK. have a good one.",
				"Bye bye!","Later!","Later"," man","Have a good one.","So long.","All right then.","Catch you later",
				"Peace!","Peace out.","I'm out!","Smell you later.","Hoşçakal","Güle güle");
				
	$response->sessionAttributes(Array("Launched" => null));
	$response->endSession(true);
	$answer = $responses[rand(0,count($responses)-1)];
	$response->respond($answer);
	$feedback['message'] = $answer;
	$feedback['result'] = $response->tell();
	debug($feedback, 'feedback');
	return $feedback;
}

function LaunchRequest($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;

	$feedback['Name'] = 'LaunchRequest';

	$log['deviceID'] = MY_DEVICE_ID;
	$log['typeID'] = getDevice($log['deviceID'])['typeID'];

    // If we wanted to initialize the session to have some attributes we could add those here.
    $response->respond("<speak> Welcome to home automation <break strength=\"weak\" />reporting service, <break time=\"0.3s\" />Please state the nature of your emergency?</speak>")
     ->reprompt("<speak> I'm sorry I didn't understand that. You can say things like, " .
        "System status <break time=\"0.2s\" /> " .
        "Are there any windows open <break time=\"0.2s\" /> " .
        "What is Playing. Or you can say exit. " .
        "Now, what can I help you with? </speak>");

	$response->sessionAttributes(Array("Launched" => true));

	$feedback['message'] = "Launch Prompt";
	$feedback['result'] = $response->ask();
	debug($feedback, 'feedback');
	return $feedback;

}

function SessionEndedRequest($request, $session, $response) {
	debug($request, 'request');
	debug($session, 'session');
	debug($response, 'response');

	global $log;

	$feedback['Name'] = 'SessionEndedRequest';

	$log['deviceID'] = MY_DEVICE_ID;
	$log['typeID'] = getDevice($log['deviceID'])['typeID'];
	$log['data'] = $request->reason;
	echo "{}";
	
	debug($feedback, 'feedback');
	return $feedback;
}

function defaultFeedbackName($deviceID) {
		if ($row = FetchRow('SELECT LOWER(description) as description FROM `ha_voice_names` WHERE default_feedback = 1 and deviceID ='.$deviceID)) {
			return $row['description'];
		} else if ($row = FetchRow('SELECT LOWER(description) as description FROM `ha_voice_names` WHERE deviceID ='.$deviceID)) {
				return $row['description'];		
		} else if ($row = FetchRow('SELECT LOWER(description) as description FROM `ha_mf_devices` WHERE id ='.$deviceID)) {
				return $row['description'];		
		} 
		return "no name";
}

function dateToSpeach($timestamp) {

	// echo $timestamp.CRLF;
	$today = new DateTime(); // This object represents current date/time
	$today->setTime( 0, 0, 0 ); // reset time part, to prevent partial comparison

	$match_date = DateTime::createFromFormat("Y-m-d", $timestamp );
	$match_date->setTime( 0, 0, 0 ); // reset time part, to prevent partial comparison

	$diff = $today->diff( $match_date );
	$diffDays = (integer)$diff->format( "%R%a" ); // Extract days count in interval

	switch( $diffDays ) {
		case 0:
			return "today";
			break;
		case -1:
			return "yesterday";
			break;
		case +1:
			return "for tomorrow";
			break;
		case -2:
		case -3:
		case -4:
		case -5:
		case -6:
			return "on last ".$match_date->format('l');
			break;
		default:
			return "on ".$match_date->format('F, jS');
			break;
	}
}

function findDeviceByName($find) {
	$mysql = 'SELECT deviceID, LOWER(description) as description FROM `ha_voice_names` WHERE deviceID > 0 and LOWER(description) LIKE "%'.$find.'%"'; 
	$found = FetchRows($mysql);
	if (empty($found)) {		//		Try by description
		$mysql = 'SELECT id as deviceID, LOWER(description) as description FROM `ha_mf_devices` WHERE LOWER(description) LIKE "%'.$find.'%" OR LOWER(shortdesc) LIKE "%'.$find.'%"'; 
		$found = FetchRows($mysql);
	}
	return $found;
}
?>
