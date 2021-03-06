<?php

namespace ManiaControl\Plugins;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Commands\CommandListener;
use ManiaControl\ManiaControl;
use ManiaControl\Manialinks\ManialinkPageAnswerListener;

/**
 * Class managing Plugins
 *
 * @author    ManiaControl Team <mail@maniacontrol.com>
 * @copyright 2014 ManiaControl Team
 * @license   http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class PluginManager {
	/*
	 * Constants
	 */
	const TABLE_PLUGINS      = 'mc_plugins';
	const CB_PLUGIN_LOADED   = 'PluginManager.PluginLoaded';
	const CB_PLUGIN_UNLOADED = 'PluginManager.PluginUnloaded';

	/*
	 * Private Properties
	 */
	private $maniaControl = null;
	private $pluginMenu = null;
	private $pluginInstallMenu = null;
	/** @var Plugin[] $activePlugins */
	private $activePlugins = array();
	/** @var string[] $pluginClasses */
	private $pluginClasses = array();

	/**
	 * Construct plugin manager
	 *
	 * @param \ManiaControl\ManiaControl $maniaControl
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		$this->initTables();

		$this->pluginMenu = new PluginMenu($maniaControl);
		$this->maniaControl->configurator->addMenu($this->pluginMenu);

		$this->pluginInstallMenu = new PluginInstallMenu($maniaControl);
		$this->maniaControl->configurator->addMenu($this->pluginInstallMenu);
	}

	/**
	 * Initialize necessary database tables
	 *
	 * @return bool
	 */
	private function initTables() {
		$mysqli            = $this->maniaControl->database->mysqli;
		$pluginsTableQuery = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_PLUGINS . "` (
				`index` int(11) NOT NULL AUTO_INCREMENT,
				`className` varchar(100) NOT NULL,
				`active` tinyint(1) NOT NULL DEFAULT '0',
				`changed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`index`),
				UNIQUE KEY `className` (`className`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='ManiaControl plugin status' AUTO_INCREMENT=1;";
		$tableStatement    = $mysqli->prepare($pluginsTableQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error, E_USER_ERROR);
			return false;
		}
		$tableStatement->execute();
		if ($tableStatement->error) {
			trigger_error($tableStatement->error, E_USER_ERROR);
			return false;
		}
		$tableStatement->close();
		return true;
	}

	/**
	 * Get the Plugin Id if the given Class is a Plugin
	 *
	 * @param string $pluginClass
	 * @return int
	 */
	public static function getPluginId($pluginClass) {
		if (self::isPluginClass($pluginClass)) {
			/** @var Plugin $pluginClass */
			return $pluginClass::getId();
		}
		return null;
	}

	/**
	 * Check if the given class implements the plugin interface
	 *
	 * @param string $pluginClass
	 * @return bool
	 */
	public static function isPluginClass($pluginClass) {
		$pluginClass = self::getClass($pluginClass);
		if (!class_exists($pluginClass, false)) {
			return false;
		}
		$interfaces = class_implements($pluginClass, false);
		if (!$interfaces) {
			return false;
		}
		if (!in_array(Plugin::PLUGIN_INTERFACE, $interfaces)) {
			return false;
		}
		return true;
	}

	/**
	 * Get the Class of the Object
	 *
	 * @param mixed $object
	 * @return string
	 */
	private static function getClass($object) {
		if (is_object($object)) {
			return get_class($object);
		}
		return (string)$object;
	}

	/**
	 * Deactivate the Plugin with the given Class
	 *
	 * @param string $pluginClass
	 * @return bool
	 */
	public function deactivatePlugin($pluginClass) {
		$pluginClass = $this->getPluginClass($pluginClass);
		if (!$pluginClass) {
			return false;
		}
		if (!$this->isPluginActive($pluginClass)) {
			return false;
		}

		/** @var Plugin $plugin */
		$plugin = $this->activePlugins[$pluginClass];
		unset($this->activePlugins[$pluginClass]);

		$plugin->unload();

		if ($plugin instanceof CallbackListener) {
			$this->maniaControl->callbackManager->unregisterCallbackListener($plugin);
			$this->maniaControl->callbackManager->unregisterScriptCallbackListener($plugin);
		}
		if ($plugin instanceof CommandListener) {
			$this->maniaControl->commandManager->unregisterCommandListener($plugin);
		}
		if ($plugin instanceof ManialinkPageAnswerListener) {
			$this->maniaControl->manialinkManager->unregisterManialinkPageAnswerListener($plugin);
		}
		if ($plugin instanceof TimerListener) {
			$this->maniaControl->timerManager->unregisterTimerListenings($plugin);
		}

		$this->savePluginStatus($pluginClass, false);

		$this->maniaControl->callbackManager->triggerCallback(self::CB_PLUGIN_UNLOADED, $pluginClass, $plugin);

		return true;
	}

	/**
	 * Get the Class of the Plugin
	 *
	 * @param mixed $pluginClass
	 * @return string
	 */
	public static function getPluginClass($pluginClass) {
		$pluginClass = self::getClass($pluginClass);
		if (!self::isPluginClass($pluginClass)) {
			return false;
		}
		return $pluginClass;
	}

	/**
	 * Check if the Plugin is currently running
	 *
	 * @param string $pluginClass
	 * @return bool
	 */
	public function isPluginActive($pluginClass) {
		$pluginClass = $this->getPluginClass($pluginClass);
		return isset($this->activePlugins[$pluginClass]);
	}

	/**
	 * Save Plugin Status in Database
	 *
	 * @param string $className
	 * @param bool   $active
	 * @return bool
	 */
	private function savePluginStatus($className, $active) {
		$mysqli            = $this->maniaControl->database->mysqli;
		$pluginStatusQuery = "INSERT INTO `" . self::TABLE_PLUGINS . "` (
				`className`,
				`active`
				) VALUES (
				?, ?
				) ON DUPLICATE KEY UPDATE
				`active` = VALUES(`active`);";
		$pluginStatement   = $mysqli->prepare($pluginStatusQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		$activeInt = ($active ? 1 : 0);
		$pluginStatement->bind_param('si', $className, $activeInt);
		$pluginStatement->execute();
		if ($pluginStatement->error) {
			trigger_error($pluginStatement->error);
			$pluginStatement->close();
			return false;
		}
		$pluginStatement->close();
		return true;
	}

	/**
	 * Load complete Plugins Directory and start all configured Plugins
	 *
	 * @return string[]
	 */
	public function loadPlugins() {
		$pluginsDirectory = ManiaControlDir . 'plugins' . DIRECTORY_SEPARATOR;

		$classesBefore = get_declared_classes();
		$this->loadPluginFiles($pluginsDirectory);
		$classesAfter = get_declared_classes();

		$newPluginClasses = array();

		$newClasses = array_diff($classesAfter, $classesBefore);
		foreach ($newClasses as $className) {
			if (!$this->isPluginClass($className)) {
				continue;
			}

			if (!$this->addPluginClass($className)) {
				continue;
			}
			array_push($newPluginClasses, $className);

			/** @var Plugin $className */
			$className::prepare($this->maniaControl);

			if ($this->getSavedPluginStatus($className)) {
				$this->activatePlugin($className);
			}
		}

		return $newPluginClasses;
	}

	/**
	 * Load all Plugin Files from the Directory
	 *
	 * @param string $directory
	 */
	public function loadPluginFiles($directory = '') {
		$pluginFiles = scandir($directory);
		foreach ($pluginFiles as $pluginFile) {
			if (stripos($pluginFile, '.') === 0) {
				continue;
			}

			$filePath = $directory . $pluginFile;
			if (is_file($filePath)) {
				if (!stripos($pluginFile, '.php')) {
					continue;
				}
				$success = include_once $filePath;
				if (!$success) {
					trigger_error("Error loading File '{$filePath}'!");
				}
				continue;
			}

			$dirPath = $directory . $pluginFile;
			if (is_dir($dirPath)) {
				$this->loadPluginFiles($dirPath . DIRECTORY_SEPARATOR);
				continue;
			}
		}
	}

	/**
	 * Add the class to array of loaded plugin classes
	 *
	 * @param string $pluginClass
	 * @return bool
	 */
	public function addPluginClass($pluginClass) {
		$pluginClass = $this->getPluginClass($pluginClass);
		if (in_array($pluginClass, $this->pluginClasses)) {
			return false;
		}
		if (!$this->isPluginClass($pluginClass)) {
			return false;
		}
		array_push($this->pluginClasses, $pluginClass);
		sort($this->pluginClasses);
		return true;
	}

	/**
	 * Get plugin status from database
	 *
	 * @param string $className
	 * @return bool
	 */
	public function getSavedPluginStatus($className) {
		$mysqli            = $this->maniaControl->database->mysqli;
		$pluginStatusQuery = "SELECT `active` FROM `" . self::TABLE_PLUGINS . "`
				WHERE `className` = ?;";
		$pluginStatement   = $mysqli->prepare($pluginStatusQuery);
		if ($mysqli->error) {
			trigger_error($mysqli->error);
			return false;
		}
		$pluginStatement->bind_param('s', $className);
		$pluginStatement->execute();
		if ($pluginStatement->error) {
			trigger_error($pluginStatement->error);
			$pluginStatement->close();
			return false;
		}
		$pluginStatement->store_result();
		if ($pluginStatement->num_rows <= 0) {
			$pluginStatement->free_result();
			$pluginStatement->close();
			$this->savePluginStatus($className, false);
			return false;
		}
		$pluginStatement->bind_result($activeInt);
		$pluginStatement->fetch();
		$active = ($activeInt === 1);
		$pluginStatement->free_result();
		$pluginStatement->close();
		return $active;
	}

	/**
	 * Activate and start the plugin with the given name
	 *
	 * @param string $pluginClass
	 * @param string $adminLogin
	 * @return bool
	 */
	public function activatePlugin($pluginClass, $adminLogin = null) {
		if (!$this->isPluginClass($pluginClass)) {
			return false;
		}
		if ($this->isPluginActive($pluginClass)) {
			return false;
		}
		/** @var Plugin $plugin */
		$plugin = new $pluginClass();

		try {
			$plugin->load($this->maniaControl);
		} catch (\Exception $e) {
			$message = "Error during Plugin Activation of '{$pluginClass}': '{$e->getMessage()}'";
			$this->maniaControl->chat->sendError($message, $adminLogin);
			$this->maniaControl->log($message);
			$this->savePluginStatus($pluginClass, false);
			return false;
		}

		$this->activePlugins[$pluginClass] = $plugin;
		$this->savePluginStatus($pluginClass, true);

		$this->maniaControl->callbackManager->triggerCallback(self::CB_PLUGIN_LOADED, $pluginClass, $plugin);

		return true;
	}

	/**
	 * Returns a Plugin if it is activated
	 *
	 * @param string $pluginClass
	 * @return Plugin
	 */
	public function getPlugin($pluginClass) {
		if ($this->isPluginActive($pluginClass)) {
			return $this->activePlugins[$pluginClass];
		}
		return null;
	}

	/**
	 * Get all declared plugin class names
	 *
	 * @return string[]
	 */
	public function getPluginClasses() {
		return $this->pluginClasses;
	}

	/**
	 * Get the Ids of all active Plugins
	 *
	 * @return string[]
	 */
	public function getActivePluginsIds() {
		$pluginsIds = array();
		foreach ($this->getActivePlugins() as $plugin) {
			$pluginId = $plugin::getId();
			if (is_numeric($pluginId)) {
				array_push($pluginsIds, $pluginId);
			}
		}
		return $pluginsIds;
	}

	/**
	 * Get all active Plugins
	 *
	 * @return Plugin[]
	 */
	public function getActivePlugins() {
		return $this->activePlugins;
	}

	/**
	 * Fetch the Plugins List from the ManiaControl Website
	 *
	 * @param callable $function
	 */
	public function fetchPluginList(callable $function) {
		$url = ManiaControl::URL_WEBSERVICE . 'plugins';

		$this->maniaControl->fileReader->loadFile($url, function ($dataJson, $error) use (&$function) {
			$data = json_decode($dataJson);
			call_user_func($function, $data, $error);
		});
	}
}
