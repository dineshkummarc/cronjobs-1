#!/usr/bin/php
<?php
require_once 'includes.php';
define("DEBUG_MODE", false);
//$GLOBALS['debug'] = 10;
define("MY_DEVICE_ID", 215);
define("MAX_FILES_DIR", 1000);
define("MOTION_URL_DESKTOP",SERVER_HOME."/index.php?option=com_content&amp;view=article&amp;id=238&amp;Itemid=30");
define("MOTION_URL_PHONE",SERVER_HOME."/index.php?option=com_content&view=article&id=238&Itemid=527");
$cameras = readCameras();
echo "Initialize...\n";
if (!DEBUG_MODE) {
	declare(ticks=1); // PHP internal, make signal handling work
	pcntl_signal(SIGTERM, "signal_handler");
	pcntl_signal(SIGHUP, "signal_handler");
	pcntl_signal(SIGINT, "signal_handler");
}
while (1) {
	foreach ($cameras AS $key => $camera) {
//		echo "in".$cameras[$key]['lastfiletime'].CRLF;
		if (!array_key_exists('lastfiletime', $camera)) $cameras[$key]['lastfiletime'] = 0;
		$basedir = LOCAL_CAMERAS.$camera['previous_properties']['Directory']['value'].'/';
		$indir = LOCAL_CAMERAS.$camera['previous_properties']['Directory']['value'].'/';
		$cameras[$key] = movePictures($cameras[$key], $basedir, $indir);

		if (array_key_exists('Sound Directory', $camera['previous_properties'])) {
			$indir = LOCAL_CAMERAS.$camera['previous_properties']['Sound Directory']['value'].'/';
			$cameras[$key] = movePictures($cameras[$key], $basedir, $indir);
		}

//		echo "out".$cameras[$key]['lastfiletime'].CRLF;
	}
	sleep(5);
    updateDLink(MY_DEVICE_ID);
}
function readCameras() {
	$mysql = 'SELECT id AS deviceID,  description FROM ha_mf_devices 
			  WHERE ha_mf_devices.typeID = '.DEV_TYPE_CAMERA. ' AND inuse = 1';
			  
	$camrows = FetchRows($mysql);
	foreach ($camrows as $cam) {
		$cameras[] = getDevice($cam['deviceID']);			// TODO:: Add where to getDevice
		$cameras[getLastKey($cameras)]['previous_properties'] = getDeviceProperties(Array('deviceID' => $cam['deviceID']));
	}
	//
	// print_r($cameras);
	return $cameras;
}
function movePictures($camera, $basedir, $indir) {
	$files = array();
	$filetimes = array();


	$lastfiletime = $camera['lastfiletime'];
	if ($handle = opendir($indir)) {
		while (false !== ($file = readdir($handle))) {
			$file_parts = mb_pathinfo($file);
			if ($file != "." && $file != ".." && !is_dir($indir.$file) && strtolower($file_parts['extension'])=='jpg') {
				$files[] = $file;
				$filetimes[] = filemtime($indir.$file);
			}
		}
		closedir($handle);
		if (count($files) == 0) {
			// echo date("Y-m-d H:i:s").": ".$camera['description']." Nothing to do.".CRLF;
			return $camera;
		}
		
  // echo '<pre>';
  // print_r($files);
  // print_r($filetimes);
  // echo '</pre>';
		//array_multisort($filetimes, $files); 
		asort($files);
		//echo '<pre>';
		//print_r($files);
		//print_r($filetimes);
//echo "</pre>";
		$seq = 0;
		$group_dir = null;
		$numfiles = 0;
		// Sleep .5 second before moving...
		sleep(10);
		foreach ($files as $index => $file) {
			$filetime = $filetimes[$index];		// Time of currently handled file
			// echo ">$file<".CRLF;
			$datedir = date ("Y-m-d", $filetime);
			if (is_null($group_dir)) {		// Did we find a group dir? If not find the last one and the num files in it
				if ($group_dir = findLastGroupDir($basedir.$datedir)) {
					$targetdir = $basedir.$datedir.'/'.$group_dir;
					// echo "Add to existing directory ".$targetdir.CRLF;
					$numfiles  = iterator_count(new DirectoryIterator($targetdir)) - 2;
					$mysql = 'SELECT * FROM `ha_cam_recordings` WHERE folder ="'.$camera['previous_properties']['Directory']['value'].'/'.$datedir.'/'.$group_dir.'"';
					debug($mysql, 'mysql');
					$recording = FetchRow($mysql);
					debug($recording, 'recording');
					$camera['criticalalert'] = $recording['criticalalert'];
					$camera['highalert'] = $recording['highalert'];
					$camera['lastfiletime'] = strtotime($recording['lastfiletime']);
				} 
			}

			if ((int)(abs($filetime-$camera['lastfiletime']) / 60) >= 1 || (date("Y-m-d", $filetime) != date("Y-m-d", $camera['lastfiletime'])) || $numfiles >= MAX_FILES_DIR) {  // New Motion Group when; a) 1 minute gap, b) new day, c) max_files

				// If we had an old dir, update this first
				//$camera['newfilename'] = $newfilename;
				$camera['datedir'] = $datedir;
				$camera['group_dir'] = $group_dir;
				$camera['numfiles'] = $numfiles;
				$camera['updatetype'] = false;
				$camera = closeGroup($camera);
				// Open new group dir
				$numfiles = 0;
				$camera['numfiles'] = 0;
				$camera['criticalalert'] = false;
				$camera['highalert'] = false;
				$group_dir = date("H-i-s",$filetime);
				// echo "Create new directory ".$datedir.'/'.$group_dir.CRLF;
				$camera['group_dir'] = $group_dir;
				$camera['filetime'] = $filetime;
				$camera['indir'] = $indir;
				$camera = openGroup($camera);
				$targetdir = $basedir.$datedir.'/'.$group_dir;
				if (!file_exists($basedir)) {
					mkdir($basedir);
				}
				if (!file_exists($basedir.$datedir)) {
					mkdir($basedir.$datedir);
				}
				if (!file_exists($targetdir)) {
					mkdir($targetdir);
					// echo "TargetDir: ".$targetdir.CRLF;
				}
				if (!file_exists($targetdir.'/vsig_thumbs')) {
					mkdir($targetdir.'/vsig_thumbs');
					echo "TargetDir: ".$targetdir.'/vsig_thumbs'.CRLF;
				}
			} 
			if ($camera['lastfiletime'] != $filetime) $seq = 0;		// Handle multiple files per second
			$camera['lastfiletime'] = $filetime;
			$newfilename = $targetdir.'/'.$file;
			// echo $indir.$file.'->';
			// echo $newfilename."\n";
			rename($indir.$file, $newfilename);
			makeThumbs($file, $targetdir, $newfilename);
			$numfiles++;
		}	// Handled all file in old to new order
		echo $camera['description']." Creating Thumbnail.".CRLF;
		if (!file_exists(LOCAL_LASTIMAGEDIR)) {
			mkdir(LOCAL_LASTIMAGEDIR);
		}
		$thumbname = LOCAL_LASTIMAGEDIR.'/'.trim($camera['description']).'.jpg';
		createthumb($newfilename,$thumbname,200,200, date('Y-m-d H:i:s'));
		// Close group (for now)
		$camera['datedir'] = $datedir;
		$camera['group_dir'] = $group_dir;
		$camera['numfiles'] = $numfiles;
		$camera['updatetype'] = true;
		$camera = closeGroup($camera);
		return $camera;
	}  
	return $camera;
}
function openGroup($camera) {
			echo date("Y-m-d H:i:s").": ".$camera['description']." Create new group directory.".CRLF;
			debug($camera, 'camera');
			$htmllong='<a href="'.MOTION_URL_DESKTOP.'&amp;folder='.$camera['previous_properties']['Directory']['value'].'/'.$camera['datedir'].'/'.$camera['group_dir'].'">Recording</a>';
			// update device
			$params['callerID'] = MY_DEVICE_ID;
			$params['device'] = $camera;
			$params['deviceID'] = $camera['id'];
			$params['caller'] = $params;
			$properties['Pictures']['value'] = $camera['numfiles'];
			$properties['Lastest Recording']['value'] = $htmllong;
			$properties['Recording']['value'] = STATUS_ON;
			$params['device']['properties'] = $properties;
//			echo sendCommand($params); cannot with resend recording command
			// Be careful any triggers will be executed on changed properties
			$feedback['updateDeviceProperties'] = updateDeviceProperties($params); 
			//logEvent(array('inout' => COMMAND_IO_SEND, 'callerID' => $params['callerID'], 'deviceID' => $params['deviceID'], 'commandID' => COMMAND_LOG_EVENT, 'result' => $feedback));
			debug($feedback, 'feedback');
			if (array_key_exists('Recording Type', $camera['previous_properties'])) {
				$recording['recording_typeID'] = $camera['previous_properties']['Recording Type']['value'];
			} elseif (strpos($camera['indir'],'snap') === false) {
				$recording['recording_typeID'] = RECORDING_TYPE_MOTION_CAMERA;
			} else {
				$recording['recording_typeID'] = RECORDING_TYPE_SOUND_CAMERA;
				$feedback['ExecuteCommand:'.COMMAND_SET_PROPERTY_VALUE]=executeCommand(array('callerID' => $params['callerID'], 'messagetypeID' => MESS_TYPE_COMMAND, 'deviceID' => $params['deviceID'], 'commandID' => COMMAND_SET_PROPERTY_VALUE, 'commandvalue' => "Sound___1"));
				$feedback['ExecuteCommand:'.COMMAND_SET_PROPERTY_VALUE]=executeCommand(array('callerID' => $params['callerID'], 'messagetypeID' => MESS_TYPE_COMMAND, 'deviceID' => $params['deviceID'], 'commandID' => COMMAND_SET_PROPERTY_VALUE, 'commandvalue' => "Sound___0"));
			}
			$recording['cam'] = $params['deviceID'];
			$recording['mdate'] = date ("Y-m-d").'_';
			$recording['event'] = $camera['group_dir'];
			$recording['folder'] = $camera['previous_properties']['Directory']['value'].'/'.$camera['datedir'].'/'.$camera['group_dir'];
			$recording['firstfiletime'] = date ("H:i:s",$camera['filetime']);
			unset($recording['id']);
			PDOinsert('ha_cam_recordings', $recording);
	return $camera;
}
function closeGroup($camera) {
			echo $camera['description']." Closing directory.".($camera['updatetype'] ? ". Update True." : "Update False.")." Files: ".$camera['numfiles'].CRLF;
			debug($camera, 'camera');
			// update device
			$htmlshort=MOTION_URL_PHONE.'&folder='.$camera['previous_properties']['Directory']['value'].'/'.$camera['datedir'].'/'.$camera['group_dir'].'"';
			$htmllong='<a href="'.MOTION_URL_DESKTOP.'&folder='.$camera['previous_properties']['Directory']['value'].'/'.$camera['datedir'].'/'.$camera['group_dir'].'">Recording</a>';
			$params['callerID'] = MY_DEVICE_ID;
			$params['device'] = $camera;
			$params['deviceID'] = $camera['id'];
			$params['caller'] = $params;
			$properties['Pictures']['value'] = $camera['numfiles'];
			$properties['Lastest Recording']['value'] = $htmllong;
			$properties['Last File Time']['value'] = date("H:i:s",$camera['lastfiletime']); 
			if ($camera['updatetype']) {		// Temp group closing at the end of moving files 
				$properties['Recording']['value'] = STATUS_OFF;
				// Only handle alarm at this point, do not want to generate old alarm form previous group and in meanwhile $nobodyhome = off
				$nobodyhome = getDeviceProperties(Array('deviceID' => DEVICE_SOMEONE_HOME, 'description' => 'Status'))['value'] == "0";
				if ($nobodyhome && !$camera['criticalalert'] && !empty($camera['previous_properties']['Cam Pics Critical Alert']['value']) && $camera['numfiles'] >= $camera['previous_properties']['Cam Pics Critical Alert']['value'])  {
					echo $camera['description']." Creating Critical Alert.".CRLF;
					executeCommand(array('callerID' => MY_DEVICE_ID, 'messagetypeID' => MESS_TYPE_COMMAND, 'deviceID' => $params['deviceID'], 'commandID' => COMMAND_SET_PROPERTY_VALUE, 'commandvalue' => "Critical Alert___1", 'htmllong' => $htmllong, 'htmlshort' => $htmlshort));
					$camera['criticalalert'] = true;
					$camera['highalert'] = true;
					$feedback['ExecuteCommand:'.COMMAND_SET_PROPERTY_VALUE]=executeCommand(array('callerID' => $params['callerID'], 'messagetypeID' => MESS_TYPE_COMMAND, 'deviceID' => $params['deviceID'], 'commandID' => COMMAND_SET_PROPERTY_VALUE, 'commandvalue' => "Critical Alert___0"));
					//uploadPictures($camera);
				} elseif ($nobodyhome && !$camera['highalert'] && !empty($camera['previous_properties']['Cam Pics High Alert']['value']) && $camera['numfiles'] >= $camera['previous_properties']['Cam Pics High Alert']['value'])  {
					echo $camera['description']." Creating High Alert.".CRLF;
					//print_r(array('callerID' => MY_DEVICE_ID, 'messagetypeID' => MESS_TYPE_COMMAND, 'deviceID' => $params['deviceID'], 'commandID' => COMMAND_SET_PROPERTY_VALUE, 'commandvalue' => "High Alert___1", 'htmllong' => $htmllong, 'htmlshort' => $htmlshort));
					executeCommand(array('callerID' => MY_DEVICE_ID, 'messagetypeID' => MESS_TYPE_COMMAND, 'deviceID' => $params['deviceID'], 'commandID' => COMMAND_SET_PROPERTY_VALUE, 'commandvalue' => "High Alert___1", 'htmllong' => $htmllong, 'htmlshort' => $htmlshort));
					$camera['highalert'] = true;
					$feedback['ExecuteCommand:'.COMMAND_SET_PROPERTY_VALUE]=executeCommand(array('callerID' => $params['callerID'], 'messagetypeID' => MESS_TYPE_COMMAND, 'deviceID' => $params['deviceID'], 'commandID' => COMMAND_SET_PROPERTY_VALUE, 'commandvalue' => "High Alert___0"));
					//uploadPictures($camera);
				}
			}
			$params['device']['properties'] = $properties;
			// Be careful any triggers will be executed on changed properties
			$feedback['updateDeviceProperties'] = updateDeviceProperties($params); 
			//logEvent(array('inout' => COMMAND_IO_SEND, 'callerID' => $params['callerID'], 'deviceID' => $params['deviceID'], 'commandID' => COMMAND_LOG_EVENT, 'result' => $feedback));
			debug($feedback, 'feedback');
			$recording['count'] = $camera['numfiles'];
			$recording['lastfiletime'] = date("H:i:s",$camera['lastfiletime']);
			if (array_key_exists('criticalalert',$camera)) $recording['criticalalert'] = $camera['criticalalert'];
			if (array_key_exists('highalert',$camera)) $recording['highalert'] = $camera['highalert'];
			PDOupdate("ha_cam_recordings", $recording, array('folder' => $camera['previous_properties']['Directory']['value'].'/'.$camera['datedir'].'/'.$camera['group_dir']));
	return $camera;
}
function findLastGroupDir($dir) {
// Last dir or nul if no dir found
	$files = Array();
	if (file_exists($dir)) {
		if ($handle = opendir($dir)) {
			while (false !== ($file = readdir($handle))) {
				if ($file != "." && $file != ".." && is_dir($dir.'/'.$file)) {
					$files[] = $file;
				}
			}
			closedir($handle);
			if (count($files)>0) {
				sort($files);
//print_r($files);
				return end($files);
			}
			return null;
			}
		}
	return null;
}
function uploadPictures($camera) {
//Send through create alert
	$mutedon = getDeviceProperties(Array('deviceID' => DEVICE_MOTION_MUTED, 'description' => 'Status'))['value'] == "1";
	$dir = LOCAL_CAMERAS.$camera['previous_properties']['Directory']['value'].'/';
	if ($mutedon && array_key_exists('Send Pushbullet on Alert', $camera['previous_properties']) && $camera['previous_properties']['Send Pushbullet on Alert']['value'] == 'Y') {
		$targetdir = $camera['datedir'].'/'.$camera['group_dir'];
		$files = array();
		if ($handle = opendir($dir.$targetdir)) {
			while (false !== ($file = readdir($handle))) {
				$file_parts = mb_pathinfo($file);
				if ($file != "." && $file != ".." && !is_dir($dir.$file) && strtolower($file_parts['extension'])=='jpg') {
					$files[] = $file;
					//$filetimes[] = filemtime($dir.$file);
				}
			}
			closedir($handle);
		}
	        sort($files);
 		$pb = new Pushbullet\Pushbullet(PUSHBULLET_TOKEN);
		for ($i = 1; $i <= 3; $i++) {
			if (array_key_exists($i, $files)) $pb->channel(PUSH_CHANNEL)->pushFile($dir.$targetdir."/".$files[$i],null,"Motion on ".$camera['description'],$files[$i]);
		}
	}
	return;
}
function makeThumbs($file, $targetdir, $newfilename) {
	createthumb($newfilename,$targetdir.'/vsig_thumbs/'.$file,990,990);
	createthumb($newfilename,$targetdir.'/vsig_thumbs/'.$file,160,160);
}
function signal_handler($signo){
	switch ($signo) {
	 case SIGINT:
	 	// handle restart tasks
	 	cleanup();
	 	break;
	 case SIGTERM:
	 	// handle shutdown tasks
	 	cleanup();
	 	break;
	 case SIGHUP:
	 	// handle restart tasks
	 	cleanup();
	 	break;
	 default:
	 	fprintf(STDERR, "Unknown signal ". $signo);
	}
}
function cleanup(){
	echo "Cleaning up\n";
	exit (0);
}
?>
