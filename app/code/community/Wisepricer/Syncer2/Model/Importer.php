<?php

require_once(Mage::getModuleDir('', 'Wisepricer_Syncer2') . DS . 'Model' . DS . 'Abstract.php');

class Wisepricer_Syncer2_Model_Importer extends Wisepricer_Syncer2_Model_Abstract
{

    /**
     * Downloads CSV file then running on it and repricing products.
     *
     * @throws Exception
     */
    public function perform()
    {
        parent::perform();

        try {

            $data = array();
            $fileName = $this->getName() . '.csv';
            $localFile = $this->getHelper()->getImportPath($fileName);

            if (!file_exists($localFile)) {
                $this->downloadFile($this->getFileUrl(), $localFile);
            }

            $failedProducts = $this->performRepriceFromCSV($localFile);

            if ($this->getReindexRequired()) {
                Mage::getModel('index/indexer')->getProcessByCode('catalog_product_price')->reindexAll();
                Mage::getModel('index/indexer')->getProcessByCode('catalog_product_attribute')->reindexAll();
            }

            if (!empty($failedProducts)) {
                $data['failed_products'] = array();

                foreach ($failedProducts as $sku => $product) {
                    $data['failed_products'][] = array(
                        'sku' => $sku,
                        'error_code' => 404,
                        'error_message' => 'Product not found.',
                    );
                }
            }

            $this->callbackRequest(static::REMOTE_SUCCESS_CODE, null, $data);

        } catch (Exception $e) {
            $this->getHelper()->log("Failed on import: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Download file from remote to local server.
     *
     * @param $remoteFile
     * @param $localFile
     * @throws Mage_Core_Exception
     */
    protected function downloadFile($remoteFile, $localFile)
    {
        $this->getHelper()->log("Downloading file: $remoteFile");

        $tmpFile = $localFile . '.tmp';
        $tmpPath = pathinfo($tmpFile, PATHINFO_DIRNAME);
        $remoteUrl = $remoteFile . '?' . http_build_query(array(
            'token' => $this->getHelper()->getToken(),
            'job_id' => $this->getName(),
            'magento_store_id' => $this->getStoreId()
        ));

        if (!file_exists($tmpPath) && !mkdir($tmpPath, 0777, true)) {
            $this->getHelper()->exception("Can't create directory: $tmpPath. " . error_get_last());
        }

        if (false === ($fh = fopen($remoteUrl, 'r'))) {
            $this->getHelper()->exception("Can't open stream: $remoteUrl. " . error_get_last());
        }

        if (!file_put_contents($tmpFile, $fh)) {
            $this->getHelper()->exception("Can't write to file: $tmpFile. " . error_get_last());
        }

        if (!rename($tmpFile, $localFile)) {
            $this->getHelper()->exception("Can't rename file $tmpFile to $localFile. " . error_get_last());
        }

        $this->getHelper()->log("File saved at: $localFile");
    }

    /**
     * Reprice products accodring to CSV
     *
     * @param $csvFile
     * @throws Exception
     */
    protected function performRepriceFromCSV($csvFile)
    {
        $this->getHelper()->log("Starting reprice.");
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

        $productsData = $this->prepareProductsForReprice($csvFile);
        $failedProducts = $productsData;

        /**
         * @var $product Mage_Catalog_Model_Product
         */
        $product = Mage::getModel('catalog/product');

        $connection = $product->getResource()->getWriteConnection();
        $connection->beginTransaction();
        try {

            /**
             * @var $collection Mage_Catalog_Model_Resource_Product_Collection
             */
            $collection = $product->getCollection()
                ->setStoreId($this->getStoreId())
                ->addAttributeToFilter( 'sku', array( 'in' => array_keys($productsData)));

            //Add a page size to the result set.
            $collection->setPageSize(100);

            //discover how many page the result will be.
            $pages = $collection->getLastPageNumber();

            $currentPage = 1;

            do {

                $collection->setCurPage($currentPage);
                $collection->load();

                /**
                 * @var $item Mage_Catalog_Model_Product
                 */
                foreach ($collection as $item) {
                    $sku = $item->getSku();

                    if (isset($productsData[$sku])) {
                        foreach ($productsData[$sku] as $attributeCode => $attributeValue) {
                            $item->setStoreId($this->getStoreId())
                                ->setData($attributeCode, $attributeValue)
                                ->getResource()
                                ->saveAttribute($item, $attributeCode);
                        }
                    }

                    unset($failedProducts[$sku]);
                }

                $currentPage++;

                //make the collection unload the data in memory so it will pick up the next page when load() is called.
                $collection->clear();

            } while ($currentPage <= $pages);

            $connection->commit();
        } catch (Exception $e) {
            $connection->rollBack();
            throw $e;
        }

        Mage::app()->setCurrentStore($this->getStoreId());
        $this->getHelper()->log("Finished reprice.");

        return $failedProducts;
    }

    /**
     * Read csv file and prepares array for future reprice.
     *
     * @param $csvFile
     * @return array
     * @throws Exception
     * @throws Mage_Core_Exception
     */
    protected function prepareProductsForReprice($csvFile)
    {
        $this->getHelper()->log("Reading downloaded CSV file: $csvFile");
        $products = array();

        $io = new Varien_Io_File();
        $io->streamOpen($csvFile, 'r');

        $line = 1;
        $attributes = $this->getAttributesFromCsvFile($io);
        while ($csvData = $io->streamReadCsv()) {
            $sku  = $csvData[$attributes['sku']];

            if (isset($products[$sku])) {
                $io->streamClose();
                $this->getHelper()->exception("Dublicate sku '$sku' found in ".basename($csvFile).":$line");
            }

            $data = array();
            foreach ($attributes as $attributeCode => $index) {
                if ($attributeCode === 'sku') {
                    continue;
                }
                $data[$attributeCode] = $csvData[$index];
            }

            $products[$sku] = $data;

            $line++;
        }

        $io->streamClose();
        $this->getHelper()->log("Finished reading downloaded CSV file: $csvFile");

        return $products;
    }

    /**
     * @param Varien_Io_File $io
     * @return array
     * @throws Wisepricer_Syncer2_Exception
     */
    protected function getAttributesFromCsvFile(Varien_Io_File $io)
    {
        $attributes = array();

        if (($headers = $io->streamReadCsv()) && !empty($headers)) {
            foreach ($headers as $index => $attribute) {
                $this->getHelper()->checkAttributeExists($attribute);
                $attributes[$attribute] = $index;
                //$attributes[$index] = $attribute;
            }
        } else {
            $io->streamClose();
            $this->getHelper()->exception('Can\'t read CSV headers from file.');
        }

        // if (array_search('sku', $attributes)) {
        if (!isset($attributes['sku'])) {
            $io->streamClose();
            $this->getHelper()->exception('Missing "sku" in CSV headers.');
        }

        return $attributes;
    }

}
