<?php

class Wisepricer_Syncer2_ApiController extends Mage_Core_Controller_Front_Action
{
    CONST RESPONSE_MESSAGE_SUCCESS    = null;

    CONST RESPONSE_MESSAGE_FAILURE    = 'Failure';

    CONST RESPONSE_MESSAGE_BADREQUEST = 'Wrong Request';

    CONST RESPONSE_MESSAGE_TOKEN_MISS = 'Missing authorization token.';

    CONST RESPONSE_MESSAGE_FORBIDDEN  = 'Wrong authorization token.';

    CONST RESPONSE_CODE_SUCCESS       = 1;

    CONST RESPONSE_CODE_FAILURE       = 500;

    CONST RESPONSE_CODE_BADREQUEST    = 400;

    CONST RESPONSE_CODE_TOKEN_MISS    = 401;

    CONST RESPONSE_CODE_FORBIDDEN     = 403;

    CONST DEBUG_GET_LOG_FILE = 'LOGFILE';

    protected $_processInstance = null;

    protected $jsonParams = null;

    /**
     * Token authorization
     */
    public function preDispatch()
    {
        if ($token = $this->getParam('token')) {

            if ($token !== $this->getHelper()->getToken()) {
                $this->response(null, self::RESPONSE_CODE_FORBIDDEN, self::RESPONSE_MESSAGE_FORBIDDEN);
                $this->setFlag('', self::FLAG_NO_DISPATCH, true);
            }

        } else {
            $this->response(null, self::RESPONSE_CODE_TOKEN_MISS, self::RESPONSE_MESSAGE_TOKEN_MISS);
            $this->setFlag('', self::FLAG_NO_DISPATCH, true);
        }

        parent::preDispatch();
        return $this;
    }

    /**
     * Retrieves the current website data, helps to check if the website is available
     */
    public function pingAction()
    {
        try {

            $website = Mage::app()->getWebsite();

            $this->response(array(
                'website_id'        => $website->getCode(),
                'website_name'      => $website->getName(),
                'website_url'       => $website->getDefaultStore()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB),
            ));

        } catch (Exception $e) {
            $this->response(null, self::RESPONSE_CODE_FAILURE, $e->getMessage());
        }
    }
    
    /**
     * Retrieves the list of available websites data.
     */
    public function getWebsitesAction()
    {
        $websites = array();

        try {

            /**
             * @var $website Mage_Core_Model_Website
             */
            foreach (Mage::app()->getWebsites() as $website) {

                $stores = array();

                /**
                 * @var $store Mage_Core_Model_Store
                 */
                foreach ($website->getStores() as $store) {
                    $stores[] = array(
                        'store_name'   => $store->getGroup()->getName() . ' ' . $store->getName(),
                        'store_code'   => $store->getCode(),
                        'store_id'     => $store->getId(),
                        'store_url'    => $store->getBaseUrl(),
                    );
                }

                $websites[] = array(
                    'website_name'  => $website->getName(),
                    'website_code'  => $website->getCode(),
                    'website_id'    => $website->getId(),
                    'default_store' => $website->getDefaultStore()->getId(),
                    'stores'        => $stores,
                );

            }
            
            $this->response($websites);

        } catch (Exception $e) {
            $this->response(null, self::RESPONSE_CODE_FAILURE, $e->getMessage());
        }
    }
 
    /**
     * Retrieves the list of product attributes available in the system
     */
    public function getAttributesAction()
    {
        try {

            $attributesCollection = Mage::getResourceModel('catalog/product_attribute_collection')
                ->setOrder('main_table.frontend_label', 'ASC')
                ->addFieldToFilter('main_table.frontend_label', array('neq' => 'NULL' ))
                ->getItems();

            $data = array();
            foreach ($attributesCollection as $attribute) {
                $data[] = array(
                    'attribute_code'          => $attribute->getAttributeCode(),
                    'attribute_label'         => $attribute->getFrontendLabel(),
                    'attribute_type'          => $attribute->getFrontendInput(),
                    'attribute_default_value' => $attribute->getDefaultValue(),
                );
            }

            $this->response($data);

        } catch (Exception $e) {
            $this->response(null, self::RESPONSE_CODE_FAILURE, $e->getMessage());
        }
    }
    
    /**
     * When called, processes the File Export scheduler or returns currents Status 
     * and current Progress in response;
     */
    public function exportFileAction()
    {
        $errors = array();
        $params = $this->getJsonParams();

        if (!$this->getRequest()->isPost()) {
            $errors[] = 'Request method should be POST.';
        }

        if (empty($params['store_id'])) {
            $errors[] = "Param 'store_id' required and should be valid website ID.";
        }

        if (empty($params['attributes']) || !is_array($params['attributes'])) {
            $errors[] = "Param 'attributes' required and should be not empty array.";
        } else {
            foreach ($params['attributes'] as $attribute) {
                if (!$this->getHelper()->checkAttributeExists($attribute, false)) {
                    $errors[] = sprintf('Attribute with code "%s" does not exists', $attribute);
                }
            }
        }

        if (empty($params['callback_url'])) {
            $errors[] = "Param 'callback_url' required.";
        }

        if (!empty($params['exclude_attribute']) && !$this->getHelper()->checkAttributeExists($params['exclude_attribute'], false)) {
            $errors[] = sprintf('Exclude attribute with code "%s" does not exists', $params['exclude_attribute']);
        }

        if (!empty($params['product_type_filter'])) {
            $productTypes = Mage_Catalog_Model_Product_Type::getTypes();

            $productTypeFilter = explode(',', $params['product_type_filter']);
            foreach ($productTypeFilter as $productType) {
                if (!array_key_exists($productType, array_keys($productTypes))) {
                    $errors[] = sprintf("Product type '%s' not exists.", $productType);
                }
            }
        }

        if (empty($errors)) {

            try {

                $store = Mage::app()->getStore($params['store_id']);
                $jobId = uniqid($store->getCode(), true) . '_export';

                Mage::getModel('wisepricer_syncer2/exporter')
                    ->setStoreId($store->getId())
                    ->setName($jobId)
                    ->addData(array(
                        'attributes'          => $params['attributes'],
                        'callback_url'        => $params['callback_url'],
                        'enabled_only'        => !empty($params['enabled_only']) ? (bool) $params['enabled_only'] : null,
                        'product_type_filter' => !empty($params['product_type_filter']) ? explode(',', $params['product_type_filter']) : null,
                        'exclude_attribute'   => !empty($params['exclude_attribute']) ? $params['exclude_attribute'] : null,
                    ))
                    ->enqueue();

                $this->response(array('job_id' => $jobId));

            } catch (Exception $e) {
                $this->response(null, self::RESPONSE_CODE_FAILURE, $e->getMessage());
            }

        } else {
            $this->response(null, self::RESPONSE_CODE_BADREQUEST, implode(' ', $errors));
        }
    }

    /**
     * @throws Exception
     * @throws Zend_Validate_Exception
     */
    public function importFileAction()
    {
        $errors = array();
        $params = $this->getJsonParams();

        if (!$this->getRequest()->isPost()) {
            $errors[] = 'Request method should be POST.';
        }

        if (empty($params['store_id'])) {
            $errors[] = "Param 'store_id' required and should be valid website ID.";
        }

        if (empty($params['callback_url'])) {
            $errors[] = "Param 'callback_url' required.";
        }

        if (empty($params['file_url'])) {
            $errors[] = "Param 'file_url' required.";
        }

        if (empty($errors)) {

            try {

                $store = Mage::app()->getStore($params['store_id']);
                $jobId = uniqid($store->getCode(), true) . '_import';

                Mage::getModel('wisepricer_syncer2/importer')
                    ->setStoreId($store->getId())
                    ->setName($jobId)
                    ->addData(array(
                        'file_url'         => $params['file_url'],
                        'callback_url'     => $params['callback_url'],
                        'reindex_required' => isset($params['reindex_required']) ? $params['reindex_required'] : true,
                    ))
                    ->enqueue();

                $this->response(array('job_id' => $jobId));

            } catch (Exception $e) {
                $this->response(null, self::RESPONSE_CODE_FAILURE, $e->getMessage());
            }

        } else {
            $this->response(null, self::RESPONSE_CODE_BADREQUEST, implode(' ', $errors));
        }

    }

    /**
     * Exports file for wiser
     */
    public function exportAction()
    {
        if ($file = $this->getParam('file')) {

            try {

                $filePath = $this->getHelper()->getExportPath() . DS . basename($file);

                if ($file === self::DEBUG_GET_LOG_FILE) {
                    $filePath = $this->getHelper()->getLogPath();
                }

                if (!file_exists($filePath)) {
                    $this->getHelper()->exception("File for export not found: $file");
                }

                $this->_prepareDownloadResponse(basename($filePath), array(
                    'type' => 'filename',
                    'value' => $filePath,
                ));


            } catch (Exception $e) {
                $this->response(null, self::RESPONSE_CODE_FAILURE, $e->getMessage());
            }

        } else {
            $this->response(null, self::RESPONSE_CODE_BADREQUEST, "Param 'file' can't be empty.");
        }
    }

    /**
     * Response with JSON encoded message.
     * @param array  $data      Response data
     * @param int    $code      Response status code
     * @param string $response  Response message
     */
    protected function response($data = null, $code = self::RESPONSE_CODE_SUCCESS, $message = self::RESPONSE_MESSAGE_SUCCESS)
    {
        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody(json_encode(array(
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        )));
    }

    /**
     * Return array from json request
     * @return mixed|null
     */
    protected function getJsonParams()
    {
        if ($this->jsonParams === null) {
            $this->jsonParams = json_decode($this->getRequest()->getRawBody(), true);
        }

        return $this->jsonParams;
    }

    /**
     * Get param from json request or from get request.
     *
     * @param $key
     * @return mixed
     */
    protected function getParam($key)
    {
        $params = $this->getJsonParams();

        return isset($params[$key]) ? $params[$key] : $this->getRequest()->getParam($key, false);
    }

    /**
     * @return Wisepricer_Syncer2_Helper_Data
     */
    protected function getHelper()
    {
        return Mage::helper('wisepricer_syncer2');
    }

}