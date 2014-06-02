<?php

namespace MrPhil;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\Callbacks;
use ManiaControl\Commands\CommandListener;
use ManiaControl\ManiaControl;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Maps\Map;
use ManiaControl\Server\Server;

/**
 * ModeSwitcher (small description)
 *
 * @author Mr.Phil
 */

class ModeSwitcher implements CallbackListener, CommandListener, Plugin {
	/**
	 * Constants
	 */
	const ID					= 99;
	const VERSION			= 0.1;
	const NAME				= 'ModeSwitcher';
	const AUTHOR			= 'Mr.Phil';

	const USED_MODES			= 'Matchsettings of the modes separated with commas';
	const CHAT_MESSAGES		= 'Activate chat messages for mode switcher';

	/**
	 * Private properties
	 */
	/** @var ManiaControl $maniaControl */
	private $maniaControl = null;
	private $matchSettings = array();
	private $scripts = array();
	private $rand = 0;

	/**
	 * @see \ManiaControl\Plugins\Plugin::prepare()
	 */
	public static function prepare(ManiaControl $maniaControl) {
	}

	/**
	 * Load the plugin
	 *
	 * @param \ManiaControl\ManiaControl $maniaControl
	 */
	public function load(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		$this->checkConfig();

		$this->maniaControl->callbackManager->registerCallbackListener(Callbacks::BEGINMATCH, $this, 'handleOnBeginMatch');
		$this->maniaControl->callbackManager->registerCallbackListener(Callbacks::ENDMAP, $this, 'handleOnEndMap');
		$this->maniaControl->callbackManager->registerCallbackListener(Callbacks::ENDMATCH, $this, 'handleOnEndMatch');

		$this->maniaControl->settingManager->initSetting($this, self::USED_MODES, 'Mode1.txt,Mode2.txt');
		$this->maniaControl->settingManager->initSetting($this, self::CHAT_MESSAGES, true);
	}

	/**
	 * Function that check if plugin is correctly configured before enabling it.
	 *
	 * @throws \Exception
	 */
	private function checkConfig() {
		if ($this->maniaControl->settingManager->getSettingValue($this, self::USED_MODES) == 'Mode1.txt,Mode2.txt') {
			throw new \Exception('Missing the required matchsettings in plugin\'s config. Please set them before enabling the plugin.');
		}

		$this->matchSettings = $this->getMatchSettingFilenames();
		$this->scripts = $this->getScriptFilenames();
		$this->rand = rand(0, count($this->matchSettings) - 1);
	}

	/**
	 * Handle on Begin Match
	 */
	public function handleOnBeginMatch() {
		//$this->maniaControl->client->setScriptName('ShootMania\\' . $script);
		$this->sendChatMessage('handleOnBeginMatch');
	}

	/**
	 * Handle on End Map
	 *
	 * @param Map $map
	 */
	public function handleOnEndMap(Map $map) {
		//$this->maniaControl->client->loadMatchSettings('MatchSettings/' . $this->matchSettings[$this->rand]);

		$this->sendChatMessage('handleOnEndMap');
	}

	/**
	 * Handle on End Map
	 *
	 * @param Int MatchNumber
	 */
	public function handleOnEndMatch($matchNumber) {
		$this->maniaControl->client->loadMatchSettings('MatchSettings/' . $this->matchSettings[$this->rand]);

		$gameDataDirectory = $this->maniaControl->client->gameDataDirectory();
		$file = $gameDataDirectory . 'Scripts/Modes/ShootMania/' . $this->scripts[$this->rand];
		$script = file_get_contents($file);
		$this->maniaControl->client->setModeScriptText($script);
		$this->maniaControl->client->setScriptName(rtrim($this->scripts[$this->rand]));
		$this->rand = rand(0, count($this->matchSettings) - 1);

		$this->sendChatMessage('handleOnEndMatch');
		$this->sendChatMessage('$fff[ModeSwitcher] Next gamemode will be ' . rtrim($this->scripts[$this->rand]) . '!');
	}

	/**
	 * Return an array with matchsetting filenames
	 *
	 * @return Array Matchsetting filenames
	 */
	private function getMatchSettingFilenames() {
		return array_map('trim', explode(",", $this->maniaControl->settingManager->getSettingValue($this, self::USED_MODES)));
	}

	/**
	 * Return an array with script filenames for the matchsetting files
	 *
	 * @return Array Script filenames
	 */
	private function getScriptFilenames() {
		$scripts = array();
		$matchSettingsDirectory = $this->maniaControl->client->gameDataDirectory() . 'Maps/MatchSettings/';

		foreach($this->matchSettings as $matchSetting) {
			if ($xml = simplexml_load_string(file_get_contents($matchSettingsDirectory . $matchSetting))) {
				$scripts[] = $xml->gameinfos->script_name;
			} else {
				throw new \Exception('The file "' . $matchSetting . '" wasn\'t found. Please check your config.');
			}
		}
		return $scripts;
	}

	/**
	 * Send a message in chat if the chat setting is enabled
	 *
	 * @param String $message
	 */
	private function sendChatMessage($message) {
		if ($this->maniaControl->settingManager->getSettingValue($this, self::CHAT_MESSAGES)) {
			$this->maniaControl->chat->sendChat($message);
		}
	}

	/**
	 * Unload the plugin and its resources
	 */
	public function unload() {
		$this->maniaControl = null;
	}

	/**
	 * Get plugin id
	 *
	 * @return int
	 */
	public static function getId() {
		return self::ID;
	}

	/**
	 * Get Plugin Name
	 *
	 * @return string
	 */
	public static function getName() {
		return self::NAME;
	}

	/**
	 * Get Plugin Version
	 *
	 * @return float
	 */
	public static function getVersion() {
		return self::VERSION;
	}

	/**
	 * Get Plugin Author
	 *
	 * @return string
	 */
	public static function getAuthor() {
		return self::AUTHOR;
	}

	/**
	 * Get Plugin Description
	 *
	 * @return string
	 */
	public static function getDescription() {
		return 'ModeSwitcher';
	}
}