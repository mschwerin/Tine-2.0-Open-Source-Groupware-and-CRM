<?php
/**
 * Tine 2.0
 * 
 * @package     CRM
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Thomas Wadewitz <t.wadewitz@metaways.de>
 * @copyright   Copyright (c) 2007-2008 Metaways Infosystems GmbH (http://www.metaways.de)
 * @version     $Id$
 *
 */

/**
 * class to hold contact data
 * 
 * @package     CRM
 * @todo		check if one contact class (in Addressbook) is enough
 */
class Crm_Model_Contact extends Tinebase_Record_Abstract
{
    /**
     * key in $_validators/$_properties array for the filed which 
     * represents the identifier
     * 
     * @var string
     */    
    protected $_identifier = 'id';
    
    /**
     * application the record belongs to
     *
     * @var string
     */
    protected $_application = 'Crm';
    
    /**
     * list of zend inputfilter
     * 
     * this filter get used when validating user generated content with Zend_Input_Filter
     *
     * @var array
     */
    protected $_filters = array(
        '*'                     => 'StringTrim'
    );
    
    /**
     * list of zend validator
     * 
     * this validators get used when validating user generated content with Zend_Input_Filter
     *
     * @var array
     */
    protected $_validators = array(
        'link_id'               => array(Zend_Filter_Input::ALLOW_EMPTY => true, Zend_Filter_Input::DEFAULT_VALUE => NULL),
        'link_remark'           => array(Zend_Filter_Input::ALLOW_EMPTY => false),         
        'contact_id'            => array(Zend_Filter_Input::ALLOW_EMPTY => false),
        'owner'         => array(Zend_Filter_Input::ALLOW_EMPTY => true),   
        'n_family'              => array(Zend_Filter_Input::ALLOW_EMPTY => true),      
        'n_given'               => array(Zend_Filter_Input::ALLOW_EMPTY => true),     
        'n_middle'              => array(Zend_Filter_Input::ALLOW_EMPTY => true),      
        'n_prefix'              => array(Zend_Filter_Input::ALLOW_EMPTY => true),    
        'n_suffix'              => array(Zend_Filter_Input::ALLOW_EMPTY => true),      
        'n_fn'                  => array(Zend_Filter_Input::ALLOW_EMPTY => true),      
        'n_fileas'              => array(Zend_Filter_Input::ALLOW_EMPTY => true),      
        'org_name'              => array(Zend_Filter_Input::ALLOW_EMPTY => true),    
        'org_unit'              => array(Zend_Filter_Input::ALLOW_EMPTY => true),    
        'adr_one_street'        => array(Zend_Filter_Input::ALLOW_EMPTY => true),    
        'adr_one_locality'      => array(Zend_Filter_Input::ALLOW_EMPTY => true),      
        'adr_one_region'        => array(Zend_Filter_Input::ALLOW_EMPTY => true),    
        'adr_one_postalcode'    => array(Zend_Filter_Input::ALLOW_EMPTY => true),    
        'adr_one_countryname'   => array(Zend_Filter_Input::ALLOW_EMPTY => true),
        'tel_work'              => array(Zend_Filter_Input::ALLOW_EMPTY => true),
        'tel_cell'              => array(Zend_Filter_Input::ALLOW_EMPTY => true),
        'tel_fax'               => array(Zend_Filter_Input::ALLOW_EMPTY => true),
        'email'         => array(Zend_Filter_Input::ALLOW_EMPTY => true)         
    );

}