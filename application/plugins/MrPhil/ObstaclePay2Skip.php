<?php

namespace MrPhil;

use ManiaControl\ManiaControl;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Commands\CommandListener;
use ManiaControl\Bills\BillManager;
use ManiaControl\Players\Player;
use ManiaControl\Plugins\Plugin;

/**
 * ManiaControl Obstacle pay2skip Plugin
 *
 * @author MrPhil
 */
class ObstaclePay2Skip implements CallbackListener, CommandListener, Plugin {
	/**
	 * Constants
	 */
	const ID = 100;
	const VERSION = 0.1;
	const NAME				= 'Obstacle Pay2Skip';
	const AUTHOR			= 'Mr.Phil';

	const CB_JUMPTO = 'Obstacle.JumpTo';
	const CB_SKIP = 'Obstacle.Skip';
	const PLANETS2SKIP = 'Planets fee to skip a checkpoint';

	/**
	 * Private Properties
	 */
	/**
	 * @var maniaControl $maniaControl
	 */
	private $maniaControl = null;

	/**
	 * Prepares the Plugin
	 *
	 * @param ManiaControl $maniaControl
	 * @return mixed
	 */
	public static function prepare(ManiaControl $maniaControl) {
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::load()
	 */
	public function load(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;

		// Init settings
		$this->maniaControl->settingManager->initSetting($this, self::PLANETS2SKIP, 100);

		// Register for commands
		$this->maniaControl->commandManager->registerCommandListener('pay2skip', $this, 'command_pay2skip');

		return true;
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::unload()
	 */
	public function unload() {
		$this->maniaControl->commandManager->unregisterCommandListener($this);
		unset($this->maniaControl);
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::getId()
	 */
	public static function getId() {
		return self::ID;
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::getName()
	 */
	public static function getName() {
		return self::NAME;
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::getVersion()
	 */
	public static function getVersion() {
		return self::VERSION;
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::getAuthor()
	 */
	public static function getAuthor() {
		return self::AUTHOR;
	}

	/**
	 *
	 * @see \ManiaControl\Plugins\Plugin::getDescription()
	 */
	public static function getDescription() {
		return 'Allow the players to pay an amount of planet to skip their current checkpoint. This plugin requires the obstacle plugin to work.';
	}

	/**
	 * Handle JumpTo command
	 *
	 * @param array $chatCallback
	 * @param Player $player
	 * @return bool
	 */
	public function command_pay2skip(array $chatCallback, Player $player) {
		$this->handleDonation($player, intval($this->maniaControl->settingManager->getSettingValue($this, self::PLANETS2SKIP)));
	}

	/**
	 * Handle a Player Donation
	 *
	 * @param Player $player
	 * @param int    $amount
	 */
	private function handleDonation(Player $player, $amount) {
		$message    = 'Pay ' . $amount . " Planets to skip current checkpoint?\n" . '$<$f00WARNING: This will cause your time to be invalid.$>';

		//Send and Handle the Bill
		$self = $this;
		$this->maniaControl->billManager->sendBill(function ($data, $status) use (&$self, &$player, $amount) {
			switch ($status) {
				case BillManager::DONATED_TO_SERVER:
					try {
						// TODO: Change callback CB_JUMPTO to CB_SKIP when available
						$param = $player->login . ";" . 1 . ";";
						$this->maniaControl->client->triggerModeScriptEvent(self::CB_JUMPTO, $param);
					}
					catch (Exception $e) {
						if ($e->getMessage() == 'Not in script mode.') {
							trigger_error("Couldn't send skip callback for '{$player->login}'. " . $e->getMessage());
							return;
						}
						throw $e;
					}
					break;
				case BillManager::DONATED_TO_RECEIVER:
					break;
				case BillManager::PLAYER_REFUSED_DONATION:
					$message = 'Transaction cancelled.';
					$self->maniaControl->chat->sendError($message, $player->login);
					break;
				case BillManager::ERROR_WHILE_TRANSACTION:
					$message = $data;
					$self->maniaControl->chat->sendError($message, $player->login);
					break;
			}
		}, $player, $amount, $message);
	}
}
