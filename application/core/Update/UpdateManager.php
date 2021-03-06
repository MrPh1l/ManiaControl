<?php

namespace ManiaControl\Update;

use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Commands\CommandListener;
use ManiaControl\Files\BackupUtil;
use ManiaControl\Files\FileUtil;
use ManiaControl\ManiaControl;
use ManiaControl\Players\Player;
use ManiaControl\Players\PlayerManager;

/**
 * Manager checking for ManiaControl Core Updates
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class UpdateManager implements CallbackListener, CommandListener, TimerListener {
	/*
	 * Constants
	 */
	const SETTING_ENABLE_UPDATECHECK     = 'Enable Automatic Core Update Check';
	const SETTING_UPDATECHECK_INTERVAL   = 'Core Update Check Interval (Hours)';
	const SETTING_UPDATECHECK_CHANNEL    = 'Core Update Channel (release, beta, nightly)';
	const SETTING_PERFORM_BACKUPS        = 'Perform Backup before Updating';
	const SETTING_AUTO_UPDATE            = 'Perform update automatically';
	const SETTING_PERMISSION_UPDATE      = 'Update Core';
	const SETTING_PERMISSION_UPDATECHECK = 'Check Core Update';
	const CHANNEL_RELEASE                = 'release';
	const CHANNEL_BETA                   = 'beta';
	const CHANNEL_NIGHTLY                = 'nightly';

	/*
	 * Public Properties
	 */
	/** @var PluginUpdateManager $pluginUpdateManager */
	public $pluginUpdateManager = null;
	/** @var UpdateData $coreUpdateData */
	public $coreUpdateData = null;

	/*
	 * Private Properties
	 */
	/** @var ManiaControl $maniaControl */
	private $maniaControl = null;
	private $currentBuildDate = null;

	/**
	 * Create a new Update Manager
	 *
	 * @param ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;

		// Init settings
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_ENABLE_UPDATECHECK, true);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_AUTO_UPDATE, true);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_UPDATECHECK_INTERVAL, 1);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_UPDATECHECK_CHANNEL, $this->getUpdateChannels());
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_PERFORM_BACKUPS, true);

		// Register for callbacks
		$updateInterval = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_UPDATECHECK_INTERVAL);
		$this->maniaControl->timerManager->registerTimerListening($this, 'hourlyUpdateCheck', 1000 * 60 * 60 * $updateInterval);
		$this->maniaControl->callbackManager->registerCallbackListener(PlayerManager::CB_PLAYERCONNECT, $this, 'handlePlayerJoined');
		$this->maniaControl->callbackManager->registerCallbackListener(PlayerManager::CB_PLAYERDISCONNECT, $this, 'handlePlayerDisconnect');

		// define Permissions
		$this->maniaControl->authenticationManager->definePermissionLevel(self::SETTING_PERMISSION_UPDATE, AuthenticationManager::AUTH_LEVEL_ADMIN);
		$this->maniaControl->authenticationManager->definePermissionLevel(self::SETTING_PERMISSION_UPDATECHECK, AuthenticationManager::AUTH_LEVEL_MODERATOR);

		// Register for chat commands
		$this->maniaControl->commandManager->registerCommandListener('checkupdate', $this, 'handle_CheckUpdate', true, 'Checks if there is a core update.');
		$this->maniaControl->commandManager->registerCommandListener('coreupdate', $this, 'handle_CoreUpdate', true, 'Performs the core update.');

		// Plugin update manager
		$this->pluginUpdateManager = new PluginUpdateManager($maniaControl);
	}

	/**
	 * Get the possible Update Channels
	 *
	 * @return string[]
	 */
	public function getUpdateChannels() {
		// TODO: change default channel on release
		return array(self::CHANNEL_BETA, self::CHANNEL_RELEASE, self::CHANNEL_NIGHTLY);
	}

	/**
	 * Perform Hourly Update Check
	 *
	 * @param float $time
	 */
	public function hourlyUpdateCheck($time) {
		$updateCheckEnabled = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_ENABLE_UPDATECHECK);
		if (!$updateCheckEnabled) {
			$this->setCoreUpdateData();
			return;
		}
		$this->checkUpdate();
	}

	/**
	 * Set Core Update Data
	 *
	 * @param UpdateData $coreUpdateData
	 */
	public function setCoreUpdateData(UpdateData $coreUpdateData = null) {
		$this->coreUpdateData = $coreUpdateData;
	}

	/**
	 * Start an Update Check
	 */
	public function checkUpdate() {
		$this->checkCoreUpdateAsync(array($this, 'handleUpdateCheck'));
	}

	/**
	 * Checks a Core Update asynchronously
	 *
	 * @param callable $function
	 */
	public function checkCoreUpdateAsync($function) {
		$updateChannel = $this->getCurrentUpdateChannelSetting();
		$url           = ManiaControl::URL_WEBSERVICE . 'versions?current=1&channel=' . $updateChannel;

		$this->maniaControl->fileReader->loadFile($url, function ($dataJson, $error) use (&$function) {
			$versions = json_decode($dataJson);
			if (!$versions || !isset($versions[0])) {
				call_user_func($function);
			} else {
				$updateData = new UpdateData($versions[0]);
				call_user_func($function, $updateData);
			}
		});
	}

	/**
	 * Retrieve the Update Channel Setting
	 *
	 * @return string
	 */
	public function getCurrentUpdateChannelSetting() {
		$updateChannel = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_UPDATECHECK_CHANNEL);
		$updateChannel = strtolower($updateChannel);
		if (!in_array($updateChannel, $this->getUpdateChannels())) {
			$updateChannel = self::CHANNEL_RELEASE;
		}
		return $updateChannel;
	}

	/**
	 * Handle the fetched Update Data of the hourly Check
	 *
	 * @param UpdateData $updateData
	 */
	public function handleUpdateCheck(UpdateData $updateData = null) {
		if (!$this->checkUpdateData($updateData)) {
			// No new update available
			return;
		}
		if (!$this->checkUpdateDataBuildVersion($updateData)) {
			// Server incompatible
			$this->maniaControl->log("Please update Your Server to '{$updateData->minDedicatedBuild}' in order to receive further Updates!");
			return;
		}

		if ($this->coreUpdateData != $updateData) {
			if ($this->isNightlyUpdateChannel()) {
				$this->maniaControl->log("New Nightly Build ({$updateData->releaseDate}) available!");
			} else {
				$this->maniaControl->log("New ManiaControl Version {$updateData->version} available!");
			}
			$this->setCoreUpdateData($updateData);
		}

		$this->checkAutoUpdate();
	}

	/**
	 * Check if the given Update Data has a new Version and fits for the Server
	 *
	 * @param UpdateData $updateData
	 * @return bool
	 */
	public function checkUpdateData(UpdateData $updateData = null) {
		if (!$updateData || !$updateData->url) {
			// Data corrupted
			return false;
		}

		$isNightly = $this->isNightlyUpdateChannel();
		$buildDate = $this->getNightlyBuildDate();

		if ($isNightly || $buildDate) {
			return $updateData->isNewerThan($buildDate);
		}

		return ($updateData->version > ManiaControl::VERSION);
	}

	/**
	 * Check if ManiaControl is running the Nightly Update Channel
	 *
	 * @param string $updateChannel
	 * @return bool
	 */
	public function isNightlyUpdateChannel($updateChannel = null) {
		if (!$updateChannel) {
			$updateChannel = $this->getCurrentUpdateChannelSetting();
		}
		return ($updateChannel === self::CHANNEL_NIGHTLY);
	}

	/**
	 * Get the Build Date of the local Nightly Build Version
	 *
	 * @return string
	 */
	public function getNightlyBuildDate() {
		if (!$this->currentBuildDate) {
			$nightlyBuildDateFile = ManiaControlDir . 'core' . DIRECTORY_SEPARATOR . 'nightly_build.txt';
			if (file_exists($nightlyBuildDateFile)) {
				$this->currentBuildDate = file_get_contents($nightlyBuildDateFile);
			}
		}
		return $this->currentBuildDate;
	}

	/**
	 * Check if the Update Data is compatible with the Server
	 *
	 * @param UpdateData $updateData
	 * @return bool
	 */
	public function checkUpdateDataBuildVersion(UpdateData $updateData = null) {
		if (!$updateData) {
			// Data corrupted
			return false;
		}

		$version = $this->maniaControl->client->getVersion();
		if ($updateData->minDedicatedBuild > $version->build) {
			// Server not compatible
			return false;
		}

		return true;
	}

	/**
	 * Check if an automatic Update should be performed
	 */
	public function checkAutoUpdate() {
		$autoUpdate = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_AUTO_UPDATE);
		if (!$autoUpdate) {
			// Auto update turned off
			return;
		}
		if (!$this->coreUpdateData) {
			// No update available
			return;
		}
		if ($this->maniaControl->playerManager->getPlayerCount(false) > 0) {
			// Server not empty
			return;
		}

		$this->performCoreUpdate();
	}

	/**
	 * Perform a Core Update
	 *
	 * @param Player $player
	 * @return bool
	 */
	public function performCoreUpdate(Player $player = null) {
		if (!$this->coreUpdateData) {
			$message = 'Update failed: No update Data available!';
			if ($player) {
				$this->maniaControl->chat->sendError($message, $player);
			}
			$this->maniaControl->log($message);
			return false;
		}

		$this->maniaControl->log("Starting Update to Version v{$this->coreUpdateData->version}...");

		$directories = array('core', 'plugins');
		if (!FileUtil::checkWritePermissions($directories)) {
			$message = 'Update not possible: Incorrect File System Permissions!';
			if ($player) {
				$this->maniaControl->chat->sendError($message, $player);
			}
			$this->maniaControl->log($message);
			return false;
		}

		$performBackup = $this->maniaControl->settingManager->getSettingValue($this, self::SETTING_PERFORM_BACKUPS);
		if ($performBackup && !BackupUtil::performFullBackup()) {
			$message = 'Creating Backup before Update failed!';
			if ($player) {
				$this->maniaControl->chat->sendError($message, $player);
			}
			$this->maniaControl->log($message);
		}

		$self         = $this;
		$updateData   = $this->coreUpdateData;
		$maniaControl = $this->maniaControl;
		$this->maniaControl->fileReader->loadFile($updateData->url, function ($updateFileContent, $error) use (&$self, &$maniaControl, &$updateData, &$player) {
			if (!$updateFileContent || $error) {
				$message = "Update failed: Couldn't load Update zip! {$error}";
				if ($player) {
					$maniaControl->chat->sendError($message, $player);
				}
				$maniaControl->log($message);
				return;
			}

			$tempDir        = FileUtil::getTempFolder();
			$updateFileName = $tempDir . basename($updateData->url);

			$bytes = file_put_contents($updateFileName, $updateFileContent);
			if (!$bytes || $bytes <= 0) {
				$message = "Update failed: Couldn't save Update zip!";
				if ($player) {
					$maniaControl->chat->sendError($message, $player);
				}
				$maniaControl->log($message);
				return;
			}

			$zip    = new \ZipArchive();
			$result = $zip->open($updateFileName);
			if ($result !== true) {
				$message = "Update failed: Couldn't open Update Zip. ({$result})";
				if ($player) {
					$maniaControl->chat->sendError($message, $player);
				}
				$maniaControl->log($message);
				return;
			}

			$zip->extractTo(ManiaControlDir);
			$zip->close();
			unlink($updateFileName);
			FileUtil::removeTempFolder();

			// Set the Nightly Build Date
			$self->setNightlyBuildDate($updateData->releaseDate);

			$message = 'Update finished!';
			if ($player) {
				$maniaControl->chat->sendSuccess($message, $player);
			}
			$maniaControl->log($message);

			$maniaControl->restart();
		});

		return true;
	}

	/**
	 * Set the Build Date of the local Nightly Build Version
	 *
	 * @param string $date
	 * @return bool
	 */
	public function setNightlyBuildDate($date) {
		$nightlyBuildDateFile   = ManiaControlDir . 'core' . DIRECTORY_SEPARATOR . 'nightly_build.txt';
		$success                = (bool)file_put_contents($nightlyBuildDateFile, $date);
		$this->currentBuildDate = $date;
		return $success;
	}

	/**
	 * Handle ManiaControl PlayerJoined callback
	 *
	 * @param Player $player
	 */
	public function handlePlayerJoined(Player $player) {
		if (!$this->coreUpdateData) {
			return;
		}
		// Announce available update
		if (!$this->maniaControl->authenticationManager->checkPermission($player, self::SETTING_PERMISSION_UPDATE)) {
			return;
		}

		if ($this->isNightlyUpdateChannel()) {
			$this->maniaControl->chat->sendSuccess('New Nightly Build (' . $this->coreUpdateData->releaseDate . ') available!', $player->login);
		} else {
			$this->maniaControl->chat->sendInformation('New ManiaControl Version ' . $this->coreUpdateData->version . ' available!', $player->login);
		}
	}

	/**
	 * Handle Player Disconnect Callback
	 *
	 * @param Player $player
	 */
	public function handlePlayerDisconnect(Player $player) {
		$this->checkAutoUpdate();
	}

	/**
	 * Handle //checkupdate command
	 *
	 * @param array  $chatCallback
	 * @param Player $player
	 */
	public function handle_CheckUpdate(array $chatCallback, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkPermission($player, self::SETTING_PERMISSION_UPDATECHECK)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}

		$self         = $this;
		$maniaControl = $this->maniaControl;
		$this->checkCoreUpdateAsync(function (UpdateData $updateData = null) use (&$self, &$maniaControl, &$player) {
			if (!$self->checkUpdateData($updateData)) {
				$maniaControl->chat->sendInformation('No Update available!', $player->login);
				return;
			}

			if (!$self->checkUpdateDataBuildVersion($updateData)) {
				$maniaControl->chat->sendError("Please update Your Server to '{$updateData->minDedicatedBuild}' in order to receive further Updates!", $player->login);
				return;
			}

			$isNightly = $self->isNightlyUpdateChannel();
			if ($isNightly) {
				$buildDate = $self->getNightlyBuildDate();
				if ($buildDate) {
					if ($updateData->isNewerThan($buildDate)) {
						$maniaControl->chat->sendInformation("No new Build available! (Current Build: '{$buildDate}')", $player->login);
						return;
					} else {
						$maniaControl->chat->sendSuccess("New Nightly Build ({$updateData->releaseDate}) available! (Current Build: '{$buildDate}')", $player->login);
					}
				} else {
					$maniaControl->chat->sendSuccess("New Nightly Build ('{$updateData->releaseDate}') available!", $player->login);
				}
			} else {
				$maniaControl->chat->sendSuccess('Update for Version ' . $updateData->version . ' available!', $player->login);
			}

			$self->coreUpdateData = $updateData;
		});
	}

	/**
	 * Handle //coreupdate command
	 *
	 * @param array  $chatCallback
	 * @param Player $player
	 */
	public function handle_CoreUpdate(array $chatCallback, Player $player) {
		if (!$this->maniaControl->authenticationManager->checkPermission($player, self::SETTING_PERMISSION_UPDATE)) {
			$this->maniaControl->authenticationManager->sendNotAllowed($player);
			return;
		}

		$self         = $this;
		$maniaControl = $this->maniaControl;
		$this->checkCoreUpdateAsync(function (UpdateData $updateData = null) use (&$self, &$maniaControl, &$player) {
			if (!$updateData) {
				$maniaControl->chat->sendError('Update is currently not possible!', $player);
				return;
			}
			if (!$self->checkUpdateDataBuildVersion($updateData)) {
				$maniaControl->chat->sendError("The Next ManiaControl Update requires a newer Dedicated Server Version!", $player);
				return;
			}

			$self->coreUpdateData = $updateData;

			$self->performCoreUpdate($player);
		});
	}
}
