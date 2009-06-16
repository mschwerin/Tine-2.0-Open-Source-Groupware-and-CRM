<?php
/**
 * Tine 2.0
 *
 * @package     Felamimail
 * @subpackage  Controller
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Philipp Schuele <p.schuele@metaways.de>
 * @copyright   Copyright (c) 2009 Metaways Infosystems GmbH (http://www.metaways.de)
 * @version     $Id$
 * 
 * @todo        add getFolderStatus function that returns unread/recent/.. counters for all folders for one account
 * @todo        add cleanup routine for deleted (by other clients)/outofdate folders?
 */

/**
 * folder controller for Felamimail
 *
 * @package     Felamimail
 * @subpackage  Controller
 */
class Felamimail_Controller_Folder extends Tinebase_Controller_Abstract implements Tinebase_Controller_SearchInterface
{
    /**
     * application name (is needed in checkRight())
     *
     * @var string
     */
    protected $_applicationName = 'Felamimail';
    
    /**
     * last search count (2 dim array: userId => accountId => count)
     *
     * @var array
     */
    protected $_lastSearchCount = array();
    
    /**
     * system folder names
     *
     * @var array
     * 
     * @todo    get these from account settings
     */
    protected $_systemFolders = array('trash', 'inbox', 'drafts', 'junk', 'sent', 'templates');
    
    /**
     * folder delimiter/separator
     * 
     * @staticvar string
     * 
     * @todo get delimiter from backend?
     */
    const DELIMITER = '/';
    
    /**
     * folder backend
     *
     * @var Felamimail_Backend_Folder
     */
    protected $_folderBackend = NULL;
    
    /**
     * holds the instance of the singleton
     *
     * @var Felamimail_Controller_Folder
     */
    private static $_instance = NULL;
    
    /**
     * the constructor
     *
     * don't use the constructor. use the singleton
     */
    private function __construct() {
        $this->_currentAccount = Tinebase_Core::getUser();
        $this->_folderBackend = new Felamimail_Backend_Folder();
    }
    
    /**
     * don't clone. Use the singleton.
     *
     */
    private function __clone() 
    {        
    }
    
    /**
     * the singleton pattern
     *
     * @return Felamimail_Controller_Folder
     */
    public static function getInstance() 
    {
        if (self::$_instance === NULL) {
            self::$_instance = new Felamimail_Controller_Folder();
        }
        
        return self::$_instance;
    }

    /************************************* public functions *************************************/
    
    /**
     * get list of records
     *
     * @param Tinebase_Model_Filter_FilterGroup|optional $_filter
     * @param Tinebase_Model_Pagination|optional $_pagination
     * @param bool $_getRelations
     * @return Tinebase_Record_RecordSet
     * 
     * @todo remove caching/counting here when we have the unread/recent check recursive function
     */
    public function search(Tinebase_Model_Filter_FilterGroup $_filter = NULL, Tinebase_Record_Interface $_pagination = NULL, $_getRelations = FALSE, $_onlyIds = FALSE)
    {
        $filterValues = $this->_extractFilter($_filter);
        
        try {
            // try to get folders from imap backend
            $result = $this->getSubFolders($filterValues['globalname'], $filterValues['account_id']);    
            
        } catch (Zend_Mail_Protocol_Exception $zmpe) {
            Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . ' ' . $zmpe->getMessage());
            
            // get folders from db
            $filter = new Felamimail_Model_FolderFilter(array(
                array('field' => 'parent',      'operator' => 'equals', 'value' => $filterValues['globalname']),
                array('field' => 'account_id',  'operator' => 'equals', 'value' => $filterValues['account_id'])
            ));
            $result = $this->_folderBackend->search($filter);
        }
        
        // @todo remove-->
        $messageCacheBackend = new Felamimail_Backend_Cache_Sql_Message();
        //$cacheController = Felamimail_Controller_Cache::getInstance();
        foreach ($result as $folder) {
            //$folder = $cacheController->update($folder);
            if ($folder->cache_status == 'complete') {
                $seenCount = $messageCacheBackend->seenCountByFolderId($folder->getId());
                Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
                    . ' Get unread count for ' . $folder->globalname
                    . ': totalcount = ' . $folder->totalcount . ' / seencount = ' . $seenCount
                );
                $folder->unreadcount = $folder->totalcount - $seenCount;
            }
        }
        //<--remove
        
        $this->_lastSearchCount[$this->_currentAccount->getId()][$filterValues['account_id']] = count($result);
        
        return $result;
    }
    
    /**
     * Gets total count of search with $_filter
     * 
     * @param Tinebase_Model_Filter_FilterGroup $_filter
     * @return int
     */
    public function searchCount(Tinebase_Model_Filter_FilterGroup $_filter)
    {
        $filterValues = $this->_extractFilter($_filter);
        
        return $this->_lastSearchCount[$this->_currentAccount->getId()][$filterValues['account_id']];
    }
    
    /**
     * create folder
     *
     * @param string $_folderName to create
     * @param string $_parentFolder
     * @param string $_accountId [optional]
     * @return Felamimail_Model_Folder
     */
    public function create($_folderName, $_parentFolder = '', $_accountId = 'default')
    {
        $imap = Felamimail_Backend_ImapFactory::factory($_accountId);
        $imap->createFolder($_folderName, $_parentFolder);
        
        $globalname = (empty($_parentFolder)) ? $_folderName : $_parentFolder . self::DELIMITER . $_folderName;
        
        // create new folder
        $folder = new Felamimail_Model_Folder(array(
            'localname'     => $_folderName,
            'globalname'    => $globalname,
            'account_id'    => $_accountId,
            'parent'        => $_parentFolder
        ));           
        
        $folder = $this->_folderBackend->create($folder);
        return $folder;
    }
    
    /**
     * remove folder
     *
     * @param string $_folderName globalName (complete path) of folder to delete
     * @param string $_accountId
     */
    public function delete($_folderName, $_accountId = 'default')
    {
        $imap = Felamimail_Backend_ImapFactory::factory($_accountId);
        $imap->removeFolder($_folderName);
        
        try {
            $folder = $this->_folderBackend->getByBackendAndGlobalName($_accountId, $_folderName);
            $this->_folderBackend->delete($folder->getId());
        } catch (Tinebase_Exception_NotFound $tenf) {
            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' Trying to delete non-existant folder.');
        }
    }
    
    /**
     * rename folder
     *
     * @param string $_oldFolderName local (complete path) of folder to rename
     * @param string $_newFolderName new globalName of folder
     * @param string $_accountId [optional]
     * @return Felamimail_Model_Folder
     */
    public function rename($_newLocalName, $_oldGlobalName, $_accountId = 'default')
    {
        //$globalNameParts = explode(self::DELIMITER, $_oldGlobalName);
        //$folder->localname = array_pop($globalNameParts);
        
        $newGlobalName = preg_replace("/[_\-a-zA-Z0-9\.]+$/", $_newLocalName, $_oldGlobalName);
        
        $imap = Felamimail_Backend_ImapFactory::factory($_accountId);
        $imap->renameFolder($_oldGlobalName, $newGlobalName);
        
        // rename folder in db
        try {
            $folder = $this->_folderBackend->getByBackendAndGlobalName($_accountId, $_oldGlobalName);
            $folder->globalname = $newGlobalName;
            $folder->localname = $_newLocalName;
            $folder = $this->_folderBackend->update($folder);
            
        } catch (Tinebase_Exception_NotFound $tenf) {
            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' Trying to rename non-existant folder.');
            throw $tenf;
        }
        
        return $folder;
    }

    /**
     * get (sub) folder and create folders in db backend
     *
     * @param string $_folderName
     * @param string $_accountId [optional]
     * @return Tinebase_Record_RecordSet of Felamimail_Model_Folder
     * 
     * @todo replace mb_convert_encoding with iconv or something like that
     */
    public function getSubFolders($_folderName = '', $_accountId = 'default')
    {
        $imap = Felamimail_Backend_ImapFactory::factory($_accountId);
        
        if(empty($_folderName)) {
            Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' get subfolders of root for backend ' . $_accountId);
            $folders = $imap->getFolders('', '%');
        } else {
            try {
                
                Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' trying to get subfolders of ' . $_folderName . self::DELIMITER);
                $folders = $imap->getFolders($_folderName . self::DELIMITER, '%');
                
            } catch (Zend_Mail_Storage_Exception $zmse) {
                
                Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . $zmse->getMessage() .' - Trying again ...');
                
                // try again without delimiter
                try {
                    Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' trying to get subfolders of ' . $_folderName . self::DELIMITER);
                    $folders = $imap->getFolders($_folderName, '%');
                    
                } catch (Zend_Mail_Storage_Exception $zmse) {
                    Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . $zmse->getMessage());
                    $folders = array();
                }
            }
            
            // remove folder if self
            if (in_array($_folderName, array_keys($folders))) {
                unset($folders[$_folderName]);
            }
        }
        
        //Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . print_r($folders, true));
        
        // do some mapping and save folder in db
        $result = new Tinebase_Record_RecordSet('Felamimail_Model_Folder');
        
        foreach ($folders as $folderData) {
            try {
                // decode folder name
                $folderData['localName'] = mb_convert_encoding($folderData['localName'], "utf-8", "UTF7-IMAP");
                $folderData['globalName'] = mb_convert_encoding($folderData['globalName'], "utf-8", "UTF7-IMAP");
                
                $folder = $this->_folderBackend->getByBackendAndGlobalName($_accountId, $folderData['globalName']);
                $folder->is_selectable = ($folderData['isSelectable'] == '1');
                $folder->has_children = ($folderData['hasChildren'] == '1');
                
            } catch (Tinebase_Exception_NotFound $tenf) {
                // create new folder
                $folder = new Felamimail_Model_Folder(array(
                    'localname'     => $folderData['localName'],
                    'globalname'    => $folderData['globalName'],
                    'is_selectable' => ($folderData['isSelectable'] == '1'),
                    'has_children'  => ($folderData['hasChildren'] == '1'),
                    'account_id'    => $_accountId,
                    'timestamp'     => Zend_Date::now(),
                    'user_id'       => $this->_currentAccount->getId(),
                    'parent'        => $_folderName,
                    'system_folder' => in_array(strtolower($folderData['localName']), $this->_systemFolders)
                ));
                
                $folder = $this->_folderBackend->create($folder);
            }
            
            $result->addRecord($folder);
        }
        
        return $result;
    }
    
    /**
     * delete all messages in one folder
     *
     * @param string $_folderId
     * @return void
     */
    public function emptyFolder($_folderId)
    {
        $filter = new Felamimail_Model_MessageFilter(array(
            array('field' => 'folder_id', 'operator' => 'equals', 'value' => $_folderId)
        ));
        
        $messages = Felamimail_Controller_Message::getInstance()->search($filter);
        
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Trying to delete ' 
            . count($messages) . ' messages from folder with id ' . $_folderId . '.'
        );
        return Felamimail_Controller_Message::getInstance()->delete($messages->getArrayOfIds());
    }
    
    /************************************* protected functions *************************************/
    
    /**
     * extract values from folder filter
     *
     * @param Felamimail_Model_FolderFilter $_filter
     * @return array (assoc) with filter values
     * 
     * @todo add AND/OR conditions for multiple filters of the same field?
     */
    protected function _extractFilter(Felamimail_Model_FolderFilter $_filter)
    {
        $result = array('account_id' => 'default', 'globalname' => '');
        
        $filters = $_filter->getFilterObjects();
        foreach($filters as $filter) {
            switch($filter->getField()) {
                case 'account_id':
                    $result['account_id'] = $filter->getValue();
                    break;
                case 'globalname':
                    $result['globalname'] = $filter->getValue();
                    break;
            }
        }
        
        return $result;
    }
}
