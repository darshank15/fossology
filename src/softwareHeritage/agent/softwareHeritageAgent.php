<?php
/*
 Copyright (C) 2019
 Copyright (C) 2020, Siemens AG
 Author: Sandip Kumar Bhuyan<sandipbhuyan@gmail.com>,
         Shaheem Azmal M MD<shaheem.azmal@siemens.com>

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

namespace Fossology\SoftwareHeritage;

use Fossology\Lib\Agent\Agent;
use Fossology\Lib\Dao\AgentDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\SoftwareHeritageDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Db\DbManager;
use \GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;

include_once(__DIR__ . "/version.php");

/**
 * @file
 * @brief Software Heritage agent source
 * @class SoftwareHeritage
 * @brief The software heritage agent
 */
class softwareHeritageAgent extends Agent
{
  /** @var UploadDao $uploadDao
   * UploadDao object
   */
  private $uploadDao;

  /** @var LicenseDao $licenseDao
   * LicenseDao object
   */
  private $licenseDao;

  /**
   * configuraiton for software heritage api
   * @var array $configuration
   */
  private $configuration;

  /**
   * @var DbManager $dbManeger
   * DbManeger object
   */
  private $dbManeger;

  /**
   * @var AgentDao $agentDao
   * AgentDao object
   */
  protected $agentDao;

  /**
   * @var SoftwareHeritageDao $shDao
   * SoftwareHeritageDao object
   */
  private $softwareHeritageDao;

  /**
   * softwareHeritageAgent constructor.
   * @throws \Exception
   */
  function __construct()
  {
    parent::__construct(SOFTWARE_HERITAGE_AGENT_NAME, AGENT_VERSION, AGENT_REV);
    $this->uploadDao = $this->container->get('dao.upload');
    $this->licenseDao = $this->container->get('dao.license');
    $this->dbManeger = $this->container->get('db.manager');
    $this->agentDao = $this->container->get('dao.agent');
    $this->softwareHeritageDao = $this->container->get('dao.softwareHeritage');
    $this->configuration = parse_ini_file(__DIR__ . '/softwareHeritage.conf');
  }

  /**
   * @brief Run software heritage for a package
   * @param int $uploadId
   * @return bool
   * @throws \Fossology\Lib\Exception
   * @see Fossology::Lib::Agent::Agent::processUploadId()
   */
  function processUploadId($uploadId)
  {
    $itemTreeBounds = $this->uploadDao->getParentItemBounds($uploadId);
    $pfileFileDetails = $this->uploadDao->getPFileDataPerFileName($itemTreeBounds);
    $pfileFks = $this->softwareHeritageDao->getSoftwareHeritagePfileFk($uploadId);
    $agentId = $this->agentDao->getCurrentAgentId("softwareHeritage");
    $maxTime = $this->configuration['api']['maxtime'];
    foreach ($pfileFileDetails as $pfileDetail) {
      if (!in_array($pfileDetail['pfile_pk'], array_column($pfileFks, 'pfile_fk'))) {
        $this->processEachPfileForSWH($pfileDetail, $agentId, $maxTime);
      }
      $this->heartbeat(1);
    }
    return true;
  }

  /**
   * @brief process each pfile for software heritage
   * and wait till the reset time
   * @param int $pfileDetail
   * @param int $maxTime
   * @return bool
   */
  function processEachPfileForSWH($pfileDetail, $agentId, $maxTime)
  {
    list ($currentStatus, $currentResult) = $this->getSoftwareHeritageLicense($pfileDetail['sha256']);
    if (SoftwareHeritageDao::SWH_RATELIMIT_EXCEED == $currentStatus) {
      $this->heartbeat(0); //Fake heartbeat to keep the agent alive.
      $timeToReset = $currentResult - time();
      print "INFO :Software Heritage X-RateLimit-Limit reached. Next slot unlocks in ".gmdate("H:i:s", $timeToReset)."\n";
      if ($timeToReset > $maxTime) {
        sleep($maxTime);
      } else {
        sleep($timeToReset);
      }
      $this->processEachPfileForSWH($pfileDetail, $agentId, $maxTime);
    } else {
      $this->insertSoftwareHeritageRecord($pfileDetail['pfile_pk'], $currentResult, $agentId, $currentStatus);
    }

    return true;
  }

  /**
   * @brief Get the license details from software heritage
   * @param String $sha256
   *
   * @return array
   */
  protected function getSoftwareHeritageLicense($sha256)
  {
    global $SysConf;
    $proxy = [];
    $URIToGetLicenses = $this->configuration['api']['url'].$this->configuration['api']['uri'].$sha256.$this->configuration['api']['content'];
    $URIToGetContent = $this->configuration['api']['url'].$this->configuration['api']['uri'].$sha256;

    if (array_key_exists('http_proxy', $SysConf['FOSSOLOGY']) &&
        ! empty($SysConf['FOSSOLOGY']['http_proxy'])) {
      $proxy['http'] = $SysConf['FOSSOLOGY']['http_proxy'];
    }
    if (array_key_exists('https_proxy', $SysConf['FOSSOLOGY']) &&
        ! empty($SysConf['FOSSOLOGY']['https_proxy'])) {
      $proxy['https'] = $SysConf['FOSSOLOGY']['https_proxy'];
    }
    if (array_key_exists('no_proxy', $SysConf['FOSSOLOGY']) &&
        ! empty($SysConf['FOSSOLOGY']['no_proxy'])) {
      $proxy['no'] = explode(',', $SysConf['FOSSOLOGY']['no_proxy']);
    }

    $client = new Client(['http_errors' => false, 'proxy' => $proxy]);
    try {
      $response = $client->get($URIToGetLicenses);
      $statusCode = $response->getStatusCode();
      $cookedResult = array();
      if ($statusCode == SoftwareHeritageDao::SWH_STATUS_OK) {
        $responseContent = json_decode($response->getBody()->getContents(),true);
        $cookedResult = $responseContent["facts"][0]["licenses"];
      } else if ($statusCode == SoftwareHeritageDao::SWH_RATELIMIT_EXCEED) {
        $responseContent = $response->getHeaders();
        $cookedResult = $responseContent["X-RateLimit-Reset"][0];
      } else if ($statusCode == SoftwareHeritageDao::SWH_NOT_FOUND) {
        $response = $client->get($URIToGetContent);
        $responseContent = json_decode($response->getBody(),true);
        if (isset($responseContent["status"])) {
          $statusCode = SoftwareHeritageDao::SWH_STATUS_OK;
        }
      }
      return array($statusCode, $cookedResult);
    } catch (RequestException $e) {
      echo "Sorry, something went wrong. check if the host is accessible!\n";
      echo Psr7\str($e->getRequest());
      if ($e->hasResponse()) {
        echo Psr7\str($e->getResponse());
      }
      $this->scheduler_disconnect(1);
      exit;
    }
  }

  /**
   * @brief Insert the License Details in softwareHeritage table
   * @param int $pfileId
   * @param array $licenses
   * @param int $agentId
   * @return boolean True if finished
   */
  protected function insertSoftwareHeritageRecord($pfileId, $licenses, $agentId, $status)
  {
    $licenseString = !empty($licenses) ? implode(", ", $licenses) : '';
    $this->softwareHeritageDao->setSoftwareHeritageDetails($pfileId,
                                  $licenseString, $status);
    if (!empty($licenses)) {
      foreach ($licenses as $license) {
        $l = $this->licenseDao->getLicenseByShortName($license);
        if ($l != NULL) {
          $this->dbManeger->insertTableRow('license_file',['agent_fk' => $agentId,
                                             'pfile_fk' => $pfileId,'rf_fk'=> $l->getId()]);
        }
      }
      return true;
    }
  }
}
