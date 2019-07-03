<?php
/**
 * Tine 2.0
 *
 * @package     Felamimail
 * @subpackage  Controller
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Philipp Schüle <p.schuele@metaways.de>
 * @copyright   Copyright (c) 2009-2018 Metaways Infosystems GmbH (http://www.metaways.de)
 */

/**
 * Account controller for Felamimail
 *
 * @package     Felamimail
 * @subpackage  Controller
 */
class Felamimail_Controller_Account extends Tinebase_Controller_Record_Abstract
{
    /**
     * application name (is needed in checkRight())
     *
     * @var string
     */
    protected $_applicationName = 'Felamimail';
    
    /**
     * if imap config useSystemAccount is active
     *
     * @var boolean
     */
    protected $_useSystemAccount = FALSE;
    
    /**
     * imap config
     * 
     * @var array
     */
    protected $_imapConfig = array();
    
    /**
     * holds the instance of the singleton
     *
     * @var Felamimail_Controller_Account
     */
    private static $_instance = NULL;
    
    /**
     * @var Felamimail_Backend_Account
     */
    protected $_backend;

    const ACCOUNT_CAPABILITIES_CACHEID = 'Felamimail_Account_Capabilities';

    /**
     * the constructor
     *
     * don't use the constructor. use the singleton
     */
    private function __construct()
    {
        $this->_modelName = Felamimail_Model_Account::class;
        $this->_doContainerACLChecks = true;
        $this->_doRightChecks = true;
        $this->_purgeRecords = false;
        
        $this->_backend = new Felamimail_Backend_Account();
        
        $this->setImapConfig();
        $this->_useSystemAccount = isset($this->_imapConfig[Tinebase_Config::IMAP_USE_SYSTEM_ACCOUNT])
            && $this->_imapConfig[Tinebase_Config::IMAP_USE_SYSTEM_ACCOUNT];
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
     * @return Felamimail_Controller_Account
     */
    public static function getInstance() 
    {
        if (self::$_instance === NULL) {
            self::$_instance = new Felamimail_Controller_Account();
        }
        
        return self::$_instance;
    }

    public static function destroyInstance()
    {
        self::$_instance = null;
    }

    public function setImapConfig()
    {
        $this->_imapConfig = Tinebase_Config::getInstance()->get(Tinebase_Config::IMAP, new Tinebase_Config_Struct())->toArray();
    }

    /**
     * get list of records
     *
     * @param Tinebase_Model_Filter_FilterGroup $_filter
     * @param Tinebase_Model_Pagination $_pagination
     * @param boolean $_getRelations
     * @param boolean $_onlyIds
     * @param string $_action for right/acl check
     * @return Tinebase_Record_RecordSet|array
     */
    public function search(Tinebase_Model_Filter_FilterGroup $_filter = NULL, Tinebase_Model_Pagination $_pagination = NULL, $_getRelations = FALSE, $_onlyIds = FALSE, $_action = 'get')
    {
        if ($_filter === NULL) {
            $_filter = Tinebase_Model_Filter_FilterGroup::getFilterForModel(Felamimail_Model_Account::class);
        }
        
        $result = parent::search($_filter, $_pagination, $_getRelations, $_onlyIds, $_action);

        // check preference / config if we should add system account with tine user credentials or from config.inc.php
        if ($this->_useSystemAccount && ! $_onlyIds) {
            // check if resultset contains system account and add config values
            foreach ($result as $account) {
                if ($account->type === Felamimail_Model_Account::TYPE_SYSTEM) {
                    $this->_addSystemAccountConfigValues($account);
                }
            }
        }
        
        return $result;
    }

    /**
     * Gets total count of search with $_filter
     * 
     * @param Tinebase_Model_Filter_FilterGroup $_filter
     * @param string $_action for right/acl check
     * @return int
     */
    public function searchCount(Tinebase_Model_Filter_FilterGroup $_filter, $_action = 'get') 
    {
        $this->checkFilterACL($_filter, $_action);
        return $this->_backend->searchCount($_filter);
    }
    
    /**
     * get by id
     *
     * @param string $_id
     * @param int $_containerId
     * @return Felamimail_Model_Account
     */
    public function get($_id, $_containerId = NULL, $_getRelatedData = true, $_getDeleted = false)
    {
        /** @var Felamimail_Model_Account $record */
        $record = parent::get($_id, $_containerId, $_getRelatedData, $_getDeleted);

        if ($record->type === Felamimail_Model_Account::TYPE_SYSTEM) {
            $this->_addSystemAccountConfigValues($record);
        }
        
        return $record;
    }
    
    /**
     * Deletes a set of records.
     * 
     * @param   array array of record identifiers
     * @return  void
     */
    public function delete($_ids)
    {
        parent::delete($_ids);
        
        // check if default account got deleted and set new default account
        if (in_array(Tinebase_Core::getPreference($this->_applicationName)->{Felamimail_Preference::DEFAULTACCOUNT}, (array) $_ids)) {
            $accounts = $this->search();
            $defaultAccountId = (count($accounts) > 0) ? $accounts->getFirstRecord()->getId() : '';
            
            Tinebase_Core::getPreference($this->_applicationName)->{Felamimail_Preference::DEFAULTACCOUNT} = $defaultAccountId;
        }
    }
    
    /**
     * Removes accounts where current user has no access to
     * 
     * @param Tinebase_Model_Filter_FilterGroup $_filter
     * @param string $_action get|update
     * @throws Tinebase_Exception_AccessDenied
     */
    public function checkFilterACL(Tinebase_Model_Filter_FilterGroup $_filter, $_action = 'get')
    {
        if (! $this->doContainerACLChecks()) {
            return;
        }

        $userFilter = $_filter->getFilter('user_id');

        // fix me!
        // TODO fix this for shared / adb list accounts!!!

        // force a $userFilter filter (ACL)
        if ($userFilter === NULL || $userFilter->getOperator() !== 'equals' || $userFilter->getValue() !== Tinebase_Core::getUser()->getId()) {
            if (! is_object(Tinebase_Core::getUser())) {
                throw new Tinebase_Exception_AccessDenied('user object not found');
            }
            $userFilter = $_filter->createFilter('user_id', 'equals', Tinebase_Core::getUser()->getId());
            $_filter->addFilter($userFilter);
        }
    }

    /**
     * inspect creation of one record
     * - add credentials and user id here
     * 
     * @param   Tinebase_Record_Interface $_record
     * @return  void
     */
    protected function _inspectBeforeCreate(Tinebase_Record_Interface $_record)
    {
        // add user id
        if ($_record->type !== Tinebase_EmailUser_Model_Account::TYPE_ADB_LIST &&
                $_record->type !== Tinebase_EmailUser_Model_Account::TYPE_SHARED) {
            $_record->user_id = Tinebase_Core::getUser()->getId();
        }
        
        // use the imap host as smtp host if empty
        if (! $_record->smtp_hostname) {
            $_record->smtp_hostname = $_record->host;
        }
        
        if (! $_record->user || ! $_record->password) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' No username or password given for new account.');
            return;
        }
        
        // add imap & smtp credentials
        if ($_record->type === Tinebase_EmailUser_Model_Account::TYPE_ADB_LIST ||
                $_record->type === Tinebase_EmailUser_Model_Account::TYPE_SHARED) {
            $_record->credentials_id = $this->_createSharedCredentials($_record->user, $_record->password);
            if ($_record->smtp_user && $_record->smtp_password) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) {
                    Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Create SMTP credentials.');
                }
                $_record->smtp_credentials_id = $this->_createSharedCredentials($_record->smtp_user, $_record->smtp_password);
            } else {
                $_record->smtp_credentials_id = $_record->credentials_id;
            }
        } else {
            $_record->credentials_id = $this->_createCredentials($_record->user, $_record->password);
            if ($_record->smtp_user && $_record->smtp_password) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) {
                    Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Create SMTP credentials.');
                }
                $_record->smtp_credentials_id = $this->_createCredentials($_record->smtp_user, $_record->smtp_password);
            } else {
                $_record->smtp_credentials_id = $_record->credentials_id;
            }
        }
        
        $this->_checkSignature($_record);
    }
    
    /**
     * convert signature to text to remove all html tags and spaces/linebreaks, if the remains are empty -> set empty signature
     * 
     * @param Felamimail_Model_Account $account
     */
    protected function _checkSignature($account)
    {
        if (empty($account->signature)) {
            return;
        }
        
        $plainTextSignature = Felamimail_Message::convertFromHTMLToText($account->signature, "\n");
        if (! preg_match('/[^\s^\\n]/', $plainTextSignature, $matches)) {
            $account->signature = '';
        }
    }

    /**
     * inspect creation of one record (after create)
     * 
     * @param   Tinebase_Record_Interface $_createdRecord
     * @param   Tinebase_Record_Interface $_record
     * @return  void
     */
    protected function _inspectAfterCreate($_createdRecord, Tinebase_Record_Interface $_record)
    {
        if ($_createdRecord->type === Tinebase_EmailUser_Model_Account::TYPE_USER) {
            // set as default account if it is the only account
            $accountCount = $this->searchCount(Tinebase_Model_Filter_FilterGroup::getFilterForModel(Felamimail_Model_Account::class));
            if ($accountCount == 1) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) {
                    Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Set account ' . $_createdRecord->name . ' as new default email account.');
                }
                Tinebase_Core::getPreference($this->_applicationName)->{Felamimail_Preference::DEFAULTACCOUNT} = $_createdRecord->getId();
            }
        }
    }
    
    /**
     * inspect update of one record
     * 
     * @param   Tinebase_Record_Interface $_record      the update record
     * @param   Tinebase_Record_Interface $_oldRecord   the current persistent record
     * @return  void
     */
    protected function _inspectBeforeUpdate($_record, $_oldRecord)
    {
        if ($_record->type !== $_oldRecord->type) {
            throw new Tinebase_Exception_UnexpectedValue('type can not change');
        }

        if ($_record->type === Felamimail_Model_Account::TYPE_SYSTEM) {
            $this->_beforeUpdateSystemAccount($_record, $_oldRecord);
        } else {
            $this->_beforeUpdateStandardAccount($_record, $_oldRecord);
        }

        $this->_checkSignature($_record);
    }
    
    /**
     * inspect update of system account
     * - only allow to update certain fields of system accounts
     * 
     * @param   Tinebase_Record_Interface $_record      the update record
     * @param   Tinebase_Record_Interface $_oldRecord   the current persistent record
     * @return  void
     */
    protected function _beforeUpdateSystemAccount($_record, $_oldRecord)
    {
        // only allow to update some values for system accounts
        $allowedFields = array(
            'name',
            'signature',
            'signature_position',
            'display_format',
            'compose_format',
            'preserve_format',
            'reply_to',
            'has_children_support',
            'delimiter',
            'ns_personal',
            'ns_other',
            'ns_shared',
            'last_modified_time',
            'last_modified_by',
            'sieve_notification_email',
        );
        $diff = $_record->diff($_oldRecord)->diff;
        foreach ($diff as $key => $value) {
            if (! in_array($key, $allowedFields)) {
                // setting old value
                $_record->$key = $_oldRecord->$key;
            }
        } 
    }
    
    /**
     * inspect update of normal user account
     * 
     * @param   Tinebase_Record_Interface $_record      the update record
     * @param   Tinebase_Record_Interface $_oldRecord   the current persistent record
     * @return  void
     */
    protected function _beforeUpdateStandardAccount($_record, $_oldRecord)
    {
        if ($_record->type === Felamimail_Model_Account::TYPE_USER) {
            $this->_beforeUpdateStandardAccountCredentials($_record, $_oldRecord);
        } else {
            $this->_beforeUpdateSharedAccountCredentials($_record, $_oldRecord);
        }
        
        $diff = $_record->diff($_oldRecord)->diff;
        
        // delete message body cache because display format has changed
        if ((isset($diff['display_format']) || array_key_exists('display_format', $diff))) {
            Tinebase_Core::getCache()->clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, array('getMessageBody'));
        }

        // reset capabilities if imap host / port changed
        if (isset($diff['host']) || isset($diff['port'])) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
                . ' Resetting capabilities for account ' . $_record->name);
            $cacheId = Tinebase_Helper::convertCacheId(self::ACCOUNT_CAPABILITIES_CACHEID . '_' . $_record->getId());
            Tinebase_Core::getCache()->remove($cacheId);
        }
    }

    /**
     * update shared / adb list account credentials
     *
     * @param   Tinebase_Record_Interface $_record      the update record
     * @param   Tinebase_Record_Interface $_oldRecord   the current persistent record
     * @return  void
     */
    protected function _beforeUpdateSharedAccountCredentials($_record, $_oldRecord)
    {
        // get old credentials
        $credentialsBackend = Tinebase_Auth_CredentialCache::getInstance();

        $credentials = null;
        if ($_oldRecord->credentials_id) {
            $credentials = $credentialsBackend->get($_oldRecord->credentials_id);
            $credentials->key = Tinebase_Config::getInstance()->{Tinebase_Config::CREDENTIAL_CACHE_SHARED_KEY};
            try {
                $credentialsBackend->getCachedCredentials($credentials);
            } catch (Tinebase_Exception_NotFound $tenf) {
                // create new credentials in this case
                Tinebase_Exception::log($tenf);
                $credentials = null;
            }
        }

        if (! $credentials) {
            $credentials = new Tinebase_Model_CredentialCache(array(
                'username'  => '',
                'password'  => ''
            ));
        }

        // check if something changed
        if (
            ! $_oldRecord->credentials_id
            ||  (! empty($_record->user) && $_record->user !== $credentials->username)
            ||  (! empty($_record->password) && $_record->password !== $credentials->password)
        ) {
            $newPassword = ($_record->password) ? $_record->password : $credentials->password;
            $newUsername = ($_record->user) ? $_record->user : $credentials->username;

            $_record->credentials_id = $this->_createSharedCredentials($newUsername, $newPassword);
            $imapCredentialsChanged = true;
        } else {
            $imapCredentialsChanged = false;
        }

        if ($_record->smtp_user && $_record->smtp_password) {
            // create extra smtp credentials
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Update/create SMTP credentials.');
            $_record->smtp_credentials_id = $this->_createSharedCredentials($_record->smtp_user, $_record->smtp_password);

        } else if (
            $imapCredentialsChanged
            && (! $_record->smtp_credentials_id || $_record->smtp_credentials_id == $_oldRecord->credentials_id)
        ) {
            // use imap credentials for smtp auth as well
            $_record->smtp_credentials_id = $_record->credentials_id;
        }
    }

    /**
     * update user account credentials
     * 
     * @param   Tinebase_Record_Interface $_record      the update record
     * @param   Tinebase_Record_Interface $_oldRecord   the current persistent record
     * @return  void
     */
    protected function _beforeUpdateStandardAccountCredentials($_record, $_oldRecord)
    {
        // get old credentials
        $credentialsBackend = Tinebase_Auth_CredentialCache::getInstance();
        $userCredentialCache = Tinebase_Core::getUserCredentialCache();
        
        if ($userCredentialCache !== NULL) {
            $credentialsBackend->getCachedCredentials($userCredentialCache);
        } else {
            Tinebase_Core::getLogger()->crit(__METHOD__ . '::' . __LINE__ 
                . ' Something went wrong with the CredentialsCache / use given username/password instead.'
            );
            return;
        }

        $credentials = null;
        if ($_oldRecord->credentials_id) {
            $credentials = $credentialsBackend->get($_oldRecord->credentials_id);
            $credentials->key = substr($userCredentialCache->password, 0, 24);
            try {
                $credentialsBackend->getCachedCredentials($credentials);
            } catch (Tinebase_Exception_NotFound $tenf) {
                // create new credentials in this case
                Tinebase_Exception::log($tenf);
                $credentials = null;
            }
        }

        if (! $credentials) {
            $credentials = new Tinebase_Model_CredentialCache(array(
                'username'  => '',
                'password'  => ''
            ));
        }
        
        // check if something changed
        if (
            ! $_oldRecord->credentials_id
            ||  (! empty($_record->user) && $_record->user !== $credentials->username)
            ||  (! empty($_record->password) && $_record->password !== $credentials->password)
        ) {
            $newPassword = ($_record->password) ? $_record->password : $credentials->password;
            $newUsername = ($_record->user) ? $_record->user : $credentials->username;

            $_record->credentials_id = $this->_createCredentials($newUsername, $newPassword);
            $imapCredentialsChanged = TRUE;
        } else {
            $imapCredentialsChanged = FALSE;
        }
        
        if ($_record->smtp_user && $_record->smtp_password) {
            // create extra smtp credentials
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Update/create SMTP credentials.');
            $_record->smtp_credentials_id = $this->_createCredentials($_record->smtp_user, $_record->smtp_password);
            
        } else if (
            $imapCredentialsChanged 
            && (! $_record->smtp_credentials_id || $_record->smtp_credentials_id == $_oldRecord->credentials_id)
        ) {
            // use imap credentials for smtp auth as well
            $_record->smtp_credentials_id = $_record->credentials_id;
        }
    }

    /**
     * inspect update of one record (after update)
     *
     * @param   Felamimail_Model_Account $updatedRecord   the just updated record
     * @param   Felamimail_Model_Account $record          the update record
     * @param   Felamimail_Model_Account $currentRecord   the current record (before update)
     * @return  void
     */
    protected function _inspectAfterUpdate($updatedRecord, $record, $currentRecord)
    {
        if ($updatedRecord->sieve_notification_email !== $currentRecord->sieve_notification_email) {
            Felamimail_Controller_Sieve::getInstance()->setNotificationEmail($updatedRecord->getId(),
                $updatedRecord->sieve_notification_email);
        }
    }
    
    /**
     * check if user has the right to manage accounts
     * 
     * @param string $_action {get|create|update|delete}
     * @return void
     * @throws Tinebase_Exception_AccessDenied
     */
    protected function _checkRight($_action)
    {
        if (! $this->_doRightChecks) {
            return;
        }
        
        switch ($_action) {
            case 'create':
                if (! Tinebase_Core::getUser()->hasRight($this->_applicationName, Felamimail_Acl_Rights::ADD_ACCOUNTS)) {
                    throw new Tinebase_Exception_AccessDenied("You don't have the right to add accounts!");
                }
                break;
            case 'update':
            case 'delete':
                if (! Tinebase_Core::getUser()->hasRight($this->_applicationName, Felamimail_Acl_Rights::MANAGE_ACCOUNTS)) {
                    throw new Tinebase_Exception_AccessDenied("You don't have the right to manage accounts!");
                }
                break;
            default;
               break;
        }

        parent::_checkRight($_action);
    }
    
    /**
     * change account password
     *
     * @param string $_accountId
     * @param string $_username
     * @param string $_password
     * @return boolean
     */
    public function changeCredentials($_accountId, $_username, $_password)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Changing credentials for account id ' . $_accountId);
        
        // get account and set pwd
        $account = $this->get($_accountId);
        
        $account->user = $_username;
        $account->password = $_password;
        
        // update account
        $this->doRightChecks(FALSE);
        $this->update($account);
        $this->doRightChecks(TRUE);
        
        return TRUE;
    }
    
    /**
     * updates all credentials of user accounts with new password
     * 
     * @param Tinebase_Model_CredentialCache $_oldUserCredentialCache old user credential cache
     */
    public function updateCredentialsOfAllUserAccounts(Tinebase_Model_CredentialCache $_oldUserCredentialCache)
    {
        Tinebase_Auth_CredentialCache::getInstance()->getCachedCredentials($_oldUserCredentialCache);
        $accounts = $this->search();
        
        foreach ($accounts as $account) {
            if ($account->type === Felamimail_Model_Account::TYPE_USER) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Updating credentials for account ' . $account->name);
                
                $imapAndSmtpAreEqual = ($account->credentials_id == $account->smtp_credentials_id);
                $credentialIdKeys = array('credentials_id', 'smtp_credentials_id');
                foreach ($credentialIdKeys as $idKey) {
                    if (! empty($account->{$idKey})) {
                        if ($idKey == 'smtp_credentials_id' && $imapAndSmtpAreEqual) {
                            $account->smtp_credentials_id = $account->credentials_id;
                        } else {
                            $oldCredentialCache = Tinebase_Auth_CredentialCache::getInstance()->get($account->{$idKey});
                            $oldCredentialCache->key = $_oldUserCredentialCache->password;
                            Tinebase_Auth_CredentialCache::getInstance()->getCachedCredentials($oldCredentialCache);
                            $account->{$idKey} = $this->_createCredentials($oldCredentialCache->username, $oldCredentialCache->password);
                        }
                    }
                }
                $this->_backend->update($account);
            }
        }
    }
    
    /**
     * get imap server capabilities and save delimiter / personal namespace in account
     *
     * - capabilities are saved in the cache
     *
     * @param Felamimail_Model_Account $_account
     * @return array capabilities
     */
    public function updateCapabilities(Felamimail_Model_Account $_account, Felamimail_Backend_ImapProxy $_imapBackend = NULL)
    {
        $cacheId = Tinebase_Helper::convertCacheId(self::ACCOUNT_CAPABILITIES_CACHEID . '_' . $_account->getId());
        $cache = Tinebase_Core::getCache();

        if ($cache->test($cacheId)) {
            return $cache->load($cacheId);
        }

        $imapBackend = ($_imapBackend !== NULL) ? $_imapBackend : $this->_getIMAPBackend($_account, TRUE);
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
            . ' Getting capabilities of account ' . $_account->name);
        
        // get imap server capabilities and save delimiter / personal namespace in account
        $capabilities = $imapBackend->getCapabilityAndNamespace();
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
            . ' Capabilities: ' . print_r($capabilities, TRUE));
        
        $this->_updateNamespacesAndDelimiter($_account, $capabilities);

        // check if server has 'CHILDREN' support
        $_account->has_children_support = (in_array('CHILDREN', $capabilities['capabilities'])) ? 1 : 0;
        
        try {
            if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' Updating capabilities for account: ' . $_account->name);
            $this->_backend->update($_account);
        } catch (Zend_Db_Statement_Exception $zdse) {
            Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . ' Could not update account: ' . $zdse->getMessage());
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . $zdse->getTraceAsString());
        }
        
        // save capabilities in cache
        $cache->save($capabilities, $cacheId);
        
        return $capabilities;
    }
    
    /**
     * update account namespaces from capabilities
     * 
     * @param Felamimail_Model_Account $_account
     * @param array $_capabilities
     */
    protected function _updateNamespacesAndDelimiter(Felamimail_Model_Account $_account, $_capabilities)
    {
        if (! isset($_capabilities['namespace'])) {
            return;
        }
        
        // update delimiter
        $delimiter = (! empty($_capabilities['namespace']['personal'])) 
            ? $_capabilities['namespace']['personal']['delimiter'] : '/';

        // care for multiple backslashes (for example from Domino IMAP server)
        if ($delimiter == '\\\\') {
            $delimiter = '\\';
        }

        if (strlen($delimiter) > 1) {
            Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__ . ' Got long delimiter: ' . $delimiter . ' Fall back to default (/)');
            $delimiter = '/';
        }

        if ($delimiter && $delimiter != $_account->delimiter) {
            $_account->delimiter = $delimiter;
        }
    
        // update namespaces
        $_account->ns_personal   = (! empty($_capabilities['namespace']['personal'])) ? $_capabilities['namespace']['personal']['name']: '';
        $_account->ns_other      = (! empty($_capabilities['namespace']['other']))    ? $_capabilities['namespace']['other']['name']   : '';
        $_account->ns_shared     = (! empty($_capabilities['namespace']['shared']))   ? $_capabilities['namespace']['shared']['name']  : '';

        $this->_addNamespaceToFolderConfig($_account);
    }
    
    /**
     * add namespace to account system folder names
     * 
     * @param Felamimail_Model_Account $_account
     * @param string $_namespace
     * @param array $_folders
     */
    protected function _addNamespaceToFolderConfig($_account, $_namespace = 'ns_personal', $_folders = array())
    {
        $folders = (empty($_folders)) ? array(
            'sent_folder',
            'trash_folder',
            'drafts_folder',
            'templates_folder',
        ) : $_folders;
        
        if ($_account->{$_namespace} === 'NIL') {
            Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . ' No ' . $_namespace . ' namespace available for account ' . $_account->name);
            return;
        }
        
        if (empty($_account->{$_namespace})) {
            return;
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
            . ' Setting ' . $_namespace . ' namespace: "' . $_account->{$_namespace} . '" for systemfolders of account ' . $_account->name);
        
        foreach ($folders as $folder) {
            if (! preg_match('/^' . preg_quote($_account->{$_namespace}, '/') . '/', $_account->{$folder})) {
                $_account->{$folder} = $_account->{$_namespace} . $_account->{$folder};
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
                    . ' Updated system folder name: ' . $folder .' -> ' . $_account->{$folder});
            }
        }
    }
    
    /**
     * get imap backend and catch exceptions
     * 
     * @param Felamimail_Model_Account $_account
     * @param boolean $_throwException
     * @return boolean|Felamimail_Backend_ImapProxy
     * @throws Felamimail_Exception_IMAP|Felamimail_Exception_IMAPInvalidCredentials
     */
    protected function _getIMAPBackend(Felamimail_Model_Account $_account, $_throwException = FALSE)
    {
        $result = FALSE;
        try {
            $result = Felamimail_Backend_ImapFactory::factory($_account);
        } catch (Zend_Mail_Storage_Exception $zmse) {
            $message = 'Wrong user credentials (' . $zmse->getMessage() . ')';
        } catch (Zend_Mail_Protocol_Exception $zmpe) {
            $message =  'No connection to imap server (' . $zmpe->getMessage() . ')';
        } catch (Felamimail_Exception_IMAPInvalidCredentials $feiic) {
            $message = 'Wrong user credentials (' . $feiic->getMessage() . ')';
        }
        
        if (! $result) {
            $message .= ' for account ' . $_account->name;
            
            if ($_throwException) {
                throw (isset($feiic)) ? $feiic : new Felamimail_Exception_IMAP($message);
            } else {
                Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . ' ' . $message);
            }
        }
        
        return $result;
    }
    
    /**
     * get system folder for account
     * 
     * @param string|Felamimail_Model_Account $_account
     * @param string $_systemFolder
     * @return NULL|Felamimail_Model_Folder
     */
    public function getSystemFolder($_account, $_systemFolder)
    {
        $account = ($_account instanceof Felamimail_Model_Account) ? $_account : $this->get($_account);
        $changed = $this->_addFolderDefaults($account);
        if ($changed) {
            // need to use backend update because we prohibit the change of some fields in _inspectBeforeUpdate()
            $account = $this->_backend->update($account);
        }
        
        $systemFolderField = $this->_getSystemFolderField($_systemFolder);
        $folderName = $account->{$systemFolderField};
        
        if (empty($folderName)) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__
                    . ' No ' . $_systemFolder . ' folder set in account.');
            return NULL;
        }
        
        // check if folder exists on imap server
        $imapBackend = $this->_getIMAPBackend($account);
        if ($imapBackend && $imapBackend->getFolderStatus(Felamimail_Model_Folder::encodeFolderName($folderName)) === false) {
            $systemFolder = $this->_createSystemFolder($account, $folderName);
            if ($systemFolder->globalname !== $folderName) {
                $account->{$systemFolderField} = $systemFolder->globalname;
                $this->_backend->update($account);
            }
        } else {
            try {
                $systemFolder = Felamimail_Controller_Folder::getInstance()->getByBackendAndGlobalName($account->getId(), $folderName);
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
                    . ' Found system folder: ' . $folderName);
                                
            } catch (Tinebase_Exception_NotFound $tenf) {
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' 
                    . $tenf->getMessage());
                
                $splitFolderName = Felamimail_Model_Folder::extractLocalnameAndParent($_systemFolder, $account->delimiter);
                Felamimail_Controller_Cache_Folder::getInstance()->update($account, $splitFolderName['parent'], TRUE);
                $systemFolder = Felamimail_Controller_Folder::getInstance()->getByBackendAndGlobalName($account->getId(), $folderName);
            }
        }
        
        return $systemFolder;
    }
    
    /**
     * map folder constant to account model field
     * 
     * @param string $_systemFolder
     * @return string
     * @throws Tinebase_Exception_InvalidArgument
     */
    protected function _getSystemFolderField($_systemFolder)
    {
        switch ($_systemFolder) {
            case Felamimail_Model_Folder::FOLDER_TRASH:
                $field = 'trash_folder';
                break;
            case Felamimail_Model_Folder::FOLDER_SENT:
                $field = 'sent_folder';
                break;
            case Felamimail_Model_Folder::FOLDER_TEMPLATES:
                $field = 'templates_folder';
                break;
            case Felamimail_Model_Folder::FOLDER_DRAFTS:
                $field = 'drafts_folder';
                break;
            default:
                throw new Tinebase_Exception_InvalidArgument('No system folder: ' . $_systemFolder);
        }
        
        return $field;
    }
    
    /**
     * create new system folder
     * 
     * @param Felamimail_Model_Account $_account
     * @param string $_systemFolder
     * @return Felamimail_Model_Folder
     */
    protected function _createSystemFolder(Felamimail_Model_Account $_account, $_systemFolder)
    {
        Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__ . ' Folder not found: ' . $_systemFolder . '. Trying to add it.');
        
        $splitFolderName = Felamimail_Model_Folder::extractLocalnameAndParent($_systemFolder, $_account->delimiter);
        
        try {
            $result = Felamimail_Controller_Folder::getInstance()->create($_account, $splitFolderName['localname'], $splitFolderName['parent']);
        } catch (Felamimail_Exception_IMAPServiceUnavailable $feisu) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' 
                . $feisu->getMessage());
            // try again with INBOX as parent because some IMAP servers can not handle namespaces correctly
            $result = Felamimail_Controller_Folder::getInstance()->create($_account, $splitFolderName['localname'], 'INBOX');
        }
        
        return $result;
    }
    
    /**
     * set vacation active field for account
     * 
     * @param string|Felamimail_Model_Account $_account
     * @param boolean $_vacationEnabled
     * @return Felamimail_Model_Account
     */
    public function setVacationActive(Felamimail_Model_Account $_account, $_vacationEnabled)
    {
        $account = $this->get($_account->getId());
        if ($account->sieve_vacation_active != $_vacationEnabled) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Updating sieve_vacation_active = ' . $_vacationEnabled . ' for account: ' . $account->name);
            
            $account->sieve_vacation_active = (bool) $_vacationEnabled;
            // skip all special update handling
            $account = $this->_backend->update($account);
        }
        
        return $account;
    }

    /**
     * @param Tinebase_Model_User|string|null $_accountId
     * @return Felamimail_Model_Account|null
     * @throws Tinebase_Exception_InvalidArgument
     */
    public function getSystemAccount($_accountId = null)
    {
        if (null === $_accountId) {
            $_accountId = Tinebase_Core::getUser()->getId();
        } elseif ($_accountId instanceof Tinebase_Model_User) {
            $_accountId = $_accountId->getId();
        }
        return $this->search(new Felamimail_Model_AccountFilter([
            ['field' => 'user_id', 'operator' => 'equals', 'value' => $_accountId],
            ['field' => 'type', 'operator' => 'equals', 'value' => Felamimail_Model_Account::TYPE_SYSTEM],
        ]))->getFirstRecord();
    }

    /**
     * add system account with tine user credentials (from config.inc.php or config db) 
     *
     * @param Tinebase_Model_User
     * @return Felamimail_Model_Account
     */
    public function addSystemAccount(Tinebase_Model_User $_account)
    {
        $email = $this->_getAccountEmail($_account);
        
        // only create account if email address is set
        if ($email) {
            if (null !== ($systemAccount = $this->getSystemAccount($_account->getId()))) {
                Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__
                    . ' system account "' . $systemAccount->name . '" already exists.');
                return;
            }
            $systemAccount = new Felamimail_Model_Account([
                'type'      => Felamimail_Model_Account::TYPE_SYSTEM,
                'user_id'   => $_account->getId(),
            ], true);

            $this->_addSystemAccountConfigValues($systemAccount, $_account);

            $this->_addFolderDefaults($systemAccount, true);

            // create new account and update capabilities
            Tinebase_Timemachine_ModificationLog::setRecordMetaData($systemAccount, 'create');
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) {
                Tinebase_Core::getLogger()->debug(
                    __METHOD__ . '::' . __LINE__ . ' ' . print_r($systemAccount->toArray(), true));
            }

            /** @var Felamimail_Model_Account $systemAccount */
            $systemAccount = $this->_backend->create($systemAccount);

            if (Felamimail_Config::getInstance()
                    ->featureEnabled(Felamimail_Config::FEATURE_SYSTEM_ACCOUNT_AUTOCREATE_FOLDERS)) {
                $this->_autoCreateSystemAccountFolders($systemAccount);
            }
            
            // set as default account preference
            Tinebase_Core::getPreference($this->_applicationName)->setValueForUser(
                Felamimail_Preference::DEFAULTACCOUNT, $systemAccount->getId(), $_account->getId(), true);
            
            Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__
                . ' Created new system account "' . $systemAccount->name . '".');

            return $systemAccount;
        } else {
            Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__
                . ' Could not create system account for user ' . $_account->accountLoginName
                . '. No email address given.');
        }

        return null;
    }
    
    /**
     * add folder defaults
     * 
     * @param Felamimail_Model_Account $_account
     * @param boolean $_force
     * @return boolean
     */
    protected function _addFolderDefaults(Felamimail_Model_Account $_account, $_force = FALSE)
    {
        // set some default settings if not set
        $folderDefaults = Felamimail_Config::getInstance()->get(Felamimail_Config::SYSTEM_ACCOUNT_FOLDER_DEFAULTS, array(
            'sent_folder'       => 'Sent',
            'trash_folder'      => 'Trash',
            'drafts_folder'     => 'Drafts',
            'templates_folder'  => 'Templates',
        ));
        
        $changed = FALSE;
        foreach ($folderDefaults as $key => $value) {
            if ($_force || ! isset($_account->{$key}) || empty($_account->{$key})) {
                $_account->{$key} = $value;
                $changed = TRUE;
            }
        }
        
        $this->_addNamespaceToFolderConfig($_account);
        
        return $changed;
    }

    /**
     * @param Felamimail_Model_Account $_account
     */
    protected function _autoCreateSystemAccountFolders(Felamimail_Model_Account $_account)
    {
        try {
            foreach ([
                         Felamimail_Model_Folder::FOLDER_DRAFTS,
                         Felamimail_Model_Folder::FOLDER_SENT,
                         Felamimail_Model_Folder::FOLDER_TEMPLATES,
                         Felamimail_Model_Folder::FOLDER_TRASH
                     ] as $folder) {
                $systemFolderField = $this->_getSystemFolderField($folder);
                $folderName = $_account->{$systemFolderField};
                $this->_createSystemFolder($_account, $folderName);
            }
        } catch (Felamimail_Exception_IMAPInvalidCredentials $feiic) {
            Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__ . ' ' . $feiic->getMessage());
        } catch (Exception $e) {
            // skip creation at this point
            Tinebase_Exception::log($e);
        }
    }
    
    /**
     * returns email address used for the account by checking the user data and imap config
     * 
     * @param Tinebase_Model_User $_user
     * @return string
     */
    protected function _getAccountEmail(Tinebase_Model_User $_user)
    {
        $email = ((! $_user->accountEmailAddress || empty($_user->accountEmailAddress))
            && (isset($this->_imapConfig['user']) || array_key_exists('user', $this->_imapConfig))
        )
            ? $this->_imapConfig['user']
            : $_user->accountEmailAddress;
            
        if (empty($email)) {
            $email = $_user->accountLoginName;
        }
            
        if (! preg_match('/@/', $email)) {
            if (isset($this->_imapConfig['domain'])) {
                $email .= '@' . $this->_imapConfig['domain'];
            } else {
                $email .= '@' . $this->_imapConfig['host'];
            }
        }
        
        return $email;
    }

    /**
     * create a shared credential cache and return new credentials id
     *
     * @param Felamimail_Model_Account $_account
     * @param string $_key
     * @return string
     */
    protected function _createSharedCredentials($_username, $_password)
    {
        $cc = Tinebase_Auth_CredentialCache::getInstance();
        $adapter = explode('_', get_class($cc->getCacheAdapter()));
        $adapter = end($adapter);
        try {
            $cc->setCacheAdapter('Shared');
            $sharedCredentials = Tinebase_Auth_CredentialCache::getInstance()->cacheCredentials($_username, $_password,
                null, true /* save in DB */, Tinebase_DateTime::now()->addYear(100));

            return $sharedCredentials->getId();
        } finally {
            $cc->setCacheAdapter($adapter);
        }
    }

    /**
     * create account credentials and return new credentials id
     *
     * @param string $_username
     * @param string $_password
     * @return string
     */
    protected function _createCredentials($_username = NULL, $_password = NULL, $_userCredentialCache = NULL)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) {
            $message = 'Create new account credentials';
            if ($_username !== NULL) {
                $message .= ' for username ' . $_username;
            } 
            Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' ' . $message);
        }

        $userCredentialCache = Tinebase_Core::getUserCredentialCache();
        if ($userCredentialCache !== null) {
            Tinebase_Auth_CredentialCache::getInstance()->getCachedCredentials($userCredentialCache);
        } else {
            Tinebase_Core::getLogger()->crit(__METHOD__ . '::' . __LINE__ 
                . ' Something went wrong with the CredentialsCache / use given username/password instead.'
            );
            $userCredentialCache = new Tinebase_Model_CredentialCache(array(
                'username' => $_username,
                'password' => $_password,
            ));
        }
        
        $accountCredentials = Tinebase_Auth_CredentialCache::getInstance()->cacheCredentials(
            ($_username !== NULL) ? $_username : $userCredentialCache->username,
            ($_password !== NULL) ? $_password : $userCredentialCache->password,
            $userCredentialCache->password,
            TRUE // save in DB
        );
        
        return $accountCredentials->getId();
    }
    
    /**
     * add settings/values from system account
     * 
     * @param Felamimail_Model_Account $_account
     * @param Tinebase_Model_User $_user
     * @return void
     */
    protected function _addSystemAccountConfigValues(Felamimail_Model_Account $_account, Tinebase_Model_User $_user = null)
    {
        $configs = array(
            Tinebase_Config::IMAP     => array(
                'keys'      => array('host', 'port', 'ssl'),
                'defaults'  => array(), // @todo remove when not needed for sieve anymore
            ),
            Tinebase_Config::SMTP     => array(
                'keys'      => array('hostname', 'port', 'ssl', 'auth'),
                'defaults'  => array(), // @todo remove when not needed for sieve anymore
            ),
            Tinebase_Config::SIEVE    => array(
                'keys'      => array('hostname', 'port', 'ssl'),
                'defaults'  => array('port' => 2000, 'ssl' => Felamimail_Model_Account::SECURE_NONE),
            ),
        );
        
        foreach ($configs as $configKey => $values) {
            try {
                $this->_addConfigValuesToAccount($_account, $configKey, $values['keys'], $values['defaults']);
            } catch (Felamimail_Exception $fe) {
                Tinebase_Core::getLogger()->crit(__METHOD__ . '::' . __LINE__ . ' Could not get system account config values: ' . $fe->getMessage());
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' ' . $fe->getTraceAsString());
            }
        }

        if (null === $_user || $_user->getId() !== $_account->user_id) {
            if (Tinebase_Core::getUser()->getId() === $_account->user_id) {
                $_user = Tinebase_Core::getUser();
            } else {
                $_user = Tinebase_User::getInstance()->getUserById($_account->user_id);
            }
        }
        
        $this->_addUserValues($_account, $_user);
    }
    
    /**
     * add config values to account
     * 
     * @param Felamimail_Model_Account $_account
     * @param string $_configKey for example Tinebase_Config::IMAP for imap settings 
     * @param array $_keysOverwrite keys to overwrite
     * @param array $_defaults
     */
    protected function _addConfigValuesToAccount(Felamimail_Model_Account $_account, $_configKey, $_keysOverwrite = array(), $_defaults = array())
    {
        try {
            $fullUser = Tinebase_User::getInstance()->getFullUserById($_account->user_id);
        } catch (Tinebase_Exception_NotFound $tenf) {
            throw new Felamimail_Exception('User not found: ' . $_account->user_id, null, $tenf);
        }
        
        switch ($_configKey) {
            case Tinebase_Config::IMAP:
                $imapUser = $fullUser->imapUser;
                
                if (!$imapUser) {
                    throw new Felamimail_Exception('Invalid config found for ' . $_configKey);
                }
                foreach (array(
                    'emailUsername' => 'user',
                    'emailHost'     => 'host',
                    'emailPort'     => 'port',
                    'emailSecure'   => 'ssl'
                ) as $key => $value) {
                    if ($imapUser->$key) {
                        $_account->$value = $imapUser->$key;
                    }
                }
                
                break;
                
            case Tinebase_Config::SMTP:
                $smtpUser = $fullUser->smtpUser;
                
                if (!$smtpUser) {
                    throw new Felamimail_Exception('Invalid config found for ' . $_configKey);
                }
                
                foreach (array(
                    'emailUsername' => 'smtp_user',
                    'emailHost'     => 'smtp_hostname',
                    'emailPort'     => 'smtp_port',
                    'emailSecure'   => 'smtp_ssl',
                    'emailAuth'     => 'smtp_auth'
                ) as $key => $value) {
                    if ($smtpUser->$key) {
                        $_account->$value = $smtpUser->$key;
                    }
                }
                
                break;
                
            case Tinebase_Config::SIEVE:
                $config = Tinebase_Config::getInstance()->get($_configKey, new Tinebase_Config_Struct($_defaults))->toArray();
                $prefix = strtolower($_configKey) . '_';
                
                if (! is_array($config)) {
                    throw new Felamimail_Exception('Invalid config found for ' . $_configKey);
                }
                
                foreach ($config as $key => $value) {
                    if (in_array($key, $_keysOverwrite) && ! empty($value)) {
                        $_account->{$prefix . $key} = $value;
                    }
                }
                
                break;
        }
    }
    
    /**
     * add user account/contact data
     * 
     * @param Felamimail_Model_Account $_account
     * @param Tinebase_Model_User $_user
     * @param string $_email
     * @return void
     */
    protected function _addUserValues(Felamimail_Model_Account $_account, Tinebase_Model_User $_user, $_email = NULL)
    {
        if ($_email === NULL) {
            $_email = $this->_getAccountEmail($_user);
        }
        
        // add user data
        $_account->email  = $_email;
        $_account->name   = $_email;
        $_account->from   = $_user->accountFullName;
        
        // add contact data (if available)
        try {
            $contact = Addressbook_Controller_Contact::getInstance()->getContactByUserId($_user->getId(), TRUE);
            $_account->organization = $contact->org_name;
        } catch (Addressbook_Exception_NotFound $aenf) {
            Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__ . ' Could not get system account user contact: ' . $aenf->getMessage());
        }
    }

    /**
     * Returns a set of records identified by their id's
     *
     * @param   array $_ids array of record identifiers
     * @param   bool $_ignoreACL don't check acl grants
     * @param Tinebase_Record_Expander $_expander
     * @param   bool $_getDeleted
     * @return Tinebase_Record_RecordSet of $this->_modelName
     */
    public function getMultiple($_ids, $_ignoreACL = false, Tinebase_Record_Expander $_expander = null, $_getDeleted = false)
    {
        // TODO fix me! system account resolving is mssing, add it here?!
        throw new Tinebase_Exception_NotImplemented('do not use this function');
    }
}
