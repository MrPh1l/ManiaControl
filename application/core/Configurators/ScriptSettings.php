<?php

namespace ManiaControl\Configurators;

use ManiaControl\ManiaControl;
use FML\Controls\Frame;
use FML\Controls\Label;
use FML\Script\Pages;
use FML\Script\Tooltips;
use FML\Controls\Control;
use FML\Controls\Quad;
use FML\Controls\Quads\Quad_Icons64x64_1;
use FML\Controls\Labels\Label_Text;
use FML\Controls\Entry;
use ManiaControl\Players\Player;

/**
 * Class offering a configurator for current script settings
 *
 * @author steeffeen & kremsy
 */
class ScriptSettings implements ConfiguratorMenu {
	/**
	 * Constants
	 */
	const NAME_PREFIX = 'Script.';
	const SETTING_TITLE = 'Menu Title';
	const SETTING_STYLE_SETTING = 'Setting Label Style';
	const SETTING_STYLE_DESCRIPTION = 'Description Label Style';
	
	/**
	 * Private properties
	 */
	private $maniaControl = null;

	/**
	 * Create a new script settings instance
	 *
	 * @param ManiaControl $maniaControl        	
	 */
	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
		
		// Init settings
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_TITLE, 'Script Settings');
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_STYLE_SETTING, Label_Text::STYLE_TextStaticSmall);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_STYLE_DESCRIPTION, Label_Text::STYLE_TextTips);
	}

	/**
	 *
	 * @see \ManiaControl\Configurators\ConfiguratorMenu::getTitle()
	 */
	public function getTitle() {
		return $this->maniaControl->settingManager->getSetting($this, self::SETTING_TITLE);
	}

	/**
	 *
	 * @see \ManiaControl\Configurators\ConfiguratorMenu::getMenu()
	 */
	public function getMenu($width, $height, Pages $pages, Tooltips $tooltips) {
		$frame = new Frame();
		
		$this->maniaControl->client->query('GetModeScriptInfo');
		$scriptInfo = $this->maniaControl->client->getResponse();
		$scriptParams = $scriptInfo['ParamDescs'];
		
		$this->maniaControl->client->query('GetModeScriptSettings');
		$scriptSettings = $this->maniaControl->client->getResponse();
		
		// Config
		$labelStyleSetting = $this->maniaControl->settingManager->getSetting($this, self::SETTING_STYLE_SETTING);
		$labelStyleDescription = $this->maniaControl->settingManager->getSetting($this, self::SETTING_STYLE_DESCRIPTION);
		$pagerSize = 9.;
		$settingHeight = 5.;
		$labelTextSize = 2;
		$pageMaxCount = 15;
		
		// Pagers
		$pagerPrev = new Quad_Icons64x64_1();
		$frame->add($pagerPrev);
		$pagerPrev->setPosition($width * 0.39, $height * -0.44);
		$pagerPrev->setSize($pagerSize, $pagerSize);
		$pagerPrev->setSubStyle(Quad_Icons64x64_1::SUBSTYLE_ArrowPrev);
		
		$pagerNext = new Quad_Icons64x64_1();
		$frame->add($pagerNext);
		$pagerNext->setPosition($width * 0.45, $height * -0.44);
		$pagerNext->setSize($pagerSize, $pagerSize);
		$pagerNext->setSubStyle(Quad_Icons64x64_1::SUBSTYLE_ArrowNext);
		
		$pageCountLabel = new Label();
		$frame->add($pageCountLabel);
		$pageCountLabel->setHAlign(Control::RIGHT);
		$pageCountLabel->setPosition($width * 0.35, $height * -0.44);
		$pageCountLabel->setStyle('TextTitle1');
		$pageCountLabel->setTextSize(2);
		
		// Setting pages
		$pageFrames = array();
		foreach ($scriptParams as $index => $scriptParam) {
			$settingName = $scriptParam['Name'];
			if (!isset($scriptSettings[$settingName])) continue;
			
			if (!isset($pageFrame)) {
				$pageFrame = new Frame();
				$frame->add($pageFrame);
				array_push($pageFrames, $pageFrame);
				$y = $height * 0.43;
			}
			
			$nameLabel = new Label();
			$pageFrame->add($nameLabel);
			$nameLabel->setHAlign(Control::LEFT);
			$nameLabel->setPosition($width * -0.46, $y);
			$nameLabel->setSize($width * 0.4, $settingHeight);
			$nameLabel->setStyle($labelStyleSetting);
			$nameLabel->setTextSize($labelTextSize);
			$nameLabel->setText($settingName);
			
			$decriptionLabel = new Label();
			$pageFrame->add($decriptionLabel);
			$decriptionLabel->setHAlign(Control::LEFT);
			$decriptionLabel->setPosition($width * -0.45, $height * -0.44);
			$decriptionLabel->setSize($width * 0.7, $settingHeight);
			$decriptionLabel->setStyle($labelStyleDescription);
			$decriptionLabel->setTranslate(true);
			$decriptionLabel->setTextPrefix('Desc: ');
			$decriptionLabel->setText($scriptParam['Desc']);
			$tooltips->add($nameLabel, $decriptionLabel);
			
			$entry = new Entry();
			$pageFrame->add($entry);
			$entry->setHAlign(Control::RIGHT);
			$entry->setPosition($width * 0.46, $y);
			$entry->setSize($width * 0.45, $settingHeight);
			$entry->setName(self::NAME_PREFIX . $settingName);
			$settingValue = $scriptSettings[$settingName];
			if ($settingValue === false) {
				$settingValue = 0;
			}
			$entry->setDefault($settingValue);
			
			$y -= $settingHeight;
			if ($index % $pageMaxCount == $pageMaxCount - 1) {
				unset($pageFrame);
			}
		}
		
		$pages->add(array(-1 => $pagerPrev, 1 => $pagerNext), $pageFrames, $pageCountLabel);
		
		return $frame;
	}

	/**
	 *
	 * @see \ManiaControl\Configurators\ConfiguratorMenu::saveConfigData()
	 */
	public function saveConfigData(array $configData, Player $player) {
		$this->maniaControl->client->query('GetModeScriptSettings');
		$scriptSettings = $this->maniaControl->client->getResponse();
// 		var_dump($configData);
// 		var_dump($scriptSettings);
		$prefixLength = strlen(self::NAME_PREFIX);
		foreach ($configData[3] as $dataName => $dataValue) {
			if (substr($dataName, 0, $prefixLength) != self::NAME_PREFIX) continue;
			
			$settingName = substr($dataName, $prefixLength);
		}
	}
}

?>
