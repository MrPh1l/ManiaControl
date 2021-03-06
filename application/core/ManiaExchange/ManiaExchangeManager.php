<?php

namespace ManiaControl\ManiaExchange;

use ManiaControl\ManiaControl;
use ManiaControl\Maps\Map;
use ManiaControl\Maps\MapManager;
use Maniaplanet\DedicatedServer\Xmlrpc\GameModeException;

/**
 * Mania Exchange Info Searcher Class
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class ManiaExchangeManager {
	/*
	 * Constants
	 */
	//Search others
	const SEARCH_ORDER_NONE               = -1;
	const SEARCH_ORDER_TRACK_NAME         = 0;
	const SEARCH_ORDER_AUTHOR             = 1;
	const SEARCH_ORDER_UPLOADED_NEWEST    = 2;
	const SEARCH_ORDER_UPLOADED_OLDEST    = 3;
	const SEARCH_ORDER_UPDATED_NEWEST     = 4;
	const SEARCH_ORDER_UPDATED_OLDEST     = 5;
	const SEARCH_ORDER_ACTIVITY_LATEST    = 6;
	const SEARCH_ORDER_ACTIVITY_OLDEST    = 7;
	const SEARCH_ORDER_AWARDS_MOST        = 8;
	const SEARCH_ORDER_AWARDS_LEAST       = 9;
	const SEARCH_ORDER_COMMENTS_MOST      = 10;
	const SEARCH_ORDER_COMMENTS_LEAST     = 11;
	const SEARCH_ORDER_DIFFICULTY_EASIEST = 12;
	const SEARCH_ORDER_DIFFICULTY_HARDEST = 13;
	const SEARCH_ORDER_LENGTH_SHORTEST    = 14;
	const SEARCH_ORDER_LENGTH_LONGEST     = 15;

	//Maximum Maps per request
	const MAPS_PER_MX_FETCH = 50;

	const MIN_EXE_BUILD = "2014-04-01_00_00";

	/*
	 * Private Properties
	 */
	private $maniaControl = null;
	private $mxIdUidVector = array();

	/**
	 * Construct map manager
	 *
	 * @param \ManiaControl\ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
	}

	/**
	 * Unset Map by Mx Id
	 *
	 * @param int $mxId
	 */
	public function unsetMap($mxId) {
		if (isset($this->mxIdUidVector[$mxId])) {
			unset($this->mxIdUidVector[$mxId]);
		}
	}

	/**
	 * Fetch Map Information from Mania Exchange
	 *
	 * @param mixed $maps
	 */
	public function fetchManiaExchangeMapInformation($maps = null) {
		if (!$maps) {
			//Fetch Information for whole MapList
			$maps = $this->maniaControl->mapManager->getMaps();
		} else {
			//Fetch Information for a single map
			$maps = (array)$maps;
		}

		$mysqli      = $this->maniaControl->database->mysqli;
		$mapIdString = '';

		// Fetch mx ids
		$fetchMapQuery     = "SELECT `mxid`, `changed`  FROM `" . MapManager::TABLE_MAPS . "`
				WHERE `index` = ?;";
		$fetchMapStatement = $mysqli->prepare($fetchMapQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return;
		}

		$id = 0;
		foreach ($maps as $map) {
			/** @var Map $map */
			$fetchMapStatement->bind_param('i', $map->index);
			$fetchMapStatement->execute();
			if ($fetchMapStatement->error) {
				trigger_error($fetchMapStatement->error);
				continue;
			}
			$fetchMapStatement->store_result();
			$fetchMapStatement->bind_result($mxId, $changed);
			$fetchMapStatement->fetch();
			$fetchMapStatement->free_result();

			//Set changed time into the map object
			$map->lastUpdate = strtotime($changed);

			if ($mxId != 0) {
				$appendString = $mxId . ',';
				//Set the mx id to the mxidmapvektor
				$this->mxIdUidVector[$mxId] = $map->uid;
			} else {
				$appendString = $map->uid . ',';
			}

			$id++;

			//If Max Maplimit is reached, or string gets too long send the request
			if ($id % self::MAPS_PER_MX_FETCH == 0) {
				$mapIdString = substr($mapIdString, 0, -1);
				$this->getMaplistByMixedUidIdString($mapIdString);
				$mapIdString = '';
			}

			$mapIdString .= $appendString;
		}

		if ($mapIdString != '') {
			$mapIdString = substr($mapIdString, 0, -1);
			$this->getMaplistByMixedUidIdString($mapIdString);
		}

		$fetchMapStatement->close();
	}

	/**
	 * Get the Whole MapList from MX by Mixed Uid and Id String fetch
	 *
	 * @param string $string
	 * @return bool
	 */
	public function getMaplistByMixedUidIdString($string) {
		// Get Title Prefix
		$titlePrefix = $this->maniaControl->mapManager->getCurrentMap()->getGame();

		// compile search URL
		$url = 'http://api.mania-exchange.com/' . $titlePrefix . '/maps/?ids=' . $string;

		$thisRef = $this;
		$success = $this->maniaControl->fileReader->loadFile($url, function ($mapInfo, $error) use ($thisRef, $titlePrefix, $url) {
			if ($error) {
				trigger_error("Error: '{$error}' for Url '{$url}'");
				return;
			}
			if (!$mapInfo) {
				return;
			}

			$mxMapList = json_decode($mapInfo);
			if ($mxMapList === null) {
				trigger_error("Can't decode searched JSON Data from Url '{$url}'");
				return;
			}

			$maps = array();
			foreach ($mxMapList as $map) {
				if ($map) {
					$mxMapObject = new MXMapInfo($titlePrefix, $map);
					if ($mxMapObject) {
						array_push($maps, $mxMapObject);
					}
				}
			}

			$thisRef->updateMapObjectsWithManiaExchangeIds($maps);
		}, "application/json");

		return $success;
	}

	/**
	 * Store MX Map Info in the Database and the MX Info in the Map Object
	 *
	 * @param array $mxMapInfos
	 */
	public function updateMapObjectsWithManiaExchangeIds(array $mxMapInfos) {
		$mysqli = $this->maniaControl->database->mysqli;
		// Save map data
		$saveMapQuery     = "UPDATE `" . MapManager::TABLE_MAPS . "`
				SET `mxid` = ?
				WHERE `uid` = ?;";
		$saveMapStatement = $mysqli->prepare($saveMapQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return;
		}
		$saveMapStatement->bind_param('is', $mapMxId, $mapUId);
		foreach ($mxMapInfos as $mxMapInfo) {
			/** @var MXMapInfo $mxMapInfo */
			$mapMxId = $mxMapInfo->id;
			$mapUId  = $mxMapInfo->uid;
			$saveMapStatement->execute();
			if ($saveMapStatement->error) {
				trigger_error($saveMapStatement->error);
			}

			//Take the uid out of the vector
			if (isset($this->mxIdUidVector[$mxMapInfo->id])) {
				$uid = $this->mxIdUidVector[$mxMapInfo->id];
			} else {
				$uid = $mxMapInfo->uid;
			}
			$map = $this->maniaControl->mapManager->getMapByUid($uid);
			if ($map) {
				// TODO: how does it come that $map can be empty here? we got an error report for that
				/** @var Map $map */
				$map->mx = $mxMapInfo;
			}
		}
		$saveMapStatement->close();
	}

	/**
	 * Get Map Info Asynchronously
	 *
	 * @param int      $id
	 * @param callable $function
	 * @return bool
	 */
	public function getMapInfo($id, callable $function) {
		// Get Title Prefix
		$titlePrefix = $this->maniaControl->mapManager->getCurrentMap()->getGame();

		// compile search URL
		$url = 'http://api.mania-exchange.com/' . $titlePrefix . '/maps/?ids=' . $id;

		return $this->maniaControl->fileReader->loadFile($url, function ($mapInfo, $error) use (&$function, $titlePrefix, $url) {
			$mxMapInfo = null;
			if ($error) {
				trigger_error($error);
			} else {
				$mxMapList = json_decode($mapInfo);
				if (!is_array($mxMapList)) {
					trigger_error('Cannot decode searched JSON data from ' . $url);
				} else if (count($mxMapList) > 0) {
					$mxMapInfo = new MXMapInfo($titlePrefix, $mxMapList[0]);
				}
			}
			call_user_func($function, $mxMapInfo);
		}, "application/json");
	}

	/**
	 * Fetch a MapList Asynchronously
	 *
	 * @param        $function
	 * @param string $name
	 * @param string $author
	 * @param string $env
	 * @param int    $maxMapsReturned
	 * @param int    $searchOrder
	 * @return bool
	 */
	public function getMapsAsync($function, $name = '', $author = '', $env = '', $maxMapsReturned = 100, $searchOrder = self::SEARCH_ORDER_UPDATED_NEWEST) {
		if (!is_callable($function)) {
			$this->maniaControl->log("Function is not callable");
			return false;
		}

		// Get Title Id
		$titleId     = $this->maniaControl->server->titleId;
		$titlePrefix = $this->maniaControl->mapManager->getCurrentMap()->getGame();

		// compile search URL
		$url = 'http://' . $titlePrefix . '.mania-exchange.com/tracksearch2/search?api=on';

		$game      = explode('@', $titleId);
		$envNumber = $this->getEnvironment($game[0]);
		if ($env != '' || $envNumber != -1) {
			$url .= '&environments=' . $envNumber;
		}
		if ($name != '') {
			$url .= '&trackname=' . str_replace(" ", "%20", $name);
		}
		if ($author != '') {
			$url .= '&author=' . $author;
		}

		$url .= '&priord=' . $searchOrder;
		$url .= '&limit=' . $maxMapsReturned;

		if ($titlePrefix != "tm") {
			$url .= '&minexebuild=' . self::MIN_EXE_BUILD;
		}

		// Get MapTypes
		try {
			$scriptInfos = $this->maniaControl->client->getModeScriptInfo();
			$mapTypes    = $scriptInfos->compatibleMapTypes;
			$url .= '&mtype=' . $mapTypes;
		} catch (GameModeException $e) {
		}

		$success = $this->maniaControl->fileReader->loadFile($url, function ($mapInfo, $error) use (&$function, $titlePrefix) {
			if ($error) {
				trigger_error($error);
				return;
			}

			$mxMapList = json_decode($mapInfo);

			if (!isset($mxMapList->results)) {
				trigger_error('Cannot decode searched JSON data');
				return;
			}

			$mxMapList = $mxMapList->results;

			if ($mxMapList === null) {
				trigger_error('Cannot decode searched JSON data');
				return;
			}

			$maps = array();
			foreach ($mxMapList as $map) {
				if (!empty($map)) {
					array_push($maps, new MXMapInfo($titlePrefix, $map));
				}
			}

			call_user_func($function, $maps);
		}, "application/json");

		return $success;
	}

	/**
	 * Get the Current Environment by String
	 *
	 * @param string $env
	 * @return int
	 */
	private function getEnvironment($env) {
		switch ($env) {
			case 'TMCanyon':
				return 1;
			case 'TMStadium':
				return 2;
			case 'TMValley':
				return 3;
			default:
				return -1;
		}
	}
}