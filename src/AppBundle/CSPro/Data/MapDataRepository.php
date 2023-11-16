<?php

namespace AppBundle\CSPro\Data;

use AppBundle\Service\PdoHelper;
use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Schema;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DBALException;
use AppBundle\CSPro\Data\DataSettings;
use AppBundle\CSPro\DBConfigSettings;
use AppBundle\CSPro\DictionaryHelper;

class MapDataRepository {

    private $logger;
    private $pdo;

    public function __construct(PdoHelper $pdo, LoggerInterface $logger) {
        $this->logger = $logger;
        $this->pdo = $pdo;
    }

    public function getDictionaryHelper() {
        $dbConfigSettings = new DBConfigSettings($this->pdo, $this->logger);
        $serverDeviceId = $dbConfigSettings->getServerDeviceId(); //server name
        $dictionaryHelper = new DictionaryHelper($this->pdo, $this->logger, $serverDeviceId);
        return $dictionaryHelper;
    }

    public function getDBALConnection($dataSetting) {
        $conn = null;
        if (isset($dataSetting)) {
            $connectionParams = array(
                'dbname' => $dataSetting['targetSchemaName'],
                'user' => $dataSetting['dbUserName'],
                'password' => $dataSetting['dbPassword'],
                'host' => $dataSetting['targetHostName'],
                'driver' => 'pdo_mysql'
            );
            $config = new Configuration();
            $conn = DriverManager::getConnection($connectionParams, $config);
        }
        return $conn;
    }

    public function getCaseMarkerItemList($dictionaryName) {
        $itemList = array();
        try {
            $dataSettings = new DataSettings($this->pdo, $this->logger);
            $dataSetting = $dataSettings->getDataSetting($dictionaryName, false);

            $jsonMapInfo = json_decode($dataSetting['mapInfo'], true);
            $locationItems = $jsonMapInfo['gps'];
            $itemList[] = array_map('strtoupper', array_keys($locationItems));
            $itemList = array_merge($itemList, $jsonMapInfo['metadata']);
        } catch (\Exception $e) {
            $this->logger->error('Failed getting map data points: ' . $dictionaryName, array("context" => (string) $e));
            throw $e;
        }
        return $itemList;
    }

    public function getIdListForLevel($dictionaryName, $ids) {
        $curLevel = isset($ids)? count($ids) : 0;
        $idList = array();
        $dictionaryHelper = $this->getDictionaryHelper();
        try {
            $areaNamesTableExists = $dictionaryHelper->getAreaNamesColumnCount() > 0;
            $fromAreaNames = false;
            if ($areaNamesTableExists === true) {
                $level1Labels = "SHOW COLUMNS FROM `cspro_area_names` LIKE 'level%'";
                $firstLevel = $this->pdo->fetchAll($level1Labels);
                //$xx = $firstLevel[0]["Field"];
                
                //Only if there are enough levels in the area names table
                if ($curLevel < count($firstLevel)) {
                    $selCondition = '';
                    $bind = array();
                    for( $i = 0; $i < count($firstLevel); $i++ ) {
                        $ip1 = $i + 1;
                        $colVal = " = 'X'";
                        if ($i < $curLevel) {
                            $colVal = " = :level$ip1";
                            $bind["level$ip1"] = $ids[$i];
                        } else if ($i === $curLevel) {
                            $colVal = " <> 'X'";
                        }
                        $idItemName = "`level$ip1`";
                        $and = $i > 0 ? ' AND' : ' WHERE';

                        $selCondition .= "$and $idItemName $colVal";
                    }

                    $curLevPlusOne = $curLevel + 1;
                    $levelLabels = "SELECT `level$curLevPlusOne` as id, `label` as label FROM `cspro_area_names` $selCondition ORDER BY `label`";
                    $idList["firstLevel"] = $this->pdo->fetchAll($levelLabels, $bind);
                    
                    $fromAreaNames = (count($idList["firstLevel"]) > 0);
                }
            } 
            
            //If no area names for this level then read ID values
            if (!$fromAreaNames) {
                $dataSettings = new DataSettings($this->pdo, $this->logger);
                $dataSetting = $dataSettings->getDataSetting($dictionaryName, false);
                $conn = $this->getDBALConnection($dataSetting);
                $isConnected = isset($conn) && $conn->connect();
                if ($isConnected === true) {
                    $dictionary = $dictionaryHelper->loadDictionary($dictionaryName);
                    $level = $dictionary->getLevels()[0];
                    
                    $idItems = $level->getIdItems();
                    $selCondition = '';
                    $bind = array();
                    for( $i = 0; $i < $curLevel; $i++ ) {
                        $idItemName = strtolower($idItems[$i]->getName());
                        $and = $i > 0 ? ' AND' : ' WHERE';
                        $selCondition = "$selCondition$and `$idItemName` =  :idItemName$i";
                        $bind["idItemName$i"] = $ids[$i];
                    }
                    $curIdItemName = strtolower($idItems[$curLevel]->getName());
                    $curLevelSelect = "SELECT DISTINCT  `$curIdItemName` as id, `$curIdItemName` as label FROM `level-1` $selCondition ORDER BY `id`";
                    $idList["firstLevel"] = $conn->fetchAll($curLevelSelect, $bind);
                } else {
                    $this->logger->error('Failed getting ID filter list for dictionary: ' . $dictionaryName, array("context" => (string) $e));
                    throw $e;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting ID filter list for dictionary: ' . $dictionaryName, array("context" => (string) $e));
            throw $e;
        }
        return $idList;
    }
    
    public function getCaseGuids($dictionaryName, $ids) {
        $caseGuids = array();
        $curLevel = isset($ids)? count($ids) : 0;
        $dictionaryHelper = $this->getDictionaryHelper();
        try {
            $dataSettings = new DataSettings($this->pdo, $this->logger);
            $dataSetting = $dataSettings->getDataSetting($dictionaryName, false);
            $conn = $this->getDBALConnection($dataSetting);
            $isConnected = isset($conn) && $conn->connect();
            if ($isConnected === true) {
                $dictionary = $dictionaryHelper->loadDictionary($dictionaryName);
                $level = $dictionary->getLevels()[0];

                $idItems = $level->getIdItems();
                $selCondition = '';
                $bind = array();
                for( $i = 0; $i < $curLevel; $i++ ) {
                    $idItemName = strtolower($idItems[$i]->getName());
                    $and = $i > 0 ? ' AND' : ' WHERE';
                    $selCondition = "$selCondition$and `$idItemName` =  :idItemName$i";
                    $bind["idItemName$i"] = $ids[$i];
                }
                $caseSelect = "SELECT `case-id` FROM `level-1` $selCondition";
                $caseGuids = $conn->fetchAll($caseSelect, $bind);
            } else {
                $this->logger->error('Failed getting ID filter list for dictionary: ' . $dictionaryName, array("context" => (string) $e));
                throw $e;
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting ID filter list for dictionary: ' . $dictionaryName, array("context" => (string) $e));
            throw $e;
        }
        return $caseGuids;
    }
    
    public function getIdList($dictionaryName, $ids) {
        $idList = array();
        $dictionaryHelper = $this->getDictionaryHelper();
        try {
            $areaNamesTableExists = $dictionaryHelper->getAreaNamesColumnCount() > 0;
            if ($areaNamesTableExists === true) {
                if (!isset($ids)) {
                    $level1Labels = "SELECT `level1` as id, `label` as label from `cspro_area_names` WHERE `level2` = 'X' ORDER BY `label`";
                    $firstLevel = $this->pdo->fetchAll($level1Labels);
                    $this->logger->debug(print_r($firstLevel, true));
                    $idList["firstLevel"] = $firstLevel;
                } elseif (count($ids) > 0) {
                    $level1Labels = "SELECT `level1` as `id`, `label` as `label` from `cspro_area_names` WHERE `level2` = 'X' ORDER BY `label`";
                    $firstLevel = $this->pdo->fetchAll($level1Labels);

                    $secondLevel = "SELECT `level2` as id, `label` as label from `cspro_area_names` WHERE `level1` = :level1 AND `level2` <> 'X'";
                    $bind = array("level1" => $ids[0]);
                    $secondLevel = $this->pdo->fetchAll($secondLevel, $bind);

                    $idList["firstLevel"] = $firstLevel;
                    $idList["secondLevel"] = $secondLevel;
                }
            } else {
                //get distict ids from first and second id items
                $dataSettings = new DataSettings($this->pdo, $this->logger);
                $dataSetting = $dataSettings->getDataSetting($dictionaryName, false);
                $conn = $this->getDBALConnection($dataSetting);
                $isConnected = isset($conn) && $conn->connect();
                if ($isConnected === true) {
                    $dictionary = $dictionaryHelper->loadDictionary($dictionaryName);

                    $level = $dictionary->getLevels()[0];
                    //get the first two id items for filtering
                    $idItem1 = count($level->getIdItems()) >= 1 ? $level->getIdItems()[0] : null;
                    $idItem2 = count($level->getIdItems()) > 1 ? $level->getIdItems()[1] : null;
                    if (!isset($ids) && isset($idItem1)) {
                        $idItemName = "`" . strtolower($idItem1->getName()) . "`";
                        $level1Labels = "SELECT DISTINCT  $idItemName as id, $idItemName as label from `level-1` ORDER BY `id`";
                        $firstLevel = $conn->fetchAll($level1Labels);
                        $this->logger->debug(print_r($firstLevel, true));
                        $idList["firstLevel"] = $firstLevel;
                    } elseif (count($ids) > 0 && isset($idItem2)) {
                        $idItemName1 = "`" . strtolower($idItem1->getName()) . "`";
                        $idItemName2 = "`" . strtolower($idItem2->getName()) . "`";
                        $level1Labels = "SELECT DISTINCT  $idItemName1 as id, $idItemName1 as label from `level-1`  ORDER BY `id`";
                        $firstLevel = $conn->fetchAll($level1Labels);

                        $secondLevel = "SELECT DISTINCT  $idItemName2 as id, $idItemName2 as label from `level-1` WHERE $idItemName1 =  :idItemName1  ORDER BY `id`";
                        $bind = array("idItemName1" => $ids[0]);
                        $secondLevel = $conn->fetchAll($secondLevel, $bind);

                        $idList["firstLevel"] = $firstLevel;
                        $idList["secondLevel"] = $secondLevel;
                    }
                } else {
                    $this->logger->error('Failed getting ID filter list for dictionary: ' . $dictionaryName, array("context" => (string) $e));
                    throw $e;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting ID filter list for dictionary: ' . $dictionaryName, array("context" => (string) $e));
            throw $e;
        }
        return $idList;
    }

    public function getMapDataPoints($dictionaryName, $ids, $maxMapPoints) {
        $mapPoints = null;
        try {
            $dataSettings = new DataSettings($this->pdo, $this->logger);
            $dataSetting = $dataSettings->getDataSetting($dictionaryName, false);
            $this->logger->debug('printing dataSetting' . print_r($dataSetting, true));
            $bind = array();
            $strWhereIds = "";
            $stm = $this->buildMapPointQueryString($dictionaryName, $dataSetting, $ids, $strWhereIds, $bind);

            $this->logger->debug($stm);

            if (empty($stm)) {
                $strMsg = 'Failed  building Map Point Query String';
                throw new \Exception($strMsg);
            }
            $conn = $this->getDBALConnection($dataSetting);
            $isConnected = isset($conn) && $conn->connect();
            if ($isConnected === true) {
                $totalCasesStm = "SELECT COUNT(*) FROM `level-1` ";
                $query = $conn->prepare($totalCasesStm);
                $query->execute($bind);
                $totalCases = $query->fetchColumn();
                if ($totalCases > $maxMapPoints) {
                    return array('totalMapPoints' => $totalCases);
                }

                $stmt = $conn->prepare($stm);
                $stmt->execute($bind);
                $mapPoints = $stmt->fetchAll();
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed getting map data points: ' . $dictionaryName, array("context" => (string) $e));
            throw $e;
        }
        return $mapPoints;
    }

    public function buildMapPointQueryString($dictName, $dataSetting, $ids, &$strWhereIds, &$bind): string {
        //build query string for the items selected 
        $dictionaryHelper = $this->getDictionaryHelper();
        $dictionary = $dictionaryHelper->loadDictionary($dictName);

        $jsonMapInfo = json_decode($dataSetting['mapInfo'], true);
        $locationItems = $jsonMapInfo['gps'];
        $stm = "";

        $this->logger->debug('printing location items ' . print_r($locationItems, true));
        $latList = $dictionary->finditem(strtoupper($locationItems['latitude']));
        $longList = $dictionary->finditem(strtoupper($locationItems['longitude']));

        $strWhereIds = "";
        $strWhereLocation = "";
        if (isset($ids)) {
            $index = 0;
            foreach ($ids as $id) {
                $level = $dictionary->getLevels()[0];
                $idItem = $level->getIdItems()[$index];
                $idItemName = "`" . strtolower($idItem->getName()) . "`";

                if ($index === 0) {
//get the first id item name convert to lower and set the where clause
                    $strWhereIds = " WHERE " . $idItemName . " = :id$index";
                    $bind["id$index"] = $id;
                } else {
                    $strWhereIds .= " AND " . $idItemName . " = :id$index";
                    $bind["id$index"] = $id;
                }
                $index++;
            }
        }

        if ($latList !== false && $longList !== false) {
            $latName = strtolower($latList[0]->getName());
            $longName = strtolower($longList[0]->getName());
            $latLabel = "latitude"; //client expects label as "latitude"  and "longitude" //strtolower($latList[0]->getLabel());
            $longLabel = "longitude"; //strtolower($longList[0]->getLabel());
            $this->logger->debug("$latName : $longName : $latLabel : LongLabel");
            if (isset($latList[0]) && !isset($latList[1])) {//id item
                $strLatLongQuery = "`level-1`.`$latName` as  $latLabel, `level-1`.`$longName` as $longLabel FROM `level-1`";
                $stm = "SELECT `level-1`.`case-id` as `guid` " . $strLatLongQuery;
            } elseif (isset($latList[1]) && isset($longList[1]) && $longList[1] === $latList[1]) {//non-id item assuming (lat/long should be from same record
                $this->logger->debug("inside non-id");
                $recordName = strtolower($longList[1]->getName());
                $strLatLongQuery = "`$recordName`.`$latName` as  $latLabel, `$recordName`.`$longName` as $longLabel";
                $stm = "SELECT `level-1`.`case-id` as `guid`, " . $strLatLongQuery . " FROM `$recordName` JOIN `level-1`  ON  `level-1`.`level-1-id` = $recordName.`level-1-id`  ";
                if($strWhereIds != "") {
                    $strWhereLocation .= " AND `$recordName`.`$latName` IS NOT NULL AND `$recordName`.`$longName` IS NOT NULL ";
                }
                else {
                    $strWhereLocation .= " WHERE  `$recordName`.`$latName` IS NOT NULL AND `$recordName`.`$longName` IS NOT NULL ";
                }
            }
        }
        return $stm . $strWhereIds . $strWhereLocation;
    }

}
