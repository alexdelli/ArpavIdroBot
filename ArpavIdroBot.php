<?php
class ArpavIdroBot {
	
//Telegram
	protected $host = 'api.telegram.org';
	//Port used on connection.
	protected $port = 443;
	//Url needed to access telegram (composed of protocol, port, host and token). It's automatically generated on the class construction.
	protected $apiUrl;
	//Id of the bot on telegram. [Initialized automatically]
	private $botId;
	//Username of the bot. [Initialized automatically]
	private $botUsername;
	//Bot token received from telegram on bot creation.
	protected $botToken = 'replaceWithToken';
	//cURL handle.
	protected $handle;
	//Delay, in secnds, on connection failures. 
	protected $netDelay = 5;
	//Used to mark updates as received.
	protected $updatesOffset = false;
	//Limit number of updates fot one update request.
	protected $updatesLimit = 30;
	//Timeout in (seconds) for long polling.
	protected $updatesTimeout = 10;
	//The maximum number of (seconds) to allow cURL functions to execute.
	protected $netTimeout = 10;
	//The number of (seconds) to wait while trying to connect. 
	protected $netConnectTimeout = 30;
	//(seconds)Time interval at which check the water levels of the subscribed stations. (And send messages to subscribed users on criticality change.)
	protected $spamUpdateInterval = 300;
	
	
	//CORE - BEGIN//
	
	
	/**
	 * Constructor.
	 */
	public function __construct() {
		$host = $this->host;
		$port = $this->port;
		$token = $this->botToken;
		$protocol_part = ($port == 443 ? 'https' : 'http');
		$port_part = ($port == 443 || $port == 80) ? '' : ':'.$port;
		$this->apiUrl = "{$protocol_part}://{$host}{$port_part}/bot{$token}";
	}
	
	
	/**
	 * Starts the bot.
	 */
	public function start(){
		$this->initialize();
		$this->run();
	}
	
	
	/**
	 * Initializes $botId and $botUsername variables, retrives them from Telegram.
	 */
	private function initialize() {
		$response = array();
		$firstTry = TRUE;
		do{
			if($firstTry){
				$firstTry = FALSE;
			}else{
				sleep(1);
			}
			$this->handle = curl_init();
			$response = $this->request('getMe');
			$this->log('Connecting to Telegram.');//LOG
			
		}while(!$response['ok']);
		$this->log('Connected.');//LOG
		$botInfo = $response['result'];
		$this->botId = $botInfo['id'];
		$this->botUsername = $botInfo['username'];
		$this->log('Bot initialized.');//LOG
	}
	
	
	/**
	 * Start long poll requests to Telegram, invoke {@link #receiveUpdate($update)} for each update.
	 * Check every ($spamUpdateInterval) seconds if the water levels of the stations signed by someone have changed the state of criticality.
	 * And for every station (with changed criticality status) sends a message to all of the users subscribed to it.
	 */
	private function run(){
		$params = array(
			'limit' => $this->updatesLimit,
			'timeout' => $this->updatesTimeout,
		);
		$options = array(
			'timeout' => $this->netConnectTimeout + $this->updatesTimeout + 2,
		);
		$time = time();
		while(True){
			if ($this->updatesOffset) {
				$params['offset'] = $this->updatesOffset;
			}
			$response = $this->request('getUpdates', $params, $options);
			if ($response['ok']) {
				$updates = $response['result'];
				if (is_array($updates)) {
					foreach ($updates as $update) {
						$this->updatesOffset = $update['update_id'] + 1;
						$this->receiveUpdate($update);
					}
				}
			}
			if ((time() - $time) >= $this->spamUpdateInterval) {
			  $this->sendInfoToFollowers();
				$time = time();
			}
		}//while
	}//run
	
	
	/**
	 * Automatically invoked for each update from telegram. It analyzes command received from user.
	 * If there is a command match, the corresponding method for the command {command_[command name]}is invoked.
	 * If no command match is found, the response to Telegram is sent from this method.
	 * @param $update Associative array with information from telegram.
	 */
	private function receiveUpdate($update){
		if ($update['message']) {
			$message = $update['message'];
			$chat_id = intval($message['chat']['id']);
			if($chat_id) {
				if(isset($message['text'])){
					$text = trim($message['text']);
					$this->log('Function: receiveUpdate; $message[\'text\']: '.$message['text'].PHP_EOL.'$chatId: '.$chat_id);//LOG
					$username = strtolower('@'.$this->botUsername);
					$username_len = strlen($username);
					if(strtolower(substr($text, 0, $username_len)) == $username) {
						$text = trim(substr($text, $username_len));
					}
					if(preg_match('/^(?:\/(?:([a-z0-9_]+)(?=@)|([a-z0-9_]+?))(@[a-z0-9]+)?(?:[\s_]+(.*))?)$/is', $text, $matches)) {
						$command = empty($matches[1])?$matches[2]:$matches[1];
						$command_owner = !empty($matches[3])?strtolower($matches[3]):'';
						$command_params = !empty($matches[4])?$matches[4]:'';
						if (empty($command_owner) || $command_owner == $username) {
							//Command to this bot.
							$method = 'command_'.$command;
							if (method_exists($this, $method)){
								//Requested existent command.
								$command_params=str_replace('_', ' ', $command_params);
								$arrayOfParams = array_filter(explode(' ', $command_params), 'strlen' );
								$this->$method($arrayOfParams, $message);
							}else{
								//Requested inexistent command.
								$this->sendTextMessage($this->info_inexistentCommand(), $chat_id);
							}
						}else{
							//Command not to this bot.
						}
					}else{
						//Generic phrase.
						$this->sendTextMessage($this->getAvailableCommandsPhrase(), $chat_id); 
					}
				}elseif(isset($message['location'])){
					$this->saveLocation($message);
				}
			}
		}
	}
	
	
	/**
	 * Used to invoke methodhs on telegram.
	 * @param method name of the method to invoke.
	 * @param params parameters to pass to the method of telegram.
	 * @param options options for curl request.
	 * @return associative array containing response or null. 
	 */
	private function request($method, $params = array(), $options = array()) {
		$options += array(
			'http_method' => 'GET',
			'timeout' => $this->netTimeout,
		);
		$params_arr = array();
		foreach ($params as $key => &$val) {
			if (!is_numeric($val) && !is_string($val)) {
				$val = json_encode($val);
			}
			$params_arr[] = urlencode($key).'='.urlencode($val);
		}
		$query_string = implode('&', $params_arr);
		$url = $this->apiUrl.'/'.$method;
		if ($options['http_method'] === 'POST') {
			curl_setopt($this->handle, CURLOPT_SAFE_UPLOAD, false);
			curl_setopt($this->handle, CURLOPT_POST, true);
			curl_setopt($this->handle, CURLOPT_POSTFIELDS, $query_string);
		} else {
			$url .= ($query_string ? '?'.$query_string : '');
			curl_setopt($this->handle, CURLOPT_HTTPGET, true);
		}
		$connect_timeout = $this->netConnectTimeout;
		$timeout = $options['timeout'] ?: $this->netTimeout;
		curl_setopt($this->handle, CURLOPT_URL, $url);
		curl_setopt($this->handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->handle, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
		curl_setopt($this->handle, CURLOPT_TIMEOUT, $timeout);
		$response_str = curl_exec($this->handle);
		$errno = curl_errno($this->handle);
		$http_code = intval(curl_getinfo($this->handle, CURLINFO_HTTP_CODE));
		if ($http_code == 401) {
			throw new Exception('Invalid bot token.');
		} else {
			if ($http_code >= 500 || $errno) {
				sleep($this->netDelay);
			}
		}
		$response = json_decode($response_str, true);
		return $response;
	}
	
	
	/**
	 * Sends a text message to telegram.
	 * @param $text Text to send (with optional formatting in HTML style).
	 * @param $chatId Identifier (received from Telegram) of the message destination chat.
	 * @param $params Parameters for the method (higher priority than other parameters).
	 */
	private function sendTextMessage($text, $chatId, $params = array()) {
		$maxTextLength = 3600;
		$textLength = strlen($text);
		if($textLength>$maxTextLength){
			while(strlen($text) != 0){
				$cut = strrpos(substr($text, 0, $maxTextLength), PHP_EOL) + 1;
				$textPart = substr($text, 0, $cut);
				$text = substr($text, $cut);
				$paramsSend =$params + array(
					'chat_id' => $chatId,
					'parse_mode' => 'HTML',
					'text' => $textPart,
				);
				$this->request('sendMessage', $paramsSend);
			}
		}else{
			$params += array(
				'chat_id' => $chatId,
				'parse_mode' => 'HTML',
				'text' => $text,
			);
			$this->request('sendMessage', $params);
		}
	}
	
	
	/**
	 * Sends a location to telegram.
	 * @param $latitude Latitude of the location.
	 * @param $longitude Longitude of the location.
	 * @param $chatId Identifier (received from Telegram) of the message destination chat.
	 * @param $params Parameters for the method (higher priority than other parameters).
	 */
	private function sendLocation($latitude, $longitude, $chatId, $params = array()) {
		$params += array(
			'chat_id' => $chatId,
			'latitude' => $latitude,
			'longitude' => $longitude,
		);
		$this->request('sendLocation', $params);
	}
	
	/**
	 * Sends a photo to telegram.
	 * @param $photo Url of the photo to send.
	 * @param $chatId Identifier (received from Telegram) of the message destination chat.
	 * @param $caption Caption for the photo.
	 * @param $params Parameters for the method (higher priority than other parameters).
	 */
	private function sendPhoto($photo, $chatId, $caption='', $params = array()) {
		$params += array(
			'chat_id' => $chatId,
			'photo' => $photo,
			'caption' => $caption,
		);
		return (boolean)$this->request('sendPhoto', $params);
	}
	
	
	//CORE - END//
	
	
	//COMMANDS - BEGIN//
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user - detailed information about one station.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_stazione($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		if(!empty($arrayOfParams) && count($arrayOfParams)==1){
			$stationId = ltrim($arrayOfParams[0], '0');
			if($this->itCanBeValidStationId($stationId)){
				$location = $this->getLocation($telegramUserId);
				$sendPhoto = FALSE;
				$textToSend = $this->getWaterLevels($stationId, $location, $commandName, $telegramUserId, $sendPhoto);
				$this->sendTextMessage($textToSend, $chat_id);
				if($sendPhoto){
					$photo=$this->getHydroStationGraphicURL($stationId);
					$photoSent=$this->sendPhoto($photo, $chat_id, 'Stazione: '.$stationId.'. Grafico degli andamenti.');		
					if(!$photoSent){
						$photoLink = 'Grafico degli andamenti:'.PHP_EOL;
						$photoLink.= $this->getHydroStationGraphicURL($stationId);
						$this->sendTextMessage($photoLink, $chat_id);
					}
				}
			}else{
				$toSend=$this->info_wrongStationId();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);
			}
		}else{
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user - information about his location.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_posizione($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(empty($arrayOfParams)){
			include('connect_DB.php');
			if(!$mysqli->connect_error){
				$telegramUserId = intval($message['from']['id']);
				if ($result = $mysqli->query("CALL p_getLocation(\"$telegramUserId\")")) {
					$location = array();
					if($row = $result->fetch_assoc()){
						$location['latitude']= $row['latitude'];
						$location['longitude']= $row['longitude'];
					}
					if(!empty($location)){
						$this->sendTextMessage($this->show_location($location), $chat_id);
						$this->sendLocation($location['latitude'], $location['longitude'], $chat_id);
					}else{
						$this->sendTextMessage($this->info_noLocation(), $chat_id);
					}
				}else{
					//Error on procedure call.
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
			}else{
				//Error on connection to database.
				$this->sendTextMessage($this->info_genericError(), $chat_id);
				$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
			$mysqli->close();
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			$toSend=$this->info_wrongCommandSyntax();
			$toSend.=$this->getSyntaxForCommand($commandName);
			$toSend.=$this->getHelpMessageFor($commandName);
			$this->sendTextMessage($toSend, $chat_id);	
		}
	}
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user - list of all hydro stations.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_stazioni($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		$location = $this->getLocation($telegramUserId);
		if(empty($arrayOfParams)){
			$stations = $this->getStations($location);
			if(!empty($location)){
				$this->orderByDistance($stations);
			}else{
				$this->orderByName($stations);
			}
			$toSend = $this->getMessageAllStationsList().''.PHP_EOL;
			$toSend.= $this->show_stations($stations);
			$this->sendTextMessage($toSend, $chat_id);
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			$toSend=$this->info_wrongCommandSyntax();
			$toSend.=$this->getSyntaxForCommand($commandName);
			$toSend.=$this->getHelpMessageFor($commandName);
			$this->sendTextMessage($toSend, $chat_id);
		}
	}
	

	/**
	 * Command invoked from receiveUpdate method to send to Telegram user - list of hydro stations in given range.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_stazionikm($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		$location = $this->getLocation($telegramUserId);
		if(count($arrayOfParams)==1 || count($arrayOfParams)==2){
			if(!empty($location)){
				$rangeKm = $this->getValidKm($arrayOfParams[0]);
				if($rangeKm !== FALSE){
					if($this->isInRangeKm($rangeKm)){
						$rangeMeters = $rangeKm*1000;
						$stations = $this->getStations($location);
						$this->orderByDistance($stations);
						$this->filterStationsNotInRange($stations, $rangeMeters);
						if(count($stations)!=0){
							$toSend = $this->getMessageStationsInRangeList($rangeMeters).PHP_EOL;
							$toSend.= $this->show_stations($stations);
							$this->sendTextMessage($toSend, $chat_id);
						}else{
							$toSend = $this->info_noStationsInRange($rangeMeters).PHP_EOL;
							$this->sendTextMessage($toSend, $chat_id);
						}
					}else{
						$this->sendTextMessage($this->info_numberOutOfRange(), $chat_id);
					}
				}else{
					$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
					$toSend=$this->info_wrongCommandSyntax();
					$toSend.=$this->getSyntaxForCommand($commandName);
					$toSend.=$this->getHelpMessageFor($commandName);
					$this->sendTextMessage($toSend, $chat_id);
				}
			}else{
				$this->sendTextMessage($this->info_locationRequired(), $chat_id);
			}
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}

	
	/**
	 * Command invoked from receiveUpdate method to subscribe Telegram user to the hydro stations in given range and send the outcome message.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_seguikm($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		$location = $this->getLocation($telegramUserId);
		if(!empty($arrayOfParams) && count($arrayOfParams)==1){
			if(!empty($location)){
				$rangeKm = $this->getValidKm($arrayOfParams[0]);
				if($rangeKm !== FALSE){
					if($this->isInRangeKm($rangeKm)){
						$rangeMeters = $rangeKm*1000;
						$stations = $this->getStations($location);
						$this->orderByDistance($stations);
						$this->filterStationsNotInRange($stations, $rangeMeters);
						$this->filterStationsNotIdroNotCriticalityLevels($stations);
						if(count($stations)!=0){
							//Registration to stations.
							include('connect_DB.php');
							$numIscrizioni = 0;
							$numDuplicati = 0;
							$error = FALSE;
							foreach($stations as $station){
								if(!$mysqli->connect_error){
									$stationId = $station['stationId'];
									$telegramUserId = intval($message['from']['id']);
									if ($mysqli->query("SET @esito = ''") && $mysqli->query("CALL p_insertRegistration(\"$telegramUserId\", \"$chat_id\", \"$stationId\", @esito)")) {
										$res = $mysqli->query("SELECT @esito as esito");
										if ($res) {
											$row = $res->fetch_assoc();
											$esito = $row['esito'];
											if($esito==1){
												$numIscrizioni++;
											}else{
												$numDuplicati++;
											}
										}else{
											//Error on fetch from Mysql variable.
											$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
											$error = TRUE;
											break;
										}
									}else{
										//Error on procedure call or on Mysql variable creation.
										$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
										$error = TRUE;
										break;
									}
								}else{
									//Database connection error.
									$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
									$error = TRUE;
									break;
								}
							}
							if($error){
								$this->sendTextMessage($this->info_genericError(), $chat_id);
							}else{
								$toSend=$this->info_subscribedToStations(count($stations), $numIscrizioni, $numDuplicati, $rangeMeters).PHP_EOL;
								$toSend.=$this->getSubscribedStationsPhrase();
								$this->sendTextMessage($toSend, $chat_id);
							}
							$mysqli->close();
						}else{
							$this->sendTextMessage($this->info_noHydroWithCriticalityLevelsInRange(), $chat_id);
						}
					}else{
						$this->sendTextMessage($this->info_numberOutOfRange(), $chat_id);
					}
				}else{
					$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
					$toSend=$this->info_wrongCommandSyntax();
					$toSend.=$this->getSyntaxForCommand($commandName);
					$toSend.=$this->getHelpMessageFor($commandName);
					$this->sendTextMessage($toSend, $chat_id);				}
			}else{
				$this->sendTextMessage($this->info_locationRequired(), $chat_id);
			}
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to subscribe Telegram user to indicated hydro station and send the outcome message.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_segui($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(!empty($arrayOfParams) && count($arrayOfParams)==1){
			$stationId = ltrim($arrayOfParams[0], '0');
			if($this->itCanBeValidStationId($stationId)){
				if($this->haveCriticalityLevels($stationId)){
					include('connect_DB.php');
					if(!$mysqli->connect_error){
						$telegramUserId = intval($message['from']['id']);
						if ($mysqli->query("SET @esito = ''") && $mysqli->query("CALL p_insertRegistration(\"$telegramUserId\", \"$chat_id\", \"$stationId\", @esito)")) {
							$res = $mysqli->query("SELECT @esito as esito");
							if ($res) {
								$row = $res->fetch_assoc();
								$esito = $row['esito'];
								if($esito==1){
									$this->sendTextMessage($this->info_registrationSuccess(), $chat_id);
								}else{
									//Duplicate registration.
									$this->sendTextMessage($this->info_registrationDuplicate(), $chat_id);
								}
							}else{
								//Error on fetch from Mysql variable.
								$this->sendTextMessage($this->info_genericError(), $chat_id);
								$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
							}
						}else{
							//Error on procedure call or on Mysql variable creation.
							$this->sendTextMessage($this->info_genericError(), $chat_id);
							$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
						}
					}else{
						//Database connection error.
						$this->sendTextMessage($this->info_genericError(), $chat_id);
						$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
					}
					$mysqli->close();
				}else{
					$this->sendTextMessage($this->info_noHydroWithCriticalityLevels(), $chat_id);
				}
			}else{
				$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
				$toSend=$this->info_wrongStationId();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);
			}
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to unsubscribe Telegram user from indicated hydro station and send the outcome message.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_nonseguire($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(!empty($arrayOfParams) && count($arrayOfParams)==1){
			$stationId = ltrim($arrayOfParams[0], '0');
			if($this->itCanBeValidStationId($stationId)){
				include('connect_DB.php');
				if(!$mysqli->connect_error){
					$telegramUserId = intval($message['from']['id']);
					if ($mysqli->query("SET @esito = ''") && $mysqli->query("CALL p_removeRegistration(\"$telegramUserId\", \"$stationId\", @esito)")) {
						$res = $mysqli->query("SELECT @esito as esito");
						if ($res) {
							$row = $res->fetch_assoc();
							$esito = $row['esito'];
							if($esito==3){
								$this->sendTextMessage($this->info_unRegistrationSuccess(), $chat_id);
							}elseif($esito==2){
								$this->sendTextMessage($this->info_notRegistered(), $chat_id);
							}elseif($esito==1 || $esito==0){
								$this->sendTextMessage($this->info_notRegisteredToAnyStation(), $chat_id);
							}
						}else{
							//Error on fetch from Mysql variable.
							$this->sendTextMessage($this->info_genericError(), $chat_id);
							$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
						}
					}else{
						//Error on procedure call or on Mysql variable creation.
						$this->sendTextMessage($this->info_genericError(), $chat_id);
						$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
					}
				}else{
					//Database connection error.
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
				$mysqli->close();
			}else{
				$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
				$toSend=$this->info_wrongStationId();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);
			}
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to unsubscribe Telegram user from all hydro stations and send the outcome message.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_nonseguirenessuno($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(empty($arrayOfParams)){
			include('connect_DB.php');
			if(!$mysqli->connect_error){
				$telegramUserId = intval($message['from']['id']);
				if ($mysqli->query("SET @esito = ''") && $mysqli->query("CALL p_removeAllRegistrations(\"$telegramUserId\", @esito)")) {
					$res = $mysqli->query("SELECT @esito as esito");
					if ($res) {
						$row = $res->fetch_assoc();
						$esito = $row['esito'];
						if($esito!=0){
							$this->sendTextMessage($this->info_unregistrationSuccessStations($esito), $chat_id);
						}else{
							$this->sendTextMessage($this->info_notRegisteredToAnyStation(), $chat_id);
						}
					}else{
						//Error on fetch from Mysql variable.
						$this->sendTextMessage($this->info_genericError(), $chat_id);
						$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
					}
				}else{
					//Error on procedure call or on Mysql variable creation.
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
			}else{
				//Database connection error.
				$this->sendTextMessage($this->info_genericError(), $chat_id);
				$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
			$mysqli->close();
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			$toSend=$this->info_wrongCommandSyntax();
			$toSend.=$this->getSyntaxForCommand($commandName);
			$toSend.=$this->getHelpMessageFor($commandName);
			$this->sendTextMessage($toSend, $chat_id);
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user list of subscribed stations.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_iscrizioni($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(empty($arrayOfParams)){
			include('connect_DB.php');
			if(!$mysqli->connect_error){
				$telegramUserId = intval($message['from']['id']);
				if ($result = $mysqli->query("CALL p_getRegistrations(\"$telegramUserId\")")) {
					$stationsId = array();
					$i = 0;
					while($row = $result->fetch_assoc()){
						$stationsId[$i]= $row['stationId'];
						$i++;
					}//while
					if(!empty($stationsId)){
							$location = $this->getLocation($telegramUserId);
							$stations = $this->getStations($location);
							$this->filterStationsNotInIdArray($stations, $stationsId);
							$toSend = $this->getMessageSubscribedStationsList().PHP_EOL;
							$toSend.= $this->show_stations($stations);
							$this->sendTextMessage($toSend, $chat_id);
					}else{
						$toSend =$this->info_notRegisteredToAnyStation();
						$toSend.=$this->getCommandsForRegistrationPhrase();
						$this->sendTextMessage($toSend, $chat_id);
					}
				}else{
					//Error on procedure call.
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
			}else{
				//Database connection error.
				$this->sendTextMessage($this->info_genericError(), $chat_id);
				$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
			$mysqli->close();
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			$toSend=$this->info_wrongCommandSyntax();
			$toSend.=$this->getSyntaxForCommand($commandName);
			$toSend.=$this->getHelpMessageFor($commandName);
			$this->sendTextMessage($toSend, $chat_id);
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user list of all rivers.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_fiumi($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		if(empty($arrayOfParams)){
			$rivers = $this->getRivers();
			ksort($rivers);
			$this->sendTextMessage($this->show_rivers($rivers), $chat_id);
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			$toSend=$this->info_wrongCommandSyntax();
			$toSend.=$this->getSyntaxForCommand($commandName);
			$toSend.=$this->getHelpMessageFor($commandName);
			$this->sendTextMessage($toSend, $chat_id);
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user list stations of the given river.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_stazionifiume($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		$location = $this->getLocation($telegramUserId);
		if(!empty($arrayOfParams)){
			$rivers = $this->getRivers();
			$recognisedRiverName = $this->recogniseRiverName($arrayOfParams, $rivers);
			if(!is_null($recognisedRiverName)){
				$stations = $this->getRiverStations($recognisedRiverName, $location);
				if(!empty($location)){
					$this->orderByDistance($stations);
				}else{
					$this->orderByName($stations);
				}
				$toSend =$this->getMessageRiverStationsList($recognisedRiverName).PHP_EOL;
				$toSend.=$this->show_stations($stations);
				$this->sendTextMessage($toSend, $chat_id);
			}else{
				$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
				$toSend=$this->info_riverNameNotRecognised();
				$toSend.=$this->getCommandRiversPhrase();
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			if(empty($arrayOfParams)){
				$this->sendTextMessage($this->getDescriptionForCommand($commandName), $chat_id);
			}else{
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);	
			}
		}
	}
	
	/**
	 * Command invoked from receiveUpdate method to save Telegram user position and send outcome message.
	 * @param $message Associative array with informations about the received message.
	 */
	private function saveLocation($message){
		$chat_id = intval($message['chat']['id']);
		include('connect_DB.php');
		if(!$mysqli->connect_error){
			$latitude = $message['location']['latitude'];
			$longitude = $message['location']['longitude'];
			$telegramUserId = intval($message['from']['id']);
			if ($mysqli->query("SET @esito = ''") && $mysqli->query("CALL p_insertLocation(\"$telegramUserId\", \"$chat_id\", \"$latitude\", \"$longitude\", @esito)")) {
				$res = $mysqli->query("SELECT @esito as esito");
				if ($res) {
					$row = $res->fetch_assoc();
					$esito = $row['esito'];
					if($esito==1){
						$toSend = $this->info_locationSaved();
						$toSend.= $this->info_locationNextStepHint();
						$this->sendTextMessage($toSend, $chat_id);
					}elseif($esito==2){
						$toSend = $this->info_locationChanged();
						$toSend.= $this->info_locationNextStepHint();
						$this->sendTextMessage($toSend, $chat_id);
					}else{
						$this->sendTextMessage($this->info_locationSaveError(), $chat_id);
					}
				}else{
					//Error on fetch from Mysql variable.
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
			}else{
				//Error on procedure call or on Mysql variable creation.
				$this->sendTextMessage($this->info_genericError(), $chat_id);
				$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
		}else{
			//Database connection error.
			$this->sendTextMessage($this->info_genericError(), $chat_id);
			$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
		}
		$mysqli->close();
	}

	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user description of given command or generic help if no parameters were given.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_help($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		if(empty($arrayOfParams)){
			$this->sendTextMessage($this->description_help(), $chat_id);
		}else{
			if(count($arrayOfParams)==1){
				$commandName = preg_replace('/[^a-z]/', '', strtolower($arrayOfParams[0]));
				$method = 'description_'.$commandName;
				if(method_exists($this, $method)){
					$this->sendTextMessage($this->$method(), $chat_id);
				}else{
					$this->sendTextMessage($this->info_inexistentCommandForHelp(), $chat_id);
				}
			}else{
				$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
				$toSend=$this->info_wrongCommandSyntax();
				$toSend.=$this->getSyntaxForCommand($commandName);
				$toSend.=$this->getHelpMessageFor($commandName);
				$this->sendTextMessage($toSend, $chat_id);
			}
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to delete informations related to Telegram user and send outcome.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_stop($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		if(empty($arrayOfParams)){
			include('connect_DB.php');
			if(!$mysqli->connect_error){
				if($mysqli->query("SET @esito = ''") && $mysqli->query("CALL p_deleteUser(\"$telegramUserId\", @esito)")){
					$res = $mysqli->query("SELECT @esito as esito");
					if($res){
						$row = $res->fetch_assoc();
						$esito = $row['esito'];
						if($esito==1){
							$this->sendTextMessage($this->info_stopSuccessful(), $chat_id);
						}elseif($esito==2){
							$this->sendTextMessage($this->info_stopNothingToDelete(), $chat_id);
						}else{
							$this->sendTextMessage($this->info_genericError(), $chat_id);
						}
					}else{
						//Error on fetch from Mysql variable.
						$this->sendTextMessage($this->info_genericError(), $chat_id);
						$this->logMysqlVariableFetchError(__LINE__,__FUNCTION__,$mysqli);//LOG
					}
				}else{
					//Error on procedure call or on Mysql variable creation.
					$this->sendTextMessage($this->info_genericError(), $chat_id);
					$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
			}else{
				//Database connection error.
				$this->sendTextMessage($this->info_genericError(), $chat_id);
				$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
			$mysqli->close();
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			$toSend=$this->info_wrongCommandSyntax();
			$toSend.=$this->getSyntaxForCommand($commandName);
			$toSend.=$this->getHelpMessageFor($commandName);
			$this->sendTextMessage($toSend, $chat_id);
		}
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user start message.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_start($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$this->sendTextMessage($this->description_start(), $chat_id);
	}
	
	
	/**
	 * Command invoked from receiveUpdate method to send to Telegram user list of hydro stations with defined criticality levels.
	 * @param $arrayOfParams Parameters for the command (received from Telegram user).
	 * @param $message Associative array with informations about the received message.
	 */
	private function command_stazionicriticitadefinita($arrayOfParams, $message){
		$chat_id = intval($message['chat']['id']);
		$telegramUserId = intval($message['from']['id']);
		$location = $this->getLocation($telegramUserId);
		if(empty($arrayOfParams)){
			$stations = $this->getStations($location);
			$this->filterStationsNotIdroNotCriticalityLevels($stations);
			if(!empty($location)){
				$this->orderByDistance($stations);
			}else{
				$this->orderByName($stations);
			}
			$toSend = $this->getMessageStationsWithDefinedCriticalityList().''.PHP_EOL;
			$toSend.= $this->show_stations($stations);
			$this->sendTextMessage($toSend, $chat_id);
		}else{
			$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
			$toSend=$this->info_wrongCommandSyntax();
			$toSend.=$this->getSyntaxForCommand($commandName);
			$toSend.=$this->getHelpMessageFor($commandName);
			$this->sendTextMessage($toSend, $chat_id);
		}
	}
	
	
	//COMMANDS - END//

	
	//STRINGS TO USER - BEGIN//
	

	private function description_stazione(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di consultare le informazioni su una stazione idrometrica a scelta.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		return $s.=$this->getCommandsForIdDiscoveryPhrase();
	}
	private function syntax_stazione(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.'_273'.PHP_EOL;
		return $s.='per avere le informazioni sulla stazione avente l\'identificativo 273.'.PHP_EOL;
	}
	
	private function description_segui(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di iscriversi alle notifiche di una stazione idrometrica a cui sono associati i livelli di criticità.'.PHP_EOL;
		$s.='Per ogni stazione a cui ti sei iscritto il bot ti avviserà del cambio di criticità del livello dell\'acqua. '.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		return $s.=$this->getCommandsForIdDiscoveryPhrase();
	}
	private function syntax_segui(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.'_273'.PHP_EOL;
		return $s.='per iscriverti alla stazione avente l\'identificativo 273.'.PHP_EOL;
	}
	
	private function description_seguikm(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di iscriversi alle notifiche delle stazioni nel raggio indicato (in km).'.PHP_EOL;
		$s.='Per ogni stazione a cui ti sei iscritto il bot ti avviserà del cambio di stato di criticità del livello dell\'acqua.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.=$this->info_numberOutOfRange();
		$s.='Per far funzionare questo comando mandaci la tua posizione usando il pulsante "graffetta" e poi "posizione".'.PHP_EOL;
		return $s;
	}
	private function syntax_seguikm(){
		$commandName = 'seguiKm';
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.'_10'.PHP_EOL;
		$s.='/'.$commandName.'_20'.PHP_EOL;
		$s.='/'.$commandName.'_30'.PHP_EOL;
		return $s.='per iscriversi alle stazioni nel raggio di 10km, 20km o 30km dalla tua posizione.'.PHP_EOL;
	}
	
	private function description_nonseguire(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di disiscriversi dalle notifiche di una stazione idrometrica a scelta.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Per scoprire gli identificatori delle stazioni a cui sei iscritto usa il comando:'.PHP_EOL;
		$s.='/iscrizioni'.PHP_EOL;
		return $s;
	}
	private function syntax_nonseguire(){
		$s=$this->getFirstPhraseForSyntax();
		$s.='/nonSeguire_273'.PHP_EOL;
		return $s.='per disiscriversi dalla stazione avente l\'identificativo 273.'.PHP_EOL;
	}
	
	private function description_nonseguirenessuno(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di disiscriversi dalle notifiche di tutte le stazioni.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Per scoprire le stazioni a cui sei iscritto usa il comando:'.PHP_EOL;
		$s.='/iscrizioni'.PHP_EOL;
		return $s;
	}
	private function syntax_nonseguirenessuno(){
		$s=$this->getFirstPhraseForSyntax();
		$s.='/nonSeguireNessuno'.PHP_EOL;
		return $s.='per disiscriversi da tutte le stazioni.'.PHP_EOL;
	}
	
	private function description_iscrizioni(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di visualizzare le stazioni idrometriche a cui sei iscritto.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Per disiscriverti dalle stazioni puoi usare i comandi:'.PHP_EOL;
		$s.='/nonseguire e /nonseguirenessuno'.PHP_EOL;
		return $s;
	}
	private function syntax_iscrizioni(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.PHP_EOL;
		return $s.='per ottenere la lista delle stazioni a cui sei iscritto.'.PHP_EOL;
	}
	
	private function description_stazioni(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di ottenere la lista di tutte le stazioni idrometriche.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Se hai la posizione impostata questo comando visualizzerà le stazioni ordinate per distanza.'.PHP_EOL;
		return $s;
	}
	private function syntax_stazioni(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.PHP_EOL;
		return $s.='per ottenere la lista di tutte le stazioni.'.PHP_EOL;
	}
	
	private function description_stazionikm(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette ottenere la lista delle stazioni idrometriche nel raggio indicato (in km).'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.=$this->info_numberOutOfRange();
		$s.='Se hai la posizione impostata questo comando visualizzerà le stazioni ordinate per distanza.'.PHP_EOL;
		return $s;
	}
	private function syntax_stazionikm(){
		$commandName = 'stazioniKm';
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.'_10'.PHP_EOL;
		$s.='/'.$commandName.'_20'.PHP_EOL;
		$s.='/'.$commandName.'_30'.PHP_EOL;
		return $s.='per ottenere la lista delle stazioni nel raggio di 10km, 20km o 30km dalla tua posizione.'.PHP_EOL;
	}
	
	private function description_stazionifiume(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di ottenere la lista delle stazioni idrometriche relative al fiume indicato.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.=$this->getCommandRiversPhrase();
		return $s;
	}
	private function syntax_stazionifiume(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/stazioniFiume_Muson_dei_Sassi'.PHP_EOL;
		return $s.='per ottenere la lista delle stazioni del corso d\'acqua "Muson dei Sassi".'.PHP_EOL;
	}
	
	private function description_fiumi(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di ottenere la lista dei fiumi.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Per visualizzare la lista delle stazioni di un fiume usa il comando:'.PHP_EOL;
		$s.='/stazionifiume'.PHP_EOL;
		$s.='aggiungendogli il nome del fiume.'.PHP_EOL;
		return $s;
	}
	private function syntax_fiumi(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.PHP_EOL;
		return $s.='per ottenere la lista dei fiumi.'.PHP_EOL;
	}
	
	private function description_posizione(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette di vedere la posizione che ci hai mandato.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Se la posizione è impostata potrai usare i comandi:'.PHP_EOL;
		$s.='/seguikm e /stazionikm.'.PHP_EOL;
		$s.='I comandi:'.PHP_EOL;
		$s.='/stazioni, /stazionifiume e /stazionikm'.PHP_EOL;
		$s.='presenteranno i risultati ordinati per distanza.'.PHP_EOL;
		$s.='Inoltre il comando:'.PHP_EOL;
		$s.='/stazione'.PHP_EOL;
		$s.='indicherà anche la distanza della stazione dalla posizione.'.PHP_EOL;
		return $s;
	}
	private function syntax_posizione(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.PHP_EOL;
		return $s.='per vedere l\'ultima posizione che ci hai mandato.'.PHP_EOL;
	}
	
	private function description_help(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando può essere usato per ottenere la descrizione dettagliata dei comandi.'.PHP_EOL;
		$s.='Puoi ottenere la descrizione dei seguenti comandi:'.PHP_EOL;
		$s.=$this->getAllCommandsList();
		return $s;
	}
	private function syntax_help(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.'_stazioni'.PHP_EOL;
		return $s.='per ottenere la descrizione completa del comando "stazioni"'.PHP_EOL;
	}
	
	private function description_start(){
		$s="Visualizza i livelli idrometrici dei fiumi della regione misurati attraverso le stazioni idrometriche della rete Arpav. Per tutte le stazioni sono disponibili i grafici delle ultime 48 ore. I dati sono in continuo e non validati.\nPer iniziare, usa il pulsante (graffetta) e invia la tua posizione.\nQui trovi la pagina web del bot:\nwww.arpa.veneto.it/temi-ambientali/idrologia/arpavidrobot";
		return $s;
	}
	
	private function description_stop(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando cancella la tua posizione e ti disiscrive dalle notifiche di tutte le stazioni.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		return $s;
	}
	private function syntax_stop(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s=$this->getFirstPhraseForSyntax();
		$s.='/'.$commandName.PHP_EOL;
		return $s.='per cancellare la tua posizione e disiscriverti da tutte le stazioni.'.PHP_EOL;
	}
	
	private function description_stazionicriticitadefinita(){
		$commandName = $this->getCommandNameFromFunctionName(__FUNCTION__);
		$s='Questo comando permette ottenere la lista delle stazioni idrometriche aventi i livelli di criticità definiti.'.PHP_EOL;
		$s.=$this->getSyntaxForCommand($commandName);
		$s.='Se hai la posizione impostata questo comando visualizzerà le stazioni ordinate per distanza.'.PHP_EOL;
		return $s;
	}
	private function syntax_stazionicriticitadefinita(){
		$s=$this->getFirstPhraseForSyntax();
		$s.='/stazioniCriticitaDefinita'.PHP_EOL;
		return $s.='per visualizzare le stazioni con i livelli di criticità definiti.'.PHP_EOL;
	}
	
	
	/**
	 * @return String with "clickable" help commands for all commands.
	 */
	private function getAllCommandsList(){
		$commands = Array(
			'fiumi',
			'iscrizioni',
			'nonSeguire',
			'nonSeguireNessuno',
			'posizione',
			'segui',
			'seguiKm',
			'start',
			'stazione',
			'stazioni',
			'stazioniCriticitaDefinita',
			'stazioniFiume',
			'stazioniKm',
			'stop',
		);
		$s='';
		foreach($commands as $command){
			$s.='/help_'.$command.PHP_EOL;
		}
		return $s;
	}
	
	/**
	 * @param $commandName Name of the command.
	 * @return String with message that describes how to obtain detailed information about the given command.
	 */
	private function getHelpMessageFor($commandName){
		return 'Scrivi "/help_'.$commandName.'" per avere la descrizione completa del comando "'.$commandName.'".'.PHP_EOL;
	}
	
	/**
	 * @return Phrase used before the example of the right syntax for a command.
	 */
	private function getFirstPhraseForSyntax(){
		return 'Per esempio scrivi: '.PHP_EOL;
	}
	
	/**
	 * @return Phrase that gives commands useful to discover identifiers of the stations.
	 */
	private function getCommandsForIdDiscoveryPhrase(){
	 	$s='Per scoprire gli identificatori delle stazioni puoi usare i comandi:'.PHP_EOL;
		return $s.='/stazioni, /stazioniKm o /stazioniFiume.'.PHP_EOL;
	}

	/**
	 * @return Phrase that tells how to send position.
	 */
	private function getSendYourPositionPhrase(){
		return 'Mandaci la tua posizione usando il pulsante "graffetta" e poi "posizione".'.PHP_EOL;
	}

	/**
	 * @return Phrase that gives command used to get list of rivers.
	 */
	private function getCommandRiversPhrase(){
		$s='Per vedere la lista dei fiumi disponibili usa il comando:'.PHP_EOL;
		$s.='/fiumi'.PHP_EOL;
		return $s;
	}

	/**
	 * @return Phrase that tells how to get avvailable commands.
	 */
	private function  getAvailableCommandsPhrase(){
		return 'Inserisci il simbolo "/" per vedere tutti i comandi disponibili.'.PHP_EOL;
	}

	/**
	 * @return Phrase that is placed before the list of all stations.
	 */
	private function getMessageAllStationsList(){
		return 'Elenco di tutte le stazioni idrometriche:'.PHP_EOL;
	}
	
	/**
	 * @return Phrase that is placed before the list of stations with defined criticality levels.
	 */
	private function getMessageStationsWithDefinedCriticalityList(){
		return 'Elenco di staizoni idrometriche con i livelli di criticità definiti:'.PHP_EOL;
	}

	/**
	 * @return Phrase that is placed before the list of stations in given range.
	 * @param $rangeMeters Range in meters.
	 */
	private function getMessageStationsInRangeList($rangeMeters){
		return 'Elenco di stazioni idrometriche nel raggio di '.$this->show_distance($rangeMeters).' dalla tua posizione:'.PHP_EOL;
	}

	/**
	 * @return Phrase that is located before the list of stations of indicated river.
	 * @param $riverName Range in meters.
	 */
	private function getMessageRiverStationsList($riverName){
		return 'Elenco delle stazioni idrometriche del '.$riverName.':'.PHP_EOL;
	}

	/**
	 * @return Phrase that is located before the list of subscribed stations.
	 */
	private function getMessageSubscribedStationsList(){
		return 'Sei iscritto alle seguenti stazioni idrometriche:'.PHP_EOL;
	}

	/**
	 * @return Phrase that gives the commands used to subscribe to stations.
	 */
	private function getCommandsForRegistrationPhrase(){
		return 'Usa i comandi:'.PHP_EOL.'/segui o /seguiKm'.PHP_EOL.'per iscriverti alle stazioni idrometriche.'.PHP_EOL;
	}

	/**
	 * @return Phrase that gives the command used to get list of subscribed stations.
	 */
	private function getSubscribedStationsPhrase(){
		return 'Usa il comando /Iscrizioni per vedere le stazioni a cui sei iscritto.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to show when there is a syntax error in command.
	 */
	private function info_wrongCommandSyntax(){
		return 'La sintassi del comando non è corretta.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to show when station id is wrong.
	 */
	private function info_wrongStationId(){
		return 'L\'identificatore della stazione non è corretto.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to show when station id is wrong.
	 */
	private function info_notIdroStationId(){
		return 'L\'identificatore rappresenta una stazione non idrometrica.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to show on generic (like connection) errors.
	 */
	private function info_genericError(){
		return "Si è verificato un errore, riprova più tardi.";
	}
	
	/**
	 * @return Phrase to show when user doesn't have a location.
	 */
	private function info_noLocation(){
		$s='Non hai ancora indicato una posizione.'.PHP_EOL;
		return $s.=$this->getSendYourPositionPhrase();
	}
	
	/**
	 * @return Phrase to show when user doesn't have a location.
	 */
	private function info_locationRequired(){
		$s='Questo comando ha bisogno della tua posizione per funzionare.'.PHP_EOL;
		return $s.=$this->getSendYourPositionPhrase();
	}
	
	/**
	 * @return Phrase to show when inserted number is out of range.
	 */
	private function info_numberOutOfRange(){
		return 'Il numero deve essere compreso tra 1 e 2000.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to show when no hydro stations were found in given range.
	 */
	private function  info_noHydroWithCriticalityLevelsInRange(){
		return 'Nel raggio indicato non ci sono delle stazioni idrometriche a cui è possibile iscriversi.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed when a station has not defined levels of criticality.
	 */
	private function info_noHydroWithCriticalityLevels(){
		return 'Questa stazione non ha i livelli di criticità definiti, pertanto non può essere seguita.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed when user try to register twice to the same station.
	 */
	private function info_registrationDuplicate(){
		return 'Stai già seguendo questa stazione.'.PHP_EOL;
	}

	/**
	 * @return Phrase to be displayed on successful registration to the station.
	 */
	private function info_registrationSuccess(){
		return 'Iscrizione avvenuta con successo.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed on successful registration to the station.
	 */
	private function info_notRegisteredToAnyStation(){
		return 'Non sei registrato ad alcuna stazione.'.PHP_EOL;
	}  
	
	/**
	 * @return Phrase to be displayed when user try to unsubscribe from not subscribed station.
	 */
	private function info_notRegistered(){
		return 'Non sei registrato alla stazione indicata.'.PHP_EOL;
	}

	/**
	 * @return Phrase to be displayed on successful unsubscription.
	 */
	private function info_unRegistrationSuccess(){
		return 'Non segui più la stazione indicata.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed on successful unsubscription.
	 */
	private function info_riverNameNotRecognised(){
		return 'Nome del fiume non è stato riconosciuto.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed on successful location save.
	 */
	private function info_locationSaved(){
		return 'La tua posizione è stata salvata.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed to guide the user after location save.
	 */
	private function info_locationNextStepHint(){
		return 'Puoi usare il comando /stazioniKm per trovare le stazioni vicine.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed on successful location change.
	 */
	private function info_locationChanged(){
		return 'La tua nuova posizione è stata salvata.'.PHP_EOL;
	}  
	
	/**
	 * @return Phrase to be displayed on failed location save.
	 */
	private function info_locationSaveError(){
		return 'Si è verificato un errore, durante il salvataggio della tua posizione, riprova più tardi.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed on failed location save.
	 */
	private function  info_inexistentCommandForHelp(){
		$s='Il comando indicato non esiste.'.PHP_EOL;
		return $s.=$this->getAvailableCommandsPhrase();
	}
	
	/**
	 * @return Phrase to be displayed on inexistent command request.
	 */
	private function  info_inexistentCommand(){
		$s='Il comando inserito non esiste.'.PHP_EOL;
		return $s.=$this->getAvailableCommandsPhrase();
	}
	
	/**
	 * @param $rangeMeters Radius in meters. 
	 * @return Phrase to be displayed when no stations were found in indicated range.
	 */
	private function info_noStationsInRange($rangeMeters){
		return 'Non ci sono stazioni nel raggio di '.$this->show_distance($rangeMeters).' dalla tua posizione.'.PHP_EOL;
	}
	
	/**
	 * @return Phrase to be displayed on successful invocation of the command "stop".
	 */
	private function info_stopSuccessful(){
		$s ='Tutte le informazioni associate a te sono state cancellate.'.PHP_EOL;
		$s.='Non riceverai più le notifiche.'.PHP_EOL;
		return $s;
	}
	
	/**
	 * @return Phrase to be displayed on unsuccessful invocation of the command "stop".
	 */
	private function info_stopNothingToDelete(){
		return 'Non hai nessun dato da cancellare.'.PHP_EOL;
	}
	
	/**
	 * @param $location Associative array with specified 'latitude' and 'longitude' of the location.
	 * @return String that describes the indicated location.
	 */
	private function show_location($location){
		$s='La tue posizione è: '.PHP_EOL;
		$s.='latitudine: '.$location['latitude'].PHP_EOL;
		$s.='longitudine: '.$location['longitude'];
		return $s;
	}
	
	/**
	 * @param $stations Associative array of stations.
	 * @param $riverName Name of the river.
	 * @return Phrase with list of the stations associated with indicated river.
	 */
	private function show_riverStations($stations, $riverName){
		$s='Elenco delle stazioni idrometriche del '.$riverName.':'.PHP_EOL.PHP_EOL;
		$s.=$this->show_stations($stations);
		return $s;
	}
	
	/**
	 * @param $stations Associative array of stations.
	 * @return String with the list of indicated stations.
	 */
	private function show_stations($stations){
		$s='';
		foreach ($stations as $station) {
			$s.='<b>Nome:</b> '.$station['name'].PHP_EOL;
			$s.='/Stazione_'.$station['stationId'].PHP_EOL;
			if(isset($station['distance'])){
				$s.='<b>Distanza:</b> '.$this->show_distance($station['distance']).PHP_EOL;
			}
			$s.=PHP_EOL;
		}
		return $s;
	}
	
	/**
	 * @param $stations Associative array of rivers.
	 * @return String with the list of indicated rivers.
	 */
	private function show_rivers($rivers){
		$s='Elenco dei fiumi aventi delle stazioni idrometriche, ';
		$s.='con a fianco indicato il numero di stazioni per ciascun fiume.'.PHP_EOL;
		$s.='Puoi premere sui link per vedere le stazioni associate al fiume.'.PHP_EOL.PHP_EOL;
		foreach ($rivers as $riverName => $stationsCount) {
			$s.='<b>Fiume:</b> '.$riverName.PHP_EOL;
			$s.='/StazioniFiume_'.str_replace(' ','_',str_replace('\'', '',$riverName)).PHP_EOL;
			$s.='<b>Numero di Stazioni:</b> '.$stationsCount.PHP_EOL.PHP_EOL;
		}
		return $s;
	}
	
	/**
	 * Used to display info about "/seguikm" outcome.
	 * @param $numStazioni Number of stations found.
	 * @param $numIscrizioni Number of successful registrations.
	 * @param $numDuplicati Number of unsuccessful registrations.
	 * @param $range Radius of the research.
	 * @return String with detailed info about subscription.
	 */
	private function info_subscribedToStations($numStazioni, $numIscrizioni, $numDuplicati, $range){
		$trova_OR_trovano = ($numStazioni==1 ? 'trova' : 'trovano');
		$stazione_OR_stazioni = ($numStazioni==1 ? 'stazione' : 'stazioni');
		return 'Nel raggio di '.$this->show_distance($range).' dalla tua posizione si '.$trova_OR_trovano.' '.$numStazioni.' '.$stazione_OR_stazioni.' a qui è possibile iscriversi. Ti sei iscritto ad '.$numIscrizioni.' di esse, mentre eri già iscritto ad '.$numDuplicati.' di esse.';
	}

	/**
	 * @param $stationId Identifier of the station.
	 * @param $currentCriticalityStateString Current criticality state string.
	 * @param $previousCriticalityStateString Previous criticality state string.
	 * @return String that describes the change of criticality.
	 */
	private function info_criticalityChangeMessage($stationId, $currentCriticalityStateString, $previousCriticalityStateString){
		$s='Il livello di criticità del livello dell\'acqua della stazione '.$stationId.' è cambiato.'.PHP_EOL;
		$s.='Ora è: '.$currentCriticalityStateString.PHP_EOL;
		$s.='mentre prima era: '.$previousCriticalityStateString.PHP_EOL;
		$s.='Scrivi /stazione_'.$stationId.' per vedere le informazioni dettagliate sulla stazione.';
		return $s;
	}

	/**
	 * @param $numStations Number of successful unregistrations.
	 * @return String that inform about the unregistration.
	 */
	private function info_unregistrationSuccessStations($numStations){
		$s='Ti sei disiscritto da '.$numStations.' stazioni'.PHP_EOL;
		$s.='Non segui più nessuna stazione.';
		return $s;
	}
	
	
	//STRINGS TO USER - END//
	
	
	//SUPPORT FUNCTIONS - BEGIN//
	
	
	/**
	 * Used by functions having function name composed of two prats: [function type]_[command].
	 * @param $callerFunctionName name of the function given by _FUNCTION_ magic constant.
	 * @return String which represents the command.
	 */
	private function getCommandNameFromFunctionName($callerFunctionName){
		$arr=explode('_', $callerFunctionName);
		return end($arr);
	}
	
	/**
	 * Used to get syntax for a command.
	 * @param $commandName Name of the command (method that describes the syntax for the given command must exist).
	 * @return return message obtained from invocation of the method that describes the syntax of the command.
	 */
	private function getSyntaxForCommand($commandName){
		$method = 'syntax_'.$commandName;
		return $this->$method();
	}
	
	/**
	 * Used to get description for a command.
	 * @param $commandName Name of the command (method that gives description for the given command must exist).
	 * @return return message obtained from invocation of the method that describes the command.
	 */
	private function getDescriptionForCommand($commandName){
		$method = 'description_'.$commandName;
		return $this->$method();
	}
	
	/**
	 * Used to test if given distance is in acceptable range.
	 * @param $number representing distance in km.
	 * @return TRUE if $rangeKm is in ]0,2000] range, FALSE otherwise.
	 */
	private function isInRangeKm($rangeKm){
		return ($rangeKm>0 && $rangeKm<=2000);
	}
	
	/**
	 * Used to get number of km from given string.
	 * @param $candidateKmStr string that presumably represents a number of km. 
	 * @return Float number if the given string represents a valid number, FALSE otherwise.
	 */
	private function getValidKm($candidateKmStr){
		$candidateKmStr = str_replace(',', '.', $candidateKmStr);
		$candidateKmStr = preg_replace('/[^0-9.]/', '', $candidateKmStr);
		if(is_numeric($candidateKmStr)){
			return (float)$candidateKmStr;
		}else{
			return FALSE;
		}
	}
	
	
	/**
	 * @param $telegramUserId User identifier. 
	 * @return Array of identifiers of the stations subscribed by the specified user.
	 */
	private function getRegistrations($telegramUserId){
		$a;
		include('connect_DB.php');
		if(!$mysqli->connect_error){
			if ($result = $mysqli->query("CALL p_getRegistrations(\"$telegramUserId\")")) {
				$a = array();
				$i = 0;
				while($row = $result->fetch_assoc()){
					$a[$i]= (int)$row['stationId'];
					$i++;
				}//while
			}else{
				$a=NULL;
			}
		}else{
			$a=NULL;
		}
		$mysqli->close();
		return $a;
	}
	
	
	/**
	 * Used to remove from the array of stations stations out of the given radius.
	 * @param &$stations Associative array of stations ordered in ascending order of distance.
	 * @param $range Maximum radius of acceptance of the stations.
	 */
	private function filterStationsNotInRange(&$stations, $range){
		$continue = true;
		$count = count($stations);
		for($i=0; $i<$count && $continue; $i++){
			if($stations[$i]['distance']>$range){
				$stations = array_slice($stations, 0, $i);
				$continue = false;
			}
		}
	}
	
	
	/**
	 * Used to remove from the array, the stations that do not have defined the critical levels. 
	 * @param &$stations Associative array of stations.
	 */
	private function filterStationsNotIdroNotCriticalityLevels(&$stations){
		$a=array();
		$stationsId = $this->getStationsIdWithCriticalityLevels();
		$a = array_filter($stations, function($elem) use($stationsId){
			return in_array($elem['stationId'], $stationsId);
		});
		$stations=array_values($a);
	}
	
	/**
	 * Used to remove from the array &$stations, the stations that doesn't have identifier in $stationsId matrix.
	 * @param &$stations Associative array of stations.
	 * @param $stationsId Array of stations identifiers.
	 */
	private function filterStationsNotInIdArray(&$stations, $stationsId){
		$a=array();
		$a = array_filter($stations, function($elem) use($stationsId){
			return in_array($elem['stationId'], $stationsId);
		});
		$stations=array_values($a);
	}
	
	/**
	 * Used to sort stations in ascending order of distance.
	 * @param &$stations Associative array of stations.
	 */
	private function orderByDistance(&$stations){
		usort($stations, function($a, $b){
			return $b['distance'] < $a['distance'];
		});
	}
	
	/**
	 * Used to sort stations by name.
	 * @param &$stations Associative array of stations.
	 */
	private function orderByName(&$stations){
		usort($stations, function($a, $b){
			return $b['name'] < $a['name'];
		});
	}
	
	/**
	 * @param $stationId String that supposedly represents a station identifier.
	 * @return True if the string has the right syntax to represent a station identifier.
	 */
	private function itCanBeValidStationId($stationId){
		return (boolean) preg_match("/^[0-9]{1,3}$/", ltrim($stationId, '0'));
	}
	
	/**
	 * @param $locationA First location.
	 * @param $locationB Second location.
	 * @return Distance between the two positions in meters.
	 */
	private function calcDistance($locationA, $locationB){
		$a1 = $locationA['latitude'];
		$a2 = $locationA['longitude'];
		$b1 = $locationB['latitude'];
		$b2 = $locationB['longitude'];
		$distanceMeters = $this->vincentyGreatCircleDistance($a1,$a2,$b1,$b2);
		return $distanceMeters;
	}
	
	/**
	 * @param $distanceMeters 
	 * @return Formatted string that represents the distance in km.
	 */
	private function show_distance($distanceMeters){
		$distanceKm = $distanceMeters/1000;
		$formattedDistance;
		if($distanceKm<10){
			$formattedDistance = number_format($distanceKm, 1, '.', '');
		}else{
			$formattedDistance = round($distanceKm);
		}
		return ''.$formattedDistance.' km';
	}
	
	
	/**
	 * To use in links.
	 * @return Hour to use in links. Like: 08
	 */
	private function getSolarHour() {
		$hour;
		//Time needed to the external system to generate XML files and Graphics.
		$disponibilityDelay = '-30 minutes';
		$now = time();//date("Y-m-d H:i:s");
		//1 -> true, if it's day saving time.
		if(date('I', $now)){
			$now_solar = strtotime('-1 hour', $now);
		}else{
			$now_solar = $now;
		}
		$now_solar_delayed = strtotime($disponibilityDelay, $now_solar);
		$hour = date('H', $now_solar_delayed);
		return $hour;
	}
	
	/**
	 * @param $criticalityState integer representiing criticality state.
	 * @return String representing criticality state.
	 */
	private function getCriticalityStateMessage($criticalityState){
		$s;
		if($criticalityState === 0){
			$s='ASSENTE';
		}elseif($criticalityState === 1){
			$s='ORDINARIO';
		}elseif($criticalityState === 2){
			$s='MODERATO';
		}elseif($criticalityState === 3){
			$s='ELEVATO';
		}else{
			$s='INDEFINITO';
		}
		return $s;
	}
	
	
	/**
	 * @param $stationId Station identifier.
	 * @return TRUE if the given station has defined levels of criticality, false otherwise.
	 */
	private function haveCriticalityLevels($stationId){		
	  $exist = false;
		$xml = simplexml_load_file($this->getCriticalityLevelsURL());
		if($xml){
			$exist = (bool)$xml->xpath('//STAZIONE[IDSTAZ="'.$stationId.'"]/IDSTAZ');
		}
		return $exist;
	}
	
	/**
	 * @param $stationId Station identifier.
	 * @return String that represents the URL of the XML file of the station.
	 */
	private function getWaterLevelsURL($stationId){
		$stationId_leadingZeros = str_pad($stationId, 4, '0', STR_PAD_LEFT);
		$hour = $this->getSolarHour();
		return 'http://192.168.31.3/stazioni/temporeale/h24/img'.$hour.'/'.$stationId_leadingZeros.'.xml';
	}

	/**
	 * @return String representing the URL of the XML file containing the levels of criticality of the stations.
	 */
	private function getCriticalityLevelsURL(){
		return dirname(__FILE__).'/livelli.xml';
	}
	
	/**
	 * @return String representing the URL of the XML file containing the hydrometric stations.
	 */
	private function getHydroStationsURL(){
		$hour = $this->getSolarHour();
		return 'http://192.168.31.3/stazioni/temporeale/h24/img'.$hour.'/stazioni_idro.xml';
	}
	
	/**
	 * @param $stationId Station identifier.
	 * @return String representing the URL of the XML file containing list of the hydrometric stations.
	 */
	private function getHydroStationGraphicURL($stationId){
		$hour = $this->getSolarHour();
		//To append to the URL to prevent caching. 
		$x = date("YmdHi");
		return 'http://www.arpa.veneto.it/bollettini/meteo/h24/img'.$hour.'/Graf_'.$stationId.'_LIVIDRO.jpg?x='.$x;
	}
	
	
	/**
	* Calculates the great-circle distance between two points, with the Vincenty formula.
	* @param float $latitudeFrom Latitude of start point in [deg decimal]
	* @param float $longitudeFrom Longitude of start point in [deg decimal]
	* @param float $latitudeTo Latitude of target point in [deg decimal]
	* @param float $longitudeTo Longitude of target point in [deg decimal]
	* @param float $earthRadius Mean earth radius in [meters]
	* @return float Distance between points in [meters] (same as earthRadius)
	*/
	public static function vincentyGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000){
		$latFrom = deg2rad($latitudeFrom);
		$lonFrom = deg2rad($longitudeFrom);
		$latTo = deg2rad($latitudeTo);
		$lonTo = deg2rad($longitudeTo);
		$lonDelta = $lonTo - $lonFrom;
		$a = pow(cos($latTo) * sin($lonDelta), 2) +
			pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
		$b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);
		$angle = atan2(sqrt($a), $b);
		return $angle * $earthRadius;
	}
	
	/**
	 * @param $subscribedStations Associative array of subscribed stations, Keys are identifiers of the stations Values are 'lastCriticalityState' of the stations.
	 * @param $criticalityLevels levels of criticality for all the stations.
	 * @param $idroLevels Levels of water of subscribed stations.
	 * @param &$nullStations Array of stations that previously had not saved the level of criticality in the database.
	 * @return Associative array containing for every station with the changed state of criticality: 'currentCriticalityState' and 'previousCriticalityState'.
	 */
	private function getChangedCriticalityStates($subscribedStations, $criticalityLevels, $idroLevels, &$nullStations){
		$changedCriticalityStates = array();
		foreach ($subscribedStations as $stationId => $lastCriticalityState) {
			if(isset($idroLevels[$stationId])){
				$latestIdroLevel = $idroLevels[$stationId];
				$criticality_ORDINARIA = $criticalityLevels[$stationId]['ORDINARIA'];
				$criticality_MODERATA = $criticalityLevels[$stationId]['MODERATA'];
				$criticality_ELEVATA = $criticalityLevels[$stationId]['ELEVATA'];
				$currentCriticalityState = $this->calcCriticalityState($criticality_ORDINARIA, $criticality_MODERATA, $criticality_ELEVATA, $latestIdroLevel);
				if(is_null($lastCriticalityState)){
					if(!is_null($currentCriticalityState)){
						$nullStations[$stationId]['currentCriticalityState'] = $currentCriticalityState;
					}else{
						//Not valid data from station, do nothing.
					}
				}else{
					if(!is_null($currentCriticalityState)){
						if($currentCriticalityState !== $lastCriticalityState){
							$changedCriticalityStates[$stationId]['currentCriticalityState'] = $currentCriticalityState;
							$changedCriticalityStates[$stationId]['previousCriticalityState'] = $lastCriticalityState;
						}else{
							//Criticality level is unchanged, so there is no need for update.
						}
					}else{
						//Not valid data from station, do nothing.
					}
				}
			}else{
				//Idro level wasn't set, do nothing.
			}
		}
		return $changedCriticalityStates;
	}
	
	/**
	 * @param $c1 LOW level of criticality.
	 * @param $c2 MEDIUM level of criticality.
	 * @param $c3 HIGH level of criticality.
	 * @param $idroLevel Current level of criticality.
	 * @return The integer that represents the level of criticality.
	 */
	private function calcCriticalityState($c1, $c2, $c3, $idroLevel){
		$state;
		if(is_null($idroLevel)){
			$state = NULL;
		}elseif($idroLevel >= $c3){
			$state = 3;
		}elseif($idroLevel >= $c2){
			$state = 2;
		}elseif($idroLevel >= $c1){
			$state = 1;
		}else{
			$state = 0;
		}
		return $state;
	}
	
	/**
	 * @param $stationName Station name composed of two prats: [river name][separator][station name].
	 * @return (String) Extrapolated name of the river ([river name]).
	 */
	private function getRiverNameFromStationName($stationName){
		$separatorA = ' a ';
		$separatorB = ' ad ';
		$arr = explode($separatorA, $stationName);
		$riverName;
		if(count($arr)>=2){
			$riverName = $arr[0];
		}else{
			$arr = explode($separatorB, $stationName);
			if(count($arr)>=2){
				$riverName = $arr[0];
			}else{ 
				//none of the separators was found in the station name.
				$riverName = NULL;
			}
		}
		return $riverName;
	}
	
	
	/**
	 * @param $pRiverName river name to recognise (array of words that compose the river name)
	 * @param $rivers array of valid names of the rivers.
	 * @return NULL if recognition wasn't successful, correct name of the river otherwise.
	 */
	private function recogniseRiverName($pRiverName, $rivers){
		$recognisedName = NULL;
		setlocale(LC_ALL, 'en_US.UTF8');
		$equivalent_pRiverName = iconv("utf-8","ascii//TRANSLIT",strtolower(implode(' ', $pRiverName)));
		$equivalent_pRiverName = preg_replace('/[^a-z]/', '', $equivalent_pRiverName);
		foreach($rivers as $riverName => $stationsCount){
			$equivalent_RiverName = iconv("utf-8","ascii//TRANSLIT",strtolower($riverName));
			$equivalent_RiverName = preg_replace('/[^a-z]/', '', $equivalent_RiverName);
			if($equivalent_RiverName == $equivalent_pRiverName){
				$recognisedName = $riverName;
				break;
			}
		}
		return $recognisedName;
	}
	
	
	/**
	 * @param $telegramUserId User identifier given by Telegram.
	 * @return Associative array with 'latitude' and 'longitude' of the location  found in database. Empty array if the location isn't found.
	 */
	private function getLocation($telegramUserId){
		$location = array();
		include('connect_DB.php');
		if(!$mysqli->connect_error){
			if ($result = $mysqli->query("CALL p_getLocation(\"$telegramUserId\")")) {
				if($row = $result->fetch_assoc()){
					$location['latitude']= $row['latitude'];
					$location['longitude']= $row['longitude'];
				}else{
					//User not registered.
				}
			}else{
				//Error on procedure call.
				$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
		}else{
			//Error on database connection.
			$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
		}
		$mysqli->close();
		return $location;
	}
	
	
	/**
	 * Used to output messages to the system.
	 * @param $str message.
	 */
	private function log($str){
		error_log('[ '.date("Y-m-d H:i:s").' ] '.$str.PHP_EOL, 3, "php://stdout");
	}
	
	/**
	 * Used to output a database connection error.
	 * @param $lineNumber number of the line in source code.
	 * @param $functionName name of the function where the error occurred.
	 * @param $mysqli handle to mysqli connection.
	 */
	private function logDatabaseConnectionError($lineNumber, $functionName, $mysqli){
		$this->log('Function: '.$functionName.': error on connection to database. Line: '.$lineNumber.PHP_EOL.'Connection failed: ('.$mysqli->errno.') '.$mysqli->error);//LOG
	}
	
	/**
	 * Used to output a database connection error.
	 * @param $lineNumber number of the line in source code.
	 * @param $functionName name of the function where the error occurred.
	 * @param $mysqli handle to mysqli connection.
	 */
	private function logProcedureCallError($lineNumber, $functionName, $mysqli){
		$this->log('Function: '.$functionName.': error on procedure call. Line: '.$lineNumber.PHP_EOL.'Call failed: ('.$mysqli->errno.') '.$mysqli->error);//LOG
	}
	
	/**
	 * Used to display a retrieval failure of a MySQL variable.
	 * @param $lineNumber number of the line in source code.
	 * @param $functionName name of the function where the error occurred.
	 * @param $mysqli handle to mysqli connection.
	 */
	private function logMysqlVariableFetchError($lineNumber, $functionName, $mysqli){
		$this->log('Function: '.$functionName.': error on fetch from Mysql variable. Line: '.$lineNumber.PHP_EOL.'Fetch failed: ('.$mysqli->errno.') '.$mysqli->error);//LOG
	}
	
	/**
	 * Used to display a XML error.
	 * @param $lineNumber number of the line in source code.
	 * @param $functionName name of the function where the error occurred.
	 * @param $xml_url url to xml file.
	 */
	private function logXmlError($lineNumber, $functionName, $xml_url){
		$this->log('Function: '.$functionName.'; Line: '.$lineNumber.'; Failed to open XML at URL: '.$xml_url);//LOG
	}
	
	
	//SUPPORT FUNCTIONS - END//
	
	
	//ARRAYS FUNCTIONS - BEGIN//
	
	
	/**
	 * @param $pRiverName Valid name of the river.
	 * @param $location Optional location, used to calculate distances to the stations.
	 * @return Associative array with the stations associated with the given river.
	 */
	private function getRiverStations($pRiverName, $location = array()){
		$stations = array();
		$xml_url = $this->getHydroStationsURL();
		$xml = simplexml_load_file($xml_url);
		if($xml){
			$i=0;
			foreach ($xml->STAZIONE as $stazione){
				$stationName = $stazione->NOME;
				$riverName = (string)$this->getRiverNameFromStationName($stationName);
				if($pRiverName===$riverName){
					if(!empty($location)){
						$locB1=(float)$stazione->Y;
						$locB2=(float)$stazione->X;
						$distance = $this->vincentyGreatCircleDistance($location['latitude'], $location['longitude'], $locB1, $locB2);
						$stations[$i]['distance']=$distance;
					}
					$stations[$i]['stationId']= (int)$stazione->IDSTAZ;
					$stations[$i]['name']=(string)$stazione->NOME;
					$i++;
				}
			}
		}else{
			//Failed to open XML file with the list of hydro stations.
			$this->logXmlError(__LINE__,__FUNCTION__,$xml_url);//LOG
		}
		return $stations;
	}
	
	
	/**
	 * @param $location Optional location, used to calculate distances to the stations.
	 * @return Associative array with all the stations.
	 */
	private function getStations($location = array()){
		$stations = array();
		$xml_url = $this->getHydroStationsURL();
		$xml = simplexml_load_file($xml_url);
		if($xml){
			$i=0;
			foreach ($xml->STAZIONE as $stazione){
				if(!empty($location)){
					$locB1=(float)$stazione->Y;
					$locB2=(float)$stazione->X;
					$distance = $this->vincentyGreatCircleDistance($location['latitude'], $location['longitude'], $locB1, $locB2);
					$stations[$i]['distance']=$distance;
				}
				$stations[$i]['stationId']= (int)$stazione->IDSTAZ;
				$stations[$i]['name']=(string)$stazione->NOME;
				$i++;
			}
		}else{
			//Failed to open XML file with the list of hydro stations.
			$this->logXmlError(__LINE__,__FUNCTION__,$xml_url);//LOG
		}
		return $stations;
	}
	
	
	/**
	 * @param $stationId Station identifier.
	 * @param $location Optional location, used to calculate the distance to the station.
	 * @param $callerCommandName Name of the command that caused the invocation of this method.
	 * @param $telegramUserId User identifier given by Telegram.
	 * @param &$sendPhoto It will be set to TRUE if it is ok to send a photo after the invocation of this method.
	 * @return (String) Detailed information about the station.
	 */
	private function getWaterLevels($stationId, $location, $callerCommandName, $telegramUserId, &$sendPhoto) {
		$xml_url=$this->getWaterLevelsURL($stationId);
	  $xml = simplexml_load_file($xml_url);
		$toReturn ='';
		if($xml){
			$stationType = $xml->STAZIONE->TIPOSTAZ;
			$hydroSensors = $xml->STAZIONE->xpath('SENSORE[TYPE="LIVIDRO"]');
			if($stationType == 'IDRO'){
				if(!empty($hydroSensors)){
					$sendPhoto = TRUE;
					$levelsAvailable = FALSE;
					$criticita = '<b>Criticità:</b> Nessun dato.'.PHP_EOL;
					$xml_livelli_url=$this->getCriticalityLevelsURL();
					$xml_livelli = simplexml_load_file($xml_livelli_url);
					if($xml_livelli){
						$levelsAvailable = $xml_livelli->xpath('//STAZIONE[IDSTAZ="'.$stationId.'"]');
						if($levelsAvailable){
							$crit_ordinaria = $xml_livelli->xpath('//STAZIONE[IDSTAZ="'.$stationId.'"]/ORDINARIA')[0];
							$crit_moderata = $xml_livelli->xpath('//STAZIONE[IDSTAZ="'.$stationId.'"]/MODERATA')[0];
							$crit_elevata = $xml_livelli->xpath('//STAZIONE[IDSTAZ="'.$stationId.'"]/ELEVATA')[0];
							$criticita='<b>Criticità:</b>'.PHP_EOL;
							$criticita.='  <strong>Ordinaria:</strong> '.$crit_ordinaria.PHP_EOL;
							$criticita.='  <strong>Moderata:</strong> '.$crit_moderata.PHP_EOL;
							$criticita.='  <strong>Elevata:</strong> '.$crit_elevata.PHP_EOL;
						}
					}else{
						//Failed to open XML file with criticality levels.
						$this->logXmlError(__LINE__,__FUNCTION__,$xml_livelli_url);//LOG
					}
					$toReturn.='<b>Nome stazione:</b> ';
					$toReturn.=$xml->STAZIONE->NOME."\n";
					$toReturn.='<b>Identificativo stazione:</b> ';
					$toReturn.=$xml->STAZIONE->IDSTAZ."\n";
					$toReturn.='<b>Provincia:</b> ';
					$toReturn.=$xml->STAZIONE->PROVINCIA."\n";
					$toReturn.='<b>Comune:</b> ';
					$toReturn.=$xml->STAZIONE->COMUNE."\n";
					if(!empty($location)){
						$toReturn.='<b>Distanza dalla tua posizione:</b> ';
						$locationB['latitude'] = (float)$xml->STAZIONE->Y;
						$locationB['longitude'] = (float)$xml->STAZIONE->X;
						$distance=$this->calcDistance($location, $locationB)."\n";
						$toReturn.=$this->show_distance($distance)."\n";
					}
					$toReturn.='<b>Ora solare della misurazione:</b> ';
					$str1=''.$hydroSensors[0]->xpath('DATI[last()]/@ISTANTE')[0];
					$str2=substr($str1, -4);
					$hour=substr($str2, 0, 2);
					$minutes=substr($str2, -2);
					$toReturn.=$hour.':'.$minutes."\n";
					$toReturn.='<b>Livello dell\'acqua:</b> ';
					$misurazione = (string)$hydroSensors[0]->xpath('DATI[last()]/VM')[0];
					$toReturn.=is_numeric($misurazione)?$misurazione."\n":'dato non pervenuto.'."\n";
					$toReturn.=$criticita;
					if($levelsAvailable){
						$registered =FALSE;
						$registrations = $this->getRegistrations($telegramUserId);
						if(!is_null($registrations)){
							$registered = in_array($stationId, $registrations);
						}
						if(!$registered){
							$toReturn.="Iscriviti a questa stazione:".PHP_EOL;
							$toReturn.='/Segui_'.$stationId.PHP_EOL;
						}else{
							$toReturn.="Sei iscritto a questa stazione, puoi disiscriverti:".PHP_EOL;
							$toReturn.='/NonSeguire_'.$stationId.PHP_EOL;
						}
					}
				}else{
					
				}
			}else{
				//Not hydro station.
				$toReturn = $this->info_notIdroStationId();
				$toReturn.= $this->getHelpMessageFor($callerCommandName);
			}
		}else{
			//Load of xml failed, bad station id or network problems.
			$toReturn = $this->info_wrongStationId();
			$toReturn.= $this->getSyntaxForCommand($callerCommandName);
			$toReturn.= $this->getHelpMessageFor($callerCommandName);
			$this->log('Function: '.__FUNCTION__.'; Line: '.__LINE__.'; Warning: failed to load XML at URL: '.$xml_url.'; Ok if the station doesn\'t exist.');//LOG
		}
		return $toReturn;
	}
	
	
	/**
	 * @return Array with the identifiers of the stations that have defined the level of criticality.
	 */
	private function getStationsIdWithCriticalityLevels(){		
		$stationsId = array();
		$xml_url = $this->getCriticalityLevelsURL();
		$xml = simplexml_load_file($xml_url);
		if($xml){
			$i=0;
			foreach ($xml->STAZIONE as $stazione){
				$idStazione=(int)$stazione->IDSTAZ;
				$stationsId[$i] = $idStazione;
				$i++;
			}
		}else{
			//Failed to open XML file with criticality levels.
			$this->logXmlError(__LINE__,__FUNCTION__,$xml_url);//LOG
		}
		return $stationsId;
	}
	
	
	/**
	 * @param $stationId Identifier of a station.
	 * @return Array with the identifiers of the chats of the subscribers of the indicated station.
	 */
	private function getStationSubscribersChats($stationId){
		$stationSubscribersChats = array();
		include('connect_DB.php');
		if(!$mysqli->connect_error){
			if($res = $mysqli->query("CALL p_getStationSubscribersChats(\"$stationId\")")){
				while($row = $res->fetch_assoc()){
					array_push($stationSubscribersChats, (int)$row['chatId']);
				}
			}else{
				//Error on procedure call.
				$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
		}else{
			//Error on database connection.
			$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
		}
		$mysqli->close();
		return $stationSubscribersChats;
	}
	
	
	/**
	 * @param $subscribedStations Associative array with the identifiers of the stations as Keys.
	 * @return Associative array representing levels of criticality.
	 */
	private function getCrticalityLevels($subscribedStations){
		$criticalityLevels = array();
		$xml_url = $this->getCriticalityLevelsURL();
		$xml = simplexml_load_file($xml_url);
		if($xml){
			foreach ($subscribedStations as $stationId => $lastCriticalityState) {
				$criticalityLevels[$stationId]['ORDINARIA'] = (float)$xml->xpath('//STAZIONE[IDSTAZ="'.$stationId.'"]/ORDINARIA')[0];
				$criticalityLevels[$stationId]['MODERATA'] = (float)$xml->xpath('//STAZIONE[IDSTAZ="'.$stationId.'"]/MODERATA')[0];
				$criticalityLevels[$stationId]['ELEVATA'] = (float)$xml->xpath('//STAZIONE[IDSTAZ="'.$stationId.'"]/ELEVATA')[0];
			}
		}else{
			//Failed to open XML file with criticality levels.
			$this->logXmlError(__LINE__,__FUNCTION__,$xml_url);//LOG
		}
		return $criticalityLevels;
	}	
	
	
	/**
	 * @param $subscribedStations Associative array with the identifiers of the stations as Keys. 
	 * @return Associative array representing the current water levels.
	 */
	private function getIdroLevels($subscribedStations){
		$idroLevels = array();
		foreach ($subscribedStations as $stationId => $lastCriticalityState) {
			$xml_url = $this->getWaterLevelsURL($stationId);
			$xml = simplexml_load_file($xml_url);
			if($xml){
				$misurazione = $xml->STAZIONE->SENSORE->xpath('DATI[last()]/VM');
				if(!empty($misurazione)){
					$misurazione = (string)$misurazione[0];
					if(is_numeric($misurazione)){
						$idroLevels[$stationId] = (float)$misurazione;
					}else{
						$idroLevels[$stationId] = NULL;
					}
				}else{
					$this->log('Function: '.__FUNCTION__.'; Line: '.__LINE__.'; $xml_url:'.$xml_url.'; Level data is empty.');//LOG
				}
			}else{
				//Failed to open XML file of the station.
				$this->logXmlError(__LINE__,__FUNCTION__,$xml_url);//LOG
			}
		}
		return $idroLevels;
	}
	
	
	/**
	 * Used to get stations that have at least one subscriber.
	 * @return Associative array with the identifiers of the stations as Keys and 'lastCriticalityState' as Values.
	 */
	private function getSubscribedStations() {
		include('connect_DB.php');
		$a=array();
		if(!$mysqli->connect_error){
			if($res=$mysqli->query("CALL p_getLastCriticalityState")){
				while($row = $res->fetch_assoc()){
					if(is_null($row['lastCriticalityState'])){
						$a[$row['stationId']] = NULL;
					}else{
						$a[$row['stationId']] = (int)$row['lastCriticalityState'];
					}
				}
			}else{
				//Error on procedure call.
				$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
			}
		}else{
			//Error on database connection.
			$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
		}
		$mysqli->close();
		return $a;
	}
	
	
	/**
	 * @return Array containing names of rivers as the keys and the number of stations as the values.
	 */
	private function getRivers(){
		$rivers = Array();
		$xml_url = $this->getHydroStationsURL();
		$xml = simplexml_load_file($xml_url);
		if($xml){
			foreach ($xml->STAZIONE as $station){
				$riverName = $this->getRiverNameFromStationName($station->NOME);
				if(isset($rivers[$riverName])){
					$rivers[$riverName]++;
				}else{
					$rivers[$riverName] = 1;
				}
			}
		}else{
			//Failed to open XML file with the list of hydro stations.
			$this->logXmlError(__LINE__,__FUNCTION__,$xml_url);//LOG
		}
		return $rivers;
	}
	
	
	//ARRAYS FUNCTIONS - END//
	
	
	//NOTIFICATION FUNCTIONS - BEGIN//
	

	/**
	 * Check if the water levels of the stations signed by someone have changed the state of criticality.
	 * And for every station (with changed criticality status) sends a message to all of the users subscribed to it.
	 */
	private function sendInfoToFollowers() {
		$subscribedStations = $this->getSubscribedStations();
		$criticalityLevels = $this->getCrticalityLevels($subscribedStations);
		if(!empty($criticalityLevels)){
			$idroLevels = $this->getIdroLevels($subscribedStations);
			$nullStations = array();
			$changedCriticalityStates = $this->getChangedCriticalityStates($subscribedStations, $criticalityLevels, $idroLevels, $nullStations);
			//Save on db new states.
			$this->saveCriticalityStates($changedCriticalityStates+$nullStations);
			//Send messages to all followers of each station with changed criticality state.
			$this->sendCriticalityChangeMessages($changedCriticalityStates);
		}
	}
	
	
	/**
	 * Send a message to all users registered to the indicated stations.
	 * @param $changedCriticalityStates Associative array containing for every station with the changed state of criticality: 'currentCriticalityState' and 'previousCriticalityState'.
	 */
	private function sendCriticalityChangeMessages($changedCriticalityStates){
		foreach ($changedCriticalityStates as $stationId => $criticalityStates) {
			$currentCriticalityState = $criticalityStates['currentCriticalityState'];
			$previousCriticalityState = $criticalityStates['previousCriticalityState'];
			$this->sendCriticalityChangeMessageToStationSubscribers($stationId, $currentCriticalityState, $previousCriticalityState);
		}
	}
	
	/**
	 * Sends a message to each subscriber of the indicated station.
	 * @param $stationId Identifier of the station. 
	 * @param $currentCriticalityState Current state of criticality.
	 * @param $previousCriticalityState Previous state of criticality.
	 */
	private function sendCriticalityChangeMessageToStationSubscribers($stationId, $currentCriticalityState, $previousCriticalityState){
		$stationSubscribersChats = $this->getStationSubscribersChats($stationId);
		$currentCriticalityStateMessage = $this->getCriticalityStateMessage($currentCriticalityState);
		$previousCriticalityStateMessage = $this->getCriticalityStateMessage($previousCriticalityState);
		foreach($stationSubscribersChats as $chat_id){
			$toSend = $this->info_criticalityChangeMessage($stationId, $currentCriticalityStateMessage, $previousCriticalityStateMessage);
			$this->sendTextMessage($toSend, $chat_id);
		}
	}
	
	
	/**
	 * Save on database, the new criticality levels.
	 * @param $changedCriticalityStates Associative array containing for every station with the changed state of criticality: 'currentCriticalityState'. 
	 */
	private function saveCriticalityStates($changedCriticalityStates){
	  include('connect_DB.php');
		if(!$mysqli->connect_error){
			foreach ($changedCriticalityStates as $stationId => $criticalityStates) {
				$currentCriticalityState = $criticalityStates['currentCriticalityState'];
				if ($mysqli->query("CALL p_setLastCriticalityState(\"$stationId\", \"$currentCriticalityState\")")){
				}else{
					//Error on procedure call.
					$this->logProcedureCallError(__LINE__,__FUNCTION__,$mysqli);//LOG
				}
			}
		}else{
			//Error on database connection.
			$this->logDatabaseConnectionError(__LINE__,__FUNCTION__,$mysqli);//LOG
		}
		$mysqli->close();
	}
	
	
	//NOTIFICATION FUNCTIONS - END//
	
	
}
