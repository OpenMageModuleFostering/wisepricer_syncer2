<?php

class Wisepricer_Syncer2_Helper_Data extends Mage_Core_Helper_Abstract
{
    const LOG_FILENAME = 'wisepricer_syncer2.log';

    const PATH_VAR_WS2 = 'ws2';
    const PATH_EXPORT  = 'export';
    const PATH_IMPORT  = 'import';

    const CONFIG_TOKEN = 'wisepricer_syncer2/authentication/token';

    protected $_allAttributesCollection = null;

    public function checkAttributeExists($attributeCode, $throwException = true)
    {
        if ($attributeCode) {

            /**
             * @var $_attribute Mage_Catalog_Model_Resource_Eav_Attribute
             */
            foreach ($this->getAllAttributesCollection()->getItems() as $_attribute) {
                if ($_attribute->getAttributeCode() === $attributeCode) {
                    return true;
                }
            }

        }

        if ($throwException) {
            $this->exception(sprintf('Attribute with code "%s" does not exists', $attributeCode));
        }

        return false;
    }

    public function getAllAttributesCollection()
    {
        if( is_null($this->_allAttributesCollection) ) {
            $this->_allAttributesCollection = Mage::getResourceModel('catalog/product_attribute_collection');
        }

        return $this->_allAttributesCollection;
    }

    public function getExportPath($baseFile = null)
    {
        return implode(DS, array(Mage::getBaseDir('var'), self::PATH_VAR_WS2, self::PATH_EXPORT, $baseFile));
    }

    public function getImportPath($baseFile = null)
    {
        return implode(DS, array(Mage::getBaseDir('var'), self::PATH_VAR_WS2, self::PATH_IMPORT, $baseFile));
    }

    public function getLogPath()
    {
        return implode(DS, array(Mage::getBaseDir('log'), self::LOG_FILENAME));
    }

    public function getToken()
    {
        return Mage::getStoreConfig(self::CONFIG_TOKEN);
    }

    /**
     * @param mixed $message
     * @param int   $level
     */
    public function log($message, $level = Zend_log::INFO)
    {
        $message = is_string($message) ? $message : print_r($message, true);

        Mage::log($message, $level, self::LOG_FILENAME);
    }

    /**
     * @param $message
     * @param int $code
     * @throws Mage_Core_Exception
     */
    public function exception($message, $code = 0)
    {
        $this->log('Exception thrown: ' . $message, Zend_Log::CRIT);
        throw Mage::exception('Wisepricer_Syncer2', $message, $code);
    }
}