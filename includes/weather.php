<?php
//define( 'DEBUG_YAHOOWEATHER', TRUE );
if (!defined('DEBUG_YAHOOWEATHER')) define( 'DEBUG_YAHOOWEATHER', FALSE );
if (!defined('DEBUG_WBUG')) define( 'DEBUG_WBUG', FALSE );

define('IMAGE_CACHE',"/images/yahoo/");

function loadWeather($station) {

	ini_set('max_execution_time',30);

	$mydeviceID = array("KBHM" => 65 , "KEET" => 66);
	$retry = 5;
        $success = False;

        while ($retry > 0 && !$success) {
            try {

            	$url= WEATHER_URL.$station.".xml";
            	$get = restClient::get($url);
            	$feedback = ($get->getresponsecode()==200 ? TRUE : FALSE);
            	if ($feedback) {
            		$xml = new SimpleXMLElement($get->getresponse());
            		UpdateWeatherNow($mydeviceID[$station], $xml->temp_c , $xml->relative_humidity);
            		UpdateWeatherCurrent($mydeviceID[$station], $xml->temp_c , $xml->relative_humidity );
            		UpdateLink (array('callerID' => MY_DEVICE_ID, 'deviceID' => $mydeviceID[$station]));
                	$success = true; 
            	}
        	}
            catch (Exception $e) {
                //Error trapping
                //My.Application.Log.WriteException(exc, TraceEventType.Error, "Error reading data from" & My.Settings.WeatherUrl & MyStation & ".xml", 301)
                 echo 'Caught exception: ',  $e->getMessage(), "\n";
			} 
			$retry = $retry - 1;
		}
	return ($success ? true : false);
}

function getYahooWeather($station) {

	ini_set('max_execution_time',30);

	$mydeviceID = array("USAL0594" => 196);
	//USAL0594

	$url = "https://query.yahooapis.com/v1/public/yql?q=select%20*%20from%20weather.forecast%20where%20location%3D%22".$station.
	"%22%20and%20u%3D%22c%22&format=json&diagnostics=true&callback=";
	$get = restClient::get($url);
//	$response = file_get_contents($url);
	if (DEBUG_YAHOOWEATHER) echo "<pre>";
	//if (DEBUG_YAHOOWEATHER) echo "response: ".$response;
	$feedback['error'] = ($get->getresponsecode()==200 ? 0 : $get->getresponsecode());
       	if (!$feedback['error']) {
		$result = json_decode($get->getresponse());
		$feedback['message'] = $result;
		//if (DEBUG_YAHOOWEATHER) print_r($result);
		if (DEBUG_YAHOOWEATHER) print_r($result);
		$result = $result->{'query'}->{'results'}->{'channel'};
		UpdateWeatherNow($mydeviceID[$station], $result->{'item'}->{'condition'}->{'temp'} , $result->{'atmosphere'}->{'humidity'});
		UpdateWeatherCurrent($mydeviceID[$station], $result->{'item'}->{'condition'}->{'temp'} , $result->{'atmosphere'}->{'humidity'} );
		$feedback['updatestatus'] = UpdateStatus($mydeviceID[$station], array( 'deviceID' => $mydeviceID[$station], 'status' => STATUS_ON, 'commandvalue' => $result->{'item'}->{'condition'}->{'temp'}));

		$array['deviceID'] = $mydeviceID[$station];
		$array['mdate'] = date("Y-m-d H:i:s",strtotime( $result->{'item'}->{'pubDate'}));
		$array['temp'] = $result->{'item'}->{'condition'}->{'temp'};
		$array['humidity'] = $result->{'atmosphere'}->{'humidity'};
		$array['pressure'] = $result->{'atmosphere'}->{'pressure'};
		$array['rising'] = $result->{'atmosphere'}->{'rising'};
		$array['visibility'] = $result->{'atmosphere'}->{'visibility'};
		$array['chill'] = $result->{'wind'}->{'chill'};
		$wd = $result->{'wind'}->{'direction'};
		if($wd>=348.75&&$wd<=360) $wdt="N";
		if($wd>=0&&$wd<11.25) $wdt="N";
		if($wd>=11.25&&$wd<33.75) $wdt="NNE";
		if($wd>=33.75&&$wd<56.25) $wdt="NE";
		if($wd>=56.25&&$wd<78.75) $wdt="ENE";
		if($wd>=78.75&&$wd<101.25) $wdt="E";
		if($wd>=101.25&&$wd<123.75) $wdt="ESE";
		if($wd>=123.75&&$wd<146.25) $wdt="SE";
		if($wd>=146.25&&$wd<168.75) $wdt="SSE";
		if($wd>=168.75&&$wd<191.25) $wdt="S";
		if($wd>=191.25&&$wd<213.75) $wdt="SSW";
		if($wd>=213.75&&$wd<236.25) $wdt="SW";
		if($wd>=236.25&&$wd<258.75) $wdt="WSW";
		if($wd>=258.75&&$wd<281.25) $wdt="W";
		if($wd>=281.25&&$wd<303.75) $wdt="WNW";
		if($wd>=303.75&&$wd<326.25) $wdt="NW";
		if($wd>=326.25&&$wd<348.75) $wdt="NNW";
		$array['direction'] = $wdt;
		$array['speed'] = $result->{'wind'}->{'speed'};
		$array['code'] = $result->{'item'}->{'condition'}->{'code'};
		$array['text'] = $result->{'item'}->{'condition'}->{'text'};
		$array['typeID'] = DEV_TYPE_TEMP_HUM;
		// Get night or day
		$tpb = time();
		$tsr = strtotime($result->{'astronomy'}->{'sunrise'});
		$tss = strtotime($result->{'astronomy'}->{'sunset'});
		if ($tpb>$tsr && $tpb<$tss) { $daynight = 'd'; } else { $daynight = 'n'; }
		$image = $result->{'item'}->{'condition'}->{'code'}.$daynight.'.png';
		cache_image(IMAGE_CACHE.$image, 'http://l.yimg.com/a/i/us/nws/weather/gr/'.$image);
		$array['link1'] = IMAGE_CACHE.$image;

		$array['class'] = "";
		if ($daynight == "d") {
			$array['class'] = "w-day";
		} else {
			$array['class'] = "w-night";
		}
		$row = FetchRow("SELECT `severity` FROM `ha_weather_codes` WHERE `code` =".$result->{'item'}->{'condition'}->{'code'});
		if ($row['severity'] == SEVERITY_DANGER) {
			$array['class'] = SEVERITY_DANGER_CLASS;
		}
		if ($row['severity'] == SEVERITY_WARNING) {
			$array['class'] = SEVERITY_WARNING_CLASS;
		}
		PDOupdate("ha_weather_extended", $array, array( 'deviceID' => $mydeviceID[$station]));
	
		unset($array);
		$i = 0;
		foreach ($result->{'item'}->{'forecast'} as $forecast) {
			//print_r($forecast);
			$array['deviceID'] = $mydeviceID[$station];
			$array['mdate'] = date("Y-m-d H:i:s",strtotime($forecast->{'date'}));
			$array['day'] = $forecast->{'day'};
			//if ($i == 0) $array['day'] = $array['day'];
			//if ($i == 1) $array['day'] = "Tomorrow";
			$array['low'] = $forecast->{'low'};
			$array['high'] = $forecast->{'high'};
			$array['text'] = $forecast->{'text'};
			$array['code'] = $forecast->{'code'};
			$image = $forecast->{'code'}.'s.png';
			cache_image(IMAGE_CACHE.$image, 'http://l.yimg.com/a/i/us/nws/weather/gr/'.$image);
			$array['link1'] = IMAGE_CACHE.$image;
			$row = FetchRow("SELECT `severity` FROM `ha_weather_codes` WHERE `code` = ".$forecast->{'code'});
			$array['class'] = "";
			if ($row['severity'] == SEVERITY_DANGER) {
				$array['class'] = SEVERITY_DANGER_CLASS;
			}
			if ($row['severity'] == SEVERITY_WARNING) {
				$array['class'] = SEVERITY_WARNING_CLASS;
			}
			PDOupdate("ha_weather_forecast", $array, array('id' => $i));
//			PDOinsert("ha_weather_forecast", $array);
			$i++;
		}

   		UpdateLink (array('callerID' => MY_DEVICE_ID, 'deviceID' => $mydeviceID[$station]));
	}

	if (DEBUG_YAHOOWEATHER) echo "</pre>";
	
	return $feedback;
	
}
	

	
function getWBUG($station) {

	$mydeviceID = array("HOOVR" => 196);
	
	$row = FetchRow("SELECT * FROM ha_mi_oauth20 where id ='WBUG'");
	//https://thepulseapi.earthnetworks.com/oauth20/token?grant_type=client_credentials&client_id=XtlIwGloXerWOgENDDkXp2qeGji0v3uX&client_secret=JSok7jX6boeSS8t7
	//{"OAuth20":{"access_token":{"token":"efbc8548b81843a5a81ead3dfb3d","refresh_token":"efbc8548b81843a5a81ead3dfb3d","token_type":"bearer","expires_in":86399}}}
	$burl = "https://thepulseapi.earthnetworks.com/oauth20/token";
	if (DEBUG_WBUG) echo '<pre>';
	$params['grant_type'] = "client_credentials";
	$params['client_id'] = $row['clientID'];
	$params['client_secret'] = $row['secret'];
	if (DEBUG_WBUG) print_r($params);
	
	
	$url = $burl."?grant_type=client_credentials&client_id=".$row['clientID']."&client_secret=".$row['secret'];
	//$get = restClient::get($url);
	$response = file_get_contents($url);
	if (DEBUG_WBUG) echo "response: ".$response;
	$result = json_decode( $response );
	if (DEBUG_WBUG) print_r($result);

	unset($params);
	//https://thepulseapi.earthnetworks.com/data/observations/v3/current?providerid=3&stationid=HOOVR&units=metric&cultureinfo=en-en&verbose=true&access_token=setuk1wAqDXmUT3JY44QA1BQsxyj
	$burl = "https://thepulseapi.earthnetworks.com/data/observations/v3/current";
	$params['providerid'] = 3;
	$params['stationid'] = $station;
	$params['units'] = "metric";
	$params['cultureinfo'] = "en-en";
	$params['verbose'] = "true" ;  
	$params['access_token'] = $result->{'OAuth20'}->{'access_token'}->{'token'};
	$url = $burl."?providerid=3&stationid=".$params['stationid']."&units=metric&cultureinfo=en-en&verbose=true&access_token=".$params['access_token'];
	//"https://thepulseapi.earthnetworks.com/data/observations/v3/current?providerid=3&stationid=HOOVR&units=metric&cultureinfo=en-en&verbose=true&access_token=2988eb34e2f640d9a98e20b36486"
	$response = file_get_contents($url);
	if (DEBUG_WBUG) echo "response: ".$response;
	
	//if (DEBUG_WBUG) print_r($params);
	//$get = restClient::get($url, $params);
	//$feedback['error'] = ($get->getresponsecode()==200 ? 0 : $get->getresponsecode());
	//$feedback['message'] = trim($get->getresponse());
	//if (DEBUG_WBUG) echo $feedback['message'].CRLF;
	
	unset ($result);
	$result = json_decode( $response );
	if (DEBUG_WBUG) print_r($result);
	if (DEBUG_WBUG) echo CRLF;
	if (DEBUG_WBUG) echo "temp: ".$result->{'observation'}->{'temperature'}.CRLF;
	if (DEBUG_WBUG) echo "humi: ".$result->{'observation'}->{'humidity'}.CRLF;
	UpdateWeatherNow($mydeviceID[$station], $result->{'observation'}->{'temperature'} , $result->{'observation'}->{'humidity'});
	UpdateWeatherCurrent($mydeviceID[$station], $result->{'observation'}->{'temperature'} , $result->{'observation'}->{'humidity'} );
	$feedback['updatestatus'] = UpdateStatus($mydeviceID[$station], array( 'deviceID' => $mydeviceID[$station], 'status' => STATUS_ON, 'commandvalue' => $result->{'observation'}->{'temperature'}));
	UpdateLink (array('callerID' => MY_DEVICE_ID, 'deviceID' => $mydeviceID[$station]));

	return $feedback;
	
	}

//function cache_image($file, $url, $hours = 168, $fn = '', $fn_args = '') {
function cache_image($file, $url, $hours = 168) {
	//vars

	$file = $_SERVER['DOCUMENT_ROOT'].$file ;

	$current_time = time(); 
	$expire_time = $hours * 60 * 60; 
	$file_time = filemtime($file);

	if(file_exists($file) && ($current_time - $expire_time < $file_time)) {
		//echo 'returning from cached file';
		return true;
	}
	else {
		$content = get_url($url);
//		if($fn) { $content = $fn($content,$fn_args); }
//		$content.= '<!-- cached:  '.time().'-->';
		file_put_contents($file, $content);
		//echo 'retrieved fresh from '.$url.':: '.$content;
		return true;
	}
}

function get_url($url) {
	$ch = curl_init();
	curl_setopt($ch,CURLOPT_URL,$url);
	curl_setopt($ch,CURLOPT_RETURNTRANSFER,1); 
	curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,5);
	$content = curl_exec($ch);
	curl_close($ch);
	return $content;
}
?>
