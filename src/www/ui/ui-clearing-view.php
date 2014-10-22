<?php
/*
 Copyright (C) 2014, Siemens AG
 Author: Daniele Fognini, Johannes Najjar

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 version 2 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */
use Fossology\Lib\BusinessRules\ClearingDecisionEventProcessor;
use Fossology\Lib\Dao\AgentsDao;
use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\HighlightDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\ClearingDecision;
use Fossology\Lib\Data\LicenseDecision\LicenseDecisionResult;
use Fossology\Lib\Data\DecisionTypes;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Util\ChangeLicenseUtility;
use Fossology\Lib\Util\LicenseOverviewPrinter;
use Fossology\Lib\View\HighlightProcessor;
use Fossology\Lib\View\LicenseProcessor;
use Fossology\Lib\View\LicenseRenderer;
use Monolog\Logger;

define("TITLE_clearingView", _("Change concluded License "));

class ClearingView extends FO_Plugin
{
  /** @var UploadDao */
  private $uploadDao;
  /** @var LicenseDao */
  private $licenseDao;
  /** @var ClearingDao */
  private $clearingDao;
  /** @var AgentsDao */
  private $agentsDao;
  /** @var LicenseProcessor */
  private $licenseProcessor;
  /** @var LicenseOverviewPrinter */
  private $licenseOverviewPrinter;
  /** @var Logger */
  private $logger;
  /** @var HighlightDao */
  private $highlightDao;
  /** @var HighlightProcessor */
  private $highlightProcessor;
  /** @var LicenseRenderer */
  private $licenseRenderer;
  /** @var ClearingDecisionEventProcessor */
  private $clearingDecisionEventProcessor;
  /** @var bool */
  private $invalidParm = false;
  /** @var DecisionTypes */
  private $decisionTypes;

  function __construct()
  {
    $this->Name = "view-license";
    $this->Title = TITLE_clearingView;
    $this->DBaccess = PLUGIN_DB_WRITE;
    $this->Dependency = array("view");
    $this->LoginFlag = 0;
    $this->NoMenu = 0;
    parent::__construct();

    global $container;
    $this->licenseDao = $container->get('dao.license');
    $this->uploadDao = $container->get('dao.upload');
    $this->clearingDao = $container->get('dao.clearing');
    $this->agentsDao = $container->get('dao.agents');
    $this->licenseProcessor = $container->get('view.license_processor');
    $this->logger = $container->get("logger");

    $this->highlightDao = $container->get("dao.highlight");
    $this->highlightProcessor = $container->get("view.highlight_processor");
    $this->licenseRenderer = $container->get("view.license_renderer");

    $this->licenseOverviewPrinter = $container->get('utils.license_overview_printer');
    $this->decisionTypes = $container->get('decision.types');

    $this->clearingDecisionEventProcessor = $container->get('businessrules.clearing_decision_event_processor');
  }


  /**
   * @param ItemTreeBounds $itemTreeBounds
   * @param $licenseId
   * @param $selectedAgentId
   * @param $highlightId
   * @return array
   */
  private function getSelectedHighlighting(ItemTreeBounds $itemTreeBounds, $licenseId, $selectedAgentId, $highlightId)
  {
    $highlightEntries = $this->highlightDao->getHighlightEntries($itemTreeBounds, $licenseId, $selectedAgentId, $highlightId);
    if ($selectedAgentId > 0)
    {
      $this->highlightProcessor->addReferenceTexts($highlightEntries);
    } else
    {
      $this->highlightProcessor->flattenHighlights($highlightEntries, array("K", "K "));
    }
    return $highlightEntries;
  }


  function OutputOpen()
  {
    if ($this->State != PLUGIN_STATE_READY)
    {
      return (0);
    }
    $uploadId = GetParm("upload", PARM_INTEGER);
    if (empty($uploadId))
    {
      return;
    }

    $uploadTreeId = GetParm("item", PARM_INTEGER);
    if (empty($uploadTreeId))
    {
      $parent = $this->uploadDao->getUploadParent($uploadId);
      if (!isset($parent))
      {
        $this->invalidParm = true;
        return;
      }

      $item = $this->uploadDao->getNextItem($uploadId, $parent);
      if ($uploadTreeId === UploadDao::NOT_FOUND)
      {
        $this->invalidParm = true;
        return;
      }
      $uploadTreeId=$item->getId();
      header('Location: ' . Traceback_uri() . '?mod=' . $this->Name . Traceback_parm_keep(array("upload", "show")) . "&item=$uploadTreeId");
    }

    $uploadTreeTableName = GetUploadtreeTableName($uploadId);
    $uploadEntry = $this->uploadDao->getUploadEntry($uploadTreeId, $uploadTreeTableName);
    if (Isdir($uploadEntry['ufile_mode']) || Iscontainer($uploadEntry['ufile_mode']))
    {
      $parent = $this->uploadDao->getUploadParent($uploadId);
      if (!isset($parent))
      {
        $this->invalidParm = true;
        return;
      }

      $item = $this->uploadDao->getNextItem($uploadId, $parent);
      if ($uploadTreeId === UploadDao::NOT_FOUND)
      {
        $this->invalidParm = true;
        return;
      }
      $uploadTreeId=$item->getId();
      header('Location: ' . Traceback_uri() . '?mod=' . $this->Name . Traceback_parm_keep(array("upload", "show")) . "&item=$uploadTreeId");
    }

    return parent::OutputOpen();
  }


  /**
   * @brief extends standard Output to handle empty uploads
   */
  function Output()
  {
    if ($this->invalidParm)
    {
      $this->vars['content'] = 'This upload contains no files!<br><a href="' . Traceback_uri() . '?mod=browse">Go back to browse view</a>';
      return $this->renderTemplate("include/base.html.twig");
    }
    parent::Output();
  }

  /**
   * \brief display the license changing page
   */
  protected function htmlContent()
  {
    $uploadId = GetParm("upload", PARM_INTEGER);
    if (empty($uploadId))
    {
      return;
    }
    $uploadTreeId = GetParm("item", PARM_INTEGER);
    if (empty($uploadTreeId))
    {
      return;
    }

    global $SysConf;
    $userId = $SysConf['auth']['UserId'];

    $lastItem = GetParm("lastItem", PARM_INTEGER);

    if (!empty($lastItem))
    {
      $this->updateLastItem($userId, $lastItem);
    }

    $uploadTreeTableName = $this->uploadDao->getUploadtreeTableName($uploadId);
    $itemTreeBounds = $this->uploadDao->getFileTreeBounds($uploadTreeId, $uploadTreeTableName);

    $this->vars['micromenu'] = Dir2Browse('license', $uploadTreeId, NULL, $showBox = 0, "Clearing", -1, '', '', $uploadTreeTableName);

    global $Plugins;
    /** @var $view ui_view */
    $view = &$Plugins[plugin_find_id("view")];

    $licenseId = GetParm("licenseId", PARM_INTEGER);
    $selectedAgentId = GetParm("agentId", PARM_INTEGER);
    $highlightId = GetParm("highlightId", PARM_INTEGER);

    $this->vars['uri'] = Traceback_uri() . "?mod=" . $this->Name . Traceback_parm_keep(array('upload', 'folder'));
    $this->vars['optionName'] = "skipFile";
    $this->vars['formName'] = "uiClearingForm";
    $this->vars['ajaxAction'] = "setNextPrev";
    $highlights = $this->getSelectedHighlighting($itemTreeBounds, $licenseId, $selectedAgentId, $highlightId);
    $hasHighlights = count($highlights) > 0;

    $permission = GetUploadPerm($uploadId);
    $licenseInformation = "";

    $output = '';

    $isSingleFile = !$itemTreeBounds->containsFiles();
    $hasWritePermission = $permission >= PERM_WRITE;

    $clearingDecWithLicenses = null;
    if ($isSingleFile || $hasWritePermission)
    {
      $clearingDecWithLicenses = $this->clearingDao->getFileClearingsFolder($itemTreeBounds);
    }

    if ($isSingleFile)
    {
      $extractedLicenseBulkMatches = $this->licenseProcessor->extractBulkLicenseMatches($clearingDecWithLicenses);
      $output .= $this->licenseOverviewPrinter->createBulkOverview($extractedLicenseBulkMatches, $itemTreeBounds->getUploadId(), $uploadTreeId, $selectedAgentId, $licenseId, $highlightId, $hasHighlights);

      if ($permission >= PERM_WRITE)
      {
        $this->vars['bulkUri'] = Traceback_uri() . "?mod=popup-license";
        $this->vars['licenseArray'] = $this->licenseDao->getLicenseArray();
      }
      else
      {
        $this->vars['auditDenied'] = true;
      }

    }
    $licenseInformation .= $output;

    $clearingHistory = array();
    $selectedClearingType = false;
    if ($hasWritePermission)
    {
      $clearingHistory = $this->getClearingHistory($clearingDecWithLicenses);
    }
    if(count($clearingHistory)>0)
    {
      $selectedClearingType = $this->decisionTypes->getTypeByName($clearingHistory[0]['type']);
    }

    $ModBack = GetParm("modback", PARM_STRING) ?: "license";
    list($pageMenu, $textView) = $view->getView(NULL, $ModBack, 0, "", $highlights, false, true);

    $this->vars['uploadId'] = $uploadId;
    $this->vars['itemId'] = $uploadTreeId;
    $this->vars['path'] = $output;
    $this->vars['pageMenu'] = $pageMenu;
    $this->vars['textView'] = $textView;
    $this->vars['legendBox'] = $this->licenseOverviewPrinter->legendBox($selectedAgentId > 0 && $licenseId > 0);
    $this->vars['clearingTypes'] = $this->decisionTypes->getMap();
    $this->vars['selectedClearingType'] = $selectedClearingType;
    $this->vars['licenseInformation'] = $licenseInformation;
    $this->vars['clearingHistory'] = $clearingHistory;
  }

  
  /**
   * @param ClearingDecision[] $clearingDecWithLicenses
   * @return array
   */
  private function getClearingHistory($clearingDecWithLicenses)
  {
    $table = array();
    foreach ($clearingDecWithLicenses as $clearingDecision)
    {
      $licenseNames = array();
      foreach ($clearingDecision->getPositiveLicenses() as $lic)
      {
        $licenseShortName = $lic->getShortName();
        $licenseNames[$lic->getShortName()] = $licenseShortName;
      }
      foreach ($clearingDecision->getNegativeLicenses() as $lic)
      {
        $licenseShortName = $lic->getShortName();
        $licenseNames[$lic->getShortName()] = "<span style=\"color:red\">" . $licenseShortName . "</span>";
      }
      ksort($licenseNames, SORT_STRING);
      $row = array(
          'date'=>$clearingDecision->getDateAdded(),
          'username'=>$clearingDecision->getUserName(),
          'scope'=>$clearingDecision->getScope(),
          'type'=>$this->decisionTypes->getTypeName($clearingDecision->getType()),
          'licenses'=>implode(", ", $licenseNames));
      $table[] = $row;
    }
    return $table;
  }
  
  
  public function getTemplateName()
  {
    return "ui-clearing-view.html.twig";
  }

  /*
   * \brief Customize submenus.
   */
  function RegisterMenus()
  {
    $text = _("Set the concluded licenses for this upload");
    menu_insert("Browse-Pfile::Clearing", 0, $this->Name, $text);
    menu_insert("View::Audit", 35, $this->Name . Traceback_parm_keep(array("upload", "item", "show")), $text);
    return 0;
  }

  /**
   * @param $userId
   * @param $lastItem
   * @return array
   */
  protected function updateLastItem($userId, $lastItem)
  {
    $type = GetParm("clearingTypes", PARM_INTEGER);
    $global = GetParm("globalDecision", PARM_STRING) === "on";

    $uploadTreeTableName = $this->uploadDao->getUploadtreeTableName($lastItem);
    $itemBounds = $this->uploadDao->getFileTreeBounds($lastItem,$uploadTreeTableName);

    $this->clearingDecisionEventProcessor->makeDecisionFromLastEvents($itemBounds, $userId, $type, $global);
  }
}

$NewPlugin = new ClearingView;