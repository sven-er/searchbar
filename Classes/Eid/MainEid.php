<?php
namespace SvenJuergens\Searchbar\Eid;

/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Frontend\Utility\EidUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Html\HtmlParser;

class MainEid {

	const TYPE_NORMAL     = 0;
	const TYPE_TYPOSCRIPT = 1;
	const TYPE_FUNCTIONS  = 2;

	public $q;
	public $table = 'tx_searchbar_items';
	public $enableFields;
	public $extensionConfiguration;

	public function __construct(){
		$this->init();
	}

	public function init() {

		EidUtility::initTCA();

		$this->extensionConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['searchbar']);
		$this->q = htmlspecialchars(GeneralUtility::_GET('q'));
		if (empty($this->q)) {
			$value = GeneralUtility::_GET('tx_searchbarfrontend_pi1');
			if (is_array($value)) {
				$this->q = htmlspecialchars($value['q']);
			}
		}

		$this->q = GeneralUtility::trimExplode(' ', $this->q, TRUE);

		$this->enableFields = BackendUtility::BEenableFields( $this->table ) . BackendUtility::deleteClause( $this->table );

		if (isset($this->q[0]) && strtolower($this->q[0]) == 'help') {
			$this->showHelp();
			exit;
		}

		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['searchbar']['eID_afterInit'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['searchbar']['eID_afterInit'] as $userFunc) {
				$params = array(
					'pObj' => &$this
				);
				GeneralUtility::callUserFunction($userFunc, $params, $this);
			}
		}

	}


	public function main() {

		// get record
		$row = $this->getRecord($this->q[0]);
		if(empty($row) && $this->extensionConfiguration['useDefaultHotKey'] == 1 ){
			$temp = array( htmlspecialchars( $this->extensionConfiguration['defaultHotKey'] ) );
			$this->q = array_merge( $temp, $this->q );
			$row = $this->getRecord($this->q[0]);
		}

		if (empty($row)) {
			$this->showHelp();
		}

		$this->getRedirect($row['0']);

	}

	public function getRedirect($row) {

		unset($this->q['0']);
		$urlPart = '';

		if ($row['itemtype'] == self::TYPE_TYPOSCRIPT) {
			$urlPart = $this->getTypoScriptCode($row, $this->q);
		} elseif ($row['itemtype'] == self::TYPE_NORMAL) {
			$urlPart = implode(
				$row['glue'],
				$this->q
			);
		}
		if (strpos($row['searchurl'], '###SEARCHWORD##') !== FALSE) {
			$url = str_replace(
				'###SEARCHWORD###',
				$urlPart,
				$row['searchurl']
			);
		} else {
			$url = $row['searchurl'] . $urlPart;
		}

		if ($row['itemtype'] == self::TYPE_FUNCTIONS) {

			//new Option for Using Namespaced Classes
			//and for backward compatibility
			// so if "namespaceOfClass" exisit, it should be NameSpaced
			$classConfig = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['searchbar']['additionalFunctions'][ $row['additionalfunctions'] ];

			if(isset($classConfig['namespaceOfClass']) && !empty($classConfig['namespaceOfClass'])){
				if(class_exists($classConfig['namespaceOfClass'])){
					$userfile = GeneralUtility::makeInstance( $classConfig['namespaceOfClass'] );
					$url = $userfile->execute($row, $this->q);
				}
			}else{
				$file = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['searchbar']['additionalFunctions'][ $row['additionalfunctions'] ]['filePath'];
				if (is_file($file) && GeneralUtility::validPathStr($file)) {
					require_once $file;
					$userfile = GeneralUtility::makeInstance( $row['additionalfunctions'] );
					$url = $userfile->execute($row, $this->q);
				}
			}
		}
		HttpUtility::redirect( $url );
	}

	public function getTypoScriptCode(&$row) {
		$typoScriptCode = str_replace('###INPUT###', implode($row['glue'], $this->q), $row['typoscript']);

		$TSparserObject = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\TypoScript\\Parser\\TypoScriptParser');
		$TSparserObject->parse($typoScriptCode);

		$cObj = GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\ContentObject\\ContentObjectRenderer');
		$cObj->start(array(), '');

		$tsfeClassName = GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Controller\\TypoScriptFrontendController');
		$GLOBALS['TSFE'] = new $tsfeClassName( $GLOBALS['TYPO3_CONF_VARS'], 0, '');
		return $cObj->cObjGet($TSparserObject->setup);
	}

	public function getRecord($hotkey) {
		$arrRow = array();
		$arrRow = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
			'title, hotkey, glue, searchurl, typoscript, itemtype, additionalfunctions',
			$this->table,
			'hotkey=' . $GLOBALS['TYPO3_DB']->fullQuoteStr(htmlspecialchars($hotkey), $this->table) .
				$this->enableFields,
			'',
			'',
			'1'
		);
		return $arrRow;
	}

	public function showHelp() {

		$arrItems = array();
		if ($this->extensionConfiguration['showHelp'] == 1) {
			$arrItems = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
				'*',
				$this->table,
				'1=1' . $this->enableFields,
				'',
				''
			);
			if (!empty ($arrItems)) {
				echo $this->buildingList($arrItems);
			} else {
				echo 'No Entries';
			}
			exit;
		} else {
			echo 'access forbidden';
			exit;
		}
	}

	public function buildingList($arrItems) {

		$template = '';
		$templateCode = '';

		$templateCode = $this->getHtmlTemplate($this->extensionConfiguration['helpTemplateFile']);

		if (empty($templateCode)) {
			return 'Template not found, please check the Extension settings in ExtensionManager';
		}

		$templateSubpart = HtmlParser::getSubpart($templateCode, '###ROW###');

		$alt = 0;
		$entries = array();

		foreach ($arrItems as $key => $item) {
			$markerArray = array(
				'###CLASS###' => ($alt % 2) ? 'even' : 'odd',
				'###TITLE###' => htmlspecialchars($item['title']),
				'###HOTKEY###' => htmlspecialchars($item['hotkey']),
			);
			$entries[] = HtmlParser::substituteMarkerArray($templateSubpart, $markerArray);
			$alt++;
		}

		$template = HtmlParser::getSubpart($templateCode, '###HELPLIST###');
		return HtmlParser::substituteSubpart($template, '###ROW###', implode('', $entries));

	}

	public function getHtmlTemplate($filename) {
		$filename = GeneralUtility::getFileAbsFileName($filename);
		return GeneralUtility::getURL($filename);
	}
}

// Make instance:
$eid = GeneralUtility::makeInstance('SvenJuergens\\Searchbar\\Eid\\MainEid');
$eid->main();