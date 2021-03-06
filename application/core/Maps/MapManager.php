<?php

namespace ManiaControl\Maps;

use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\Callbacks;
use ManiaControl\Files\FileUtil;
use ManiaControl\ManiaControl;
use ManiaControl\ManiaExchange\ManiaExchangeList;
use ManiaControl\ManiaExchange\ManiaExchangeManager;
use ManiaControl\ManiaExchange\MXMapInfo;
use ManiaControl\Players\Player;
use Maniaplanet\DedicatedServer\InvalidArgumentException;
use Maniaplanet\DedicatedServer\Xmlrpc\Exception;
use Maniaplanet\DedicatedServer\Xmlrpc\FileException;
use Maniaplanet\DedicatedServer\Xmlrpc\IndexOutOfBoundException;
use Maniaplanet\DedicatedServer\Xmlrpc\InvalidMapException;
use Maniaplanet\DedicatedServer\Xmlrpc\NotInListException;

// TODO: adding of local maps

/**
 * Manager for Maps
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class MapManager implements CallbackListener {
	/*
	 * Constants
	 */
	const TABLE_MAPS                      = 'mc_maps';
	const CB_MAPS_UPDATED                 = 'MapManager.MapsUpdated';
	const CB_KARMA_UPDATED                = 'MapManager.KarmaUpdated';
	const SETTING_PERMISSION_ADD_MAP      = 'Add Maps';
	const SETTING_PERMISSION_REMOVE_MAP   = 'Remove Maps';
	const SETTING_PERMISSION_SHUFFLE_MAPS = 'Shuffle Maps';
	const SETTING_PERMISSION_CHECK_UPDATE = 'Check Map Update';
	const SETTING_PERMISSION_SKIP_MAP     = 'Skip Map';
	const SETTING_PERMISSION_RESTART_MAP  = 'Restart Map';
	const SETTING_AUTOSAVE_MAPLIST        = 'Autosave Maplist file';
	const SETTING_MAPLIST_FILE            = 'File to write Maplist in';

	/**  @deprecated Use Callbacks Interface */
	const CB_BEGINMAP = 'Callbacks.BeginMap';
	/**  @deprecated Use Callbacks Interface */
	const CB_ENDMAP = 'Callbacks.EndMap';

	/*
	 * Public Properties
	 */
	public $mapQueue = null;
	public $mapCommands = null;
	public $mapList = null;
	public $mxList = null;
	public $mxManager = null;
	public $mapActions = null;

	/*
	 * Private Properties
	 */
	private $maniaControl = null;
	private $maps = array();
	/** @var Map $currentMap */
	private $currentMap = null;
	private $mapEnded = false;
	private $mapBegan = false;

	/**
	 * Construct a new Map Manager
	 *
	 * @param ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		$this->initTables();

		// Create map commands instance
		$this->mxManager   = new ManiaExchangeManager($this->maniaControl);
		$this->mapList     = new MapList($this->maniaControl);
		$this->mxList      = new ManiaExchangeList($this->maniaControl);
		$this->mapCommands = new MapCommands($maniaControl);
		$this->mapQueue    = new MapQueue($this->maniaControl);
		$this->mapActions  = new MapActions($maniaControl);

		// Register for callbacks
		$this->maniaControl->callbackManager->registerCallbackListener(Callbacks::ONINIT, $this, 'handleOnInit');
		$this->maniaControl->callbackManager->registerCallbackListener(Callbacks::AFTERINIT, $this, 'handleAfterInit');
		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MP_MAPLISTMODIFIED, $this, 'mapsModified');

		// Define Rights
		$this->maniaControl->authenticationManager->definePermissionLevel(self::SETTING_PERMISSION_ADD_MAP, AuthenticationManager::AUTH_LEVEL_ADMIN);
		$this->maniaControl->authenticationManager->definePermissionLevel(self::SETTING_PERMISSION_REMOVE_MAP, AuthenticationManager::AUTH_LEVEL_ADMIN);
		$this->maniaControl->authenticationManager->definePermissionLevel(self::SETTING_PERMISSION_SHUFFLE_MAPS, AuthenticationManager::AUTH_LEVEL_ADMIN);
		$this->maniaControl->authenticationManager->definePermissionLevel(self::SETTING_PERMISSION_CHECK_UPDATE, AuthenticationManager::AUTH_LEVEL_MODERATOR);
		$this->maniaControl->authenticationManager->definePermissionLevel(self::SETTING_PERMISSION_SKIP_MAP, AuthenticationManager::AUTH_LEVEL_MODERATOR);
		$this->maniaControl->authenticationManager->definePermissionLevel(self::SETTING_PERMISSION_RESTART_MAP, AuthenticationManager::AUTH_LEVEL_MODERATOR);

		$this->maniaControl->settingManager->initSetting($this, self::SETTING_AUTOSAVE_MAPLIST, true);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_MAPLIST_FILE, "MatchSettings/tracklist.txt");
	}

	/**
	 * Initialize necessary Database Tables
	 *
	 * @return bool
	 */
	private function initTables() {
		$mysqli = $this->maniaControl->database->mysqli;
		$query  = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_MAPS . "` (
				`index` int(11) NOT NULL AUTO_INCREMENT,
				`mxid` int(11),
				`uid` varchar(50) NOT NULL,
				`name` varchar(150) NOT NULL,
				`authorLogin` varchar(100) NOT NULL,
				`fileName` varchar(100) NOT NULL,
				`environment` varchar(50) NOT NULL,
				`mapType` varchar(50) NOT NULL,
				`changed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`index`),
				UNIQUE KEY `uid` (`uid`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Map Data' AUTO_INCREMENT=1;";
		$result = $mysqli->query($query);
		if ($mysqli->error) {
			trigger_error($mysqli->error, E_USER_ERROR);
			return false;
		}
		return $result;
	}

	/**
	 * Update a Map from Mania Exchange
	 *
	 * @param Player $admin
	 * @param string $uid
	 */
	public function updateMap(Player $admin, $uid) {
		$this->updateMapTimestamp($uid);

		if (!isset($uid) || !isset($this->maps[$uid])) {
			trigger_error("Error while updating Map, unknown UID: " . $uid);
			$this->maniaControl->chat->sendError("Error while updating Map.", $admin->login);
			return;
		}

		/** @var Map $map */
		$map = $this->maps[$uid];

		$mxId = $map->mx->id;
		$this->removeMap($admin, $uid, true, false);
		$this->addMapFromMx($mxId, $admin->login, true);
	}

	/**
	 * Update the Timestamp of a Map
	 *
	 * @param string $uid
	 * @return bool
	 */
	private function updateMapTimestamp($uid) {
		$mysqli   = $this->maniaControl->database->mysqli;
		$mapQuery = "UPDATE `" . self::TABLE_MAPS . "` SET
				mxid = 0,
				changed = NOW()
				WHERE 'uid' = ?";
		$mapStatement = $mysqli->prepare($mapQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		$mapStatement->bind_param('s', $uid);
		$mapStatement->execute();
		if ($mapStatement->error) {
			trigger_error($mapStatement->error);
			$mapStatement->close();
			return false;
		}
		$mapStatement->close();
		return true;
	}

	/**
	 * Remove a Map
	 *
	 * @param Player $admin
	 * @param string $uid
	 * @param bool   $eraseFile
	 * @param bool   $message
	 */
	public function removeMap(Player $admin, $uid, $eraseFile = false, $message = true) {
		if (!isset($this->maps[$uid])) {
			$this->maniaControl->chat->sendError("Map does not exist!", $admin);
			return;
		}

		/** @var Map $map */
		$map = $this->maps[$uid];

		// Unset the Map everywhere
		$this->mapQueue->removeFromMapQueue($admin, $map->uid);

		if ($map->mx) {
			$this->mxManager->unsetMap($map->mx->id);
		}

		// Remove map
		try {
			$this->maniaControl->client->removeMap($map->fileName);
		} catch (NotInListException $e) {
		}

		unset($this->maps[$uid]);

		if ($eraseFile) {
			// Check if ManiaControl can even write to the maps dir
			$mapDir = $this->maniaControl->client->getMapsDirectory();

			// Delete map file
			if (!@unlink($mapDir . $map->fileName)) {
				trigger_error("Couldn't remove Map '{$mapDir}{$map->fileName}'.");
				$this->maniaControl->chat->sendError("ManiaControl couldn't remove the MapFile.", $admin);
				return;
			}
		}

		// Show Message
		if ($message) {
			$message = $admin->getEscapedNickname() . ' removed ' . $map->getEscapedName() . '!';
			$this->maniaControl->chat->sendSuccess($message);
			$this->maniaControl->log($message, true);
		}
	}

	/**
	 * Adds a Map from Mania Exchange
	 *
	 * @param int    $mapId
	 * @param string $login
	 * @param bool   $update
	 */
	public function addMapFromMx($mapId, $login, $update = false) {
		if (is_numeric($mapId)) {
			// Check if map exists
			$self = $this;
			$this->maniaControl->mapManager->mxManager->getMapInfo($mapId, function (MXMapInfo $mapInfo) use (&$self, &$login, &$update) {
				if (!$mapInfo || !isset($mapInfo->uploaded)) {
					// Invalid id
					$self->maniaControl->chat->sendError('Invalid MX-Id!', $login);
					return;
				}

				// Download the file
				$self->maniaControl->fileReader->loadFile($mapInfo->downloadurl, function ($file, $error) use (&$self, &$login, &$mapInfo, &$update) {
					if (!$file || $error) {
						// Download error
						$self->maniaControl->chat->sendError("Download failed: '{$error}'!", $login);
						return;
					}
					$self->processMapFile($file, $mapInfo, $login, $update);
				});
			});
		}
	}

	/**
	 * Process the MapFile
	 *
	 * @param string    $file
	 * @param MXMapInfo $mapInfo
	 * @param string    $login
	 * @param bool      $update
	 * @throws InvalidArgumentException
	 */
	private function processMapFile($file, MXMapInfo $mapInfo, $login, $update) {
		// Check if map is already on the server
		if ($this->getMapByUid($mapInfo->uid)) {
			$this->maniaControl->chat->sendError('Map is already on the server!', $login);
			return;
		}

		// Save map
		$fileName = $mapInfo->id . '_' . $mapInfo->name . '.Map.Gbx';
		$fileName = FileUtil::getClearedFileName($fileName);

		$downloadFolderName  = $this->maniaControl->settingManager->getSettingValue($this, 'MapDownloadDirectory', 'MX');
		$relativeMapFileName = $downloadFolderName . DIRECTORY_SEPARATOR . $fileName;
		$mapDir              = $this->maniaControl->client->getMapsDirectory();
		$downloadDirectory   = $mapDir . DIRECTORY_SEPARATOR . $downloadFolderName . DIRECTORY_SEPARATOR;
		$fullMapFileName     = $downloadDirectory . $fileName;

		// Check if it can get written locally
		if (is_dir($mapDir)) {
			// Create download directory if necessary
			if (!is_dir($downloadDirectory) && !mkdir($downloadDirectory)) {
				trigger_error("ManiaControl doesn't have to rights to save maps in '{$downloadDirectory}'.");
				$this->maniaControl->chat->sendError("ManiaControl doesn't have the rights to save maps.", $login);
				return;
			}

			if (!file_put_contents($fullMapFileName, $file)) {
				// Save error
				$this->maniaControl->chat->sendError('Saving map failed!', $login);
				return;
			}
		} else {
			// Write map via write file method
			try {
				$this->maniaControl->client->writeFile($relativeMapFileName, $file);
			} catch (InvalidArgumentException $e) {
				if ($e->getMessage() == 'data are too big') {
					$this->maniaControl->chat->sendError("Map is too big for a remote save.", $login);
					return;
				}
				throw $e;
			}
		}

		// Check for valid map
		try {
			$this->maniaControl->client->checkMapForCurrentServerParams($relativeMapFileName);
		} catch (InvalidMapException $e) {
			$this->maniaControl->chat->sendError('Wrong MapType or not validated!', $login);
			return;
		}

		// Add map to map list
		$this->maniaControl->client->insertMap($relativeMapFileName);
		$this->updateFullMapList();

		// Update Mx MapInfo
		$this->maniaControl->mapManager->mxManager->updateMapObjectsWithManiaExchangeIds(array($mapInfo));

		// Update last updated time
		$map = $this->getMapByUid($mapInfo->uid);
		if (!$map) {
			// TODO: improve this - error reports about not existing maps
			$this->maniaControl->errorHandler->triggerDebugNotice('Map not in List after Insert!');
			$this->maniaControl->chat->sendError('Server Error!', $login);
			return;
		}
		$map->lastUpdate = time();

		$player = $this->maniaControl->playerManager->getPlayer($login);

		if (!$update) {
			// Message
			$message = '$<' . $player->nickname . '$> added $<' . $mapInfo->name . '$>!';
			$this->maniaControl->chat->sendSuccess($message);
			$this->maniaControl->log($message, true);
			// Queue requested Map
			$this->maniaControl->mapManager->mapQueue->addMapToMapQueue($login, $mapInfo->uid);
		} else {
			$message = '$<' . $player->nickname . '$> updated $<' . $mapInfo->name . '$>!';
			$this->maniaControl->chat->sendSuccess($message);
			$this->maniaControl->log($message, true);
		}
	}

	/**
	 * Get Map by UID
	 *
	 * @param string $uid
	 * @return Map
	 */
	public function getMapByUid($uid) {
		if (isset($this->maps[$uid])) {
			return $this->maps[$uid];
		}
		return null;
	}

	/**
	 * Updates the full Map list, needed on Init, addMap and on ShuffleMaps
	 */
	private function updateFullMapList() {
		$tempList = array();

		try {
			$i = 0;
			while (true) {
				$maps = $this->maniaControl->client->getMapList(150, $i);

				foreach ($maps as $rpcMap) {
					if (array_key_exists($rpcMap->uId, $this->maps)) {
						// Map already exists, only update index
						$tempList[$rpcMap->uId] = $this->maps[$rpcMap->uId];
					} else {
						// Insert Map Object
						$map                 = $this->initializeMap($rpcMap);
						$tempList[$map->uid] = $map;
					}
				}

				$i += 150;
			}
		} catch (IndexOutOfBoundException $e) {
		}

		// restore Sorted MapList
		$this->maps = $tempList;

		// Trigger own callback
		$this->maniaControl->callbackManager->triggerCallback(self::CB_MAPS_UPDATED);

		// Write MapList
		if ($this->maniaControl->settingManager->getSettingValue($this, self::SETTING_AUTOSAVE_MAPLIST)) {
			$matchSettingsFileName = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_MAPLIST_FILE);
			try {
				$this->maniaControl->client->saveMatchSettings($matchSettingsFileName);
			} catch (FileException $e) {
				$this->maniaControl->log("Unable to write the playlist file, please checkout your MX-Folders File permissions!");
			}
		}
	}

	/**
	 * Initializes a Map
	 *
	 * @param mixed $rpcMap
	 * @return Map
	 */
	public function initializeMap($rpcMap) {
		$map = new Map($rpcMap);
		$this->saveMap($map);

		/*$mapsDirectory = $this->maniaControl->server->getMapsDirectory();
		if (is_readable($mapsDirectory . $map->fileName)) {
			$mapFetcher = new \GBXChallMapFetcher(true);
			$mapFetcher->processFile($mapsDirectory . $map->fileName);
			$map->authorNick = FORMATTER::stripDirtyCodes($mapFetcher->authorNick);
			$map->authorEInfo = $mapFetcher->authorEInfo;
			$map->authorZone = $mapFetcher->authorZone;
			$map->comment = $mapFetcher->comment;
		}*/
		return $map;
	}

	/**
	 * Save a Map in the Database
	 *
	 * @param Map $map
	 * @return bool
	 */
	private function saveMap(Map &$map) {
		//TODO saveMaps for whole maplist at once (usage of prepared statements)
		$mysqli   = $this->maniaControl->database->mysqli;
		$mapQuery = "INSERT INTO `" . self::TABLE_MAPS . "` (
				`uid`,
				`name`,
				`authorLogin`,
				`fileName`,
				`environment`,
				`mapType`
				) VALUES (
				?, ?, ?, ?, ?, ?
				) ON DUPLICATE KEY UPDATE
				`index` = LAST_INSERT_ID(`index`),
				`fileName` = VALUES(`fileName`),
				`environment` = VALUES(`environment`),
				`mapType` = VALUES(`mapType`);";

		$mapStatement = $mysqli->prepare($mapQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		$mapStatement->bind_param('ssssss', $map->uid, $map->rawName, $map->authorLogin, $map->fileName, $map->environment, $map->mapType);
		$mapStatement->execute();
		if ($mapStatement->error) {
			trigger_error($mapStatement->error);
			$mapStatement->close();
			return false;
		}
		$map->index = $mapStatement->insert_id;
		$mapStatement->close();
		return true;
	}

	/**
	 * Shuffles the MapList
	 *
	 * @param Player $admin
	 * @return bool
	 */
	public function shuffleMapList($admin = null) {
		$shuffledMaps = $this->maps;
		shuffle($shuffledMaps);

		$mapArray = array();

		foreach ($shuffledMaps as $map) {
			/**
			 * @var Map $map
			 */
			$mapArray[] = $map->fileName;
		}

		try {
			$this->maniaControl->client->chooseNextMapList($mapArray);
		} catch (Exception $e) {
			//TODO temp added 19.04.2014
			$this->maniaControl->errorHandler->triggerDebugNotice("Exception line 331 MapManager" . $e->getMessage());
			trigger_error("Couldn't shuffle mapList. " . $e->getMessage());
			return false;
		}

		$this->fetchCurrentMap();

		if ($admin) {
			$message = '$<' . $admin->nickname . '$> shuffled the Maplist!';
			$this->maniaControl->chat->sendSuccess($message);
			$this->maniaControl->log($message, true);
		}

		// Restructure if needed
		$this->restructureMapList();
		return true;
	}

	/**
	 * Freshly fetch current Map
	 *
	 * @return Map
	 */
	private function fetchCurrentMap() {
		$rpcMap = $this->maniaControl->client->getCurrentMapInfo();

		if (array_key_exists($rpcMap->uId, $this->maps)) {
			$this->currentMap                = $this->maps[$rpcMap->uId];
			$this->currentMap->nbCheckpoints = $rpcMap->nbCheckpoints;
			$this->currentMap->nbLaps        = $rpcMap->nbLaps;
			return $this->currentMap;
		}

		$this->currentMap                   = $this->initializeMap($rpcMap);
		$this->maps[$this->currentMap->uid] = $this->currentMap;
		return $this->currentMap;
	}

	/**
	 * Restructures the Maplist
	 */
	public function restructureMapList() {
		$currentIndex = $this->getMapIndex($this->currentMap);

		// No RestructureNeeded
		if ($currentIndex < Maplist::MAX_MAPS_PER_PAGE - 1) {
			return true;
		}

		$lowerMapArray  = array();
		$higherMapArray = array();

		$i = 0;
		foreach ($this->maps as $map) {
			if ($i < $currentIndex) {
				$lowerMapArray[] = $map->fileName;
			} else {
				$higherMapArray[] = $map->fileName;
			}
			$i++;
		}

		$mapArray = array_merge($higherMapArray, $lowerMapArray);
		array_shift($mapArray);

		try {
			$this->maniaControl->client->chooseNextMapList($mapArray);
		} catch (Exception $e) {
			trigger_error("Error while restructuring the Maplist. " . $e->getMessage());
			return false;
		}
		return true;
	}

	/**
	 * Returns the MapIndex of a given map
	 *
	 * @param Map $map
	 * @return int
	 */
	public function getMapIndex(Map $map) {
		$maps = $this->getMaps();
		return array_search($map, $maps);
	}

	/**
	 * Get all Maps
	 *
	 * @param int $offset
	 * @param int $length
	 * @return Map[]
	 */
	public function getMaps($offset = null, $length = null) {
		if ($offset === null) {
			return array_values($this->maps);
		}
		if ($length === null) {
			return array_slice($this->maps, $offset);
		}
		return array_slice($this->maps, $offset, $length);
	}

	/**
	 * Handle OnInit callback
	 */
	public function handleOnInit() {
		$this->updateFullMapList();
		$this->fetchCurrentMap();

		// Restructure Maplist
		$this->restructureMapList();
	}

	/**
	 * Handle AfterInit callback
	 */
	public function handleAfterInit() {
		// Fetch MX infos
		$this->mxManager->fetchManiaExchangeMapInformation();
	}

	/**
	 * Get Current Map
	 *
	 * @return Map
	 */
	public function getCurrentMap() {
		if (!$this->currentMap) {
			return $this->fetchCurrentMap();
		}
		return $this->currentMap;
	}

	/**
	 * Handle Script BeginMap callback
	 *
	 * @param string $mapUid
	 * @param bool   $restart
	 */
	public function handleScriptBeginMap($mapUid, $restart) {
		$this->beginMap($mapUid, strtolower($restart) === 'true' ? true : false);
	}

	/**
	 * Manage the Begin of a Map
	 *
	 * @param string $uid
	 * @param bool   $restart
	 */
	private function beginMap($uid, $restart = false) {
		//If a restart occurred, first call the endMap to set variables back
		if ($restart) {
			$this->endMap();
		}

		if ($this->mapBegan) {
			return;
		}
		$this->mapBegan = true;
		$this->mapEnded = false;

		if (array_key_exists($uid, $this->maps)) {
			// Map already exists, only update index
			$this->currentMap = $this->maps[$uid];
			if (!$this->currentMap->nbCheckpoints || !$this->currentMap->nbLaps) {
				$rpcMap                          = $this->maniaControl->client->getCurrentMapInfo();
				$this->currentMap->nbLaps        = $rpcMap->nbLaps;
				$this->currentMap->nbCheckpoints = $rpcMap->nbCheckpoints;
			}
		}

		// Restructure MapList if id is over 15
		$this->restructureMapList();

		// Update the mx of the map (for update checks, etc.)
		$this->mxManager->fetchManiaExchangeMapInformation($this->currentMap);

		// Trigger own BeginMap callback
		$this->maniaControl->callbackManager->triggerCallback(Callbacks::BEGINMAP, $this->currentMap);
	}

	/**
	 * Manage the End of a Map
	 */
	private function endMap() {
		if ($this->mapEnded) {
			return;
		}
		$this->mapEnded = true;
		$this->mapBegan = false;

		// Trigger own EndMap callback
		$this->maniaControl->callbackManager->triggerCallback(Callbacks::ENDMAP, $this->currentMap);
	}

	/**
	 * Handle BeginMap callback
	 *
	 * @param array $callback
	 */
	public function handleBeginMap(array $callback) {
		$this->beginMap($callback[1][0]["UId"]);
	}

	/**
	 * Handle Script EndMap Callback
	 *
	 * @param string $mapUid
	 */
	public function handleScriptEndMap($mapUid) {
		$this->endMap();
	}

	/**
	 * Handle EndMap Callback
	 *
	 * @param array $callback
	 */
	public function handleEndMap(array $callback) {
		$this->endMap();
	}

	/**
	 * Handle Maps Modified Callback
	 *
	 * @param array $callback
	 */
	public function mapsModified(array $callback) {
		$this->updateFullMapList();
	}

	/**
	 * Get the Number of Maps
	 *
	 * @return int
	 */
	public function getMapsCount() {
		return count($this->maps);
	}
}
