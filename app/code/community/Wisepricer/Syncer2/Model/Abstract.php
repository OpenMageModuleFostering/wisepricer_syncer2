<?php

abstract class Wisepricer_Syncer2_Model_Abstract extends Wisepricer_JobQueue_Model_Job_Abstract
{
    const REMOTE_SUCCESS_CODE = 1;

    const REMOTE_FAILURE_CODE = 500;

    public function perform()
    {
        Mage::app()->setCurrentStore($this->getStoreId());
    }

    /**
     * Enqueue job
     *
     * @param string $queue
     * @param null $run_at
     */
    public function enqueue($queue = "default", $run_at = null)
    {
        $this->validateNotRunning($queue);

        $this->getHelper()->log(sprintf(
            "Job '%s' (ID: '%s') added to queue. Data:\n%s",
            get_class($this),
            $this->getName(),
            print_r($this->getData(), true)
        ));

        parent::enqueue($queue, $run_at);
    }

    /**
     * After max attempts running this method.
     *
     * @param $error
     */
    public function _onDjjobRetryError($error)
    {
        $this->callbackRequest(self::REMOTE_FAILURE_CODE, $error);
    }

    /**
     * Validate that job not running now.
     *
     * @param $queue
     * @throws Mage_Core_Exception
     */
    protected function validateNotRunning($queue)
    {
        $collection = Mage::getModel('jobqueue/job')->getCollection();
        $collection->addFieldToFilter('queue', array('eq' => $queue))
            ->addFieldToFilter('store_id', array('eq' => $this->getStoreId()))
            ->addFieldToFilter('failed_at', array('null' => true))
            ->addFieldToFilter('attempts', array('lt' => (int)Mage::getStoreConfig('jobqueue/config/max_attempts')));

        if (count($collection->getItems()) > 0) {
            $this->getHelper()->exception('Can\'t enqueue job, job running.');
        }
    }

    /**
     * @param int $code
     * @param null $message
     * @param array $data
     * @return Zend_Http_Response
     * @throws Exception
     */
    protected function callbackRequest($code = self::REMOTE_SUCCESS_CODE, $message = null, $data = array())
    {
        $token  = $this->getHelper()->getToken();
        $defaults = array('token' => $token, 'job_id' => $this->getName(), 'magento_store_id' => $this->getStoreId());
        $params = array('code' => $code, 'error_message' => $message) + $defaults + $data;

        $this->getHelper()->log(sprintf(
            "Making request to %s with params\n%s",
            $this->getCallbackUrl(),
            print_r($params, true)
        ));

        try {

            $httpClient = new Varien_Http_Client($this->getCallbackUrl());
            $httpClient->setMethod(Varien_Http_Client::POST);
            $httpClient->setParameterGet('token', $token);
            $httpClient->setRawData(json_encode($params), 'application/json');
            $httpClient->setHeaders(Varien_Http_Client::CONTENT_TYPE, 'application/json; charset=utf-8');
            $httpClient->setHeaders(array('Accept' => 'application/json'));

            $response = $httpClient->request();

        } catch (Exception $e) {
            $this->getHelper()->exception("Failed make callback request: " . $e->getMessage());
        }

        if ($response->isError()) {
            $this->getHelper()->exception(
                sprintf("Got error status '%s' and body: %s", $response->getStatus(), $response->getBody())
            );
        }

        if ($jsonResponse = json_decode($response->getBody(), true)) {

            if (empty($jsonResponse['code']) || $jsonResponse['code'] != 200) {
                $this->getHelper()->exception(sprintf(
                    "Got error in response from callback server '%s'",
                    isset($jsonResponse['message']) ? $jsonResponse['message'] : 'EMPTY MESSAGE'
                ));
            }

        } else {
            $this->getHelper()->exception(sprintf(
                "Error on json decode. Error code: %s\nResponse: '%s'",
                json_last_error(),
                $response->getBody()
            ));
        }

    }

    /**
     * @return Wisepricer_Syncer2_Helper_Data
     */
    protected function getHelper()
    {
        return Mage::helper('wisepricer_syncer2');
    }
}
