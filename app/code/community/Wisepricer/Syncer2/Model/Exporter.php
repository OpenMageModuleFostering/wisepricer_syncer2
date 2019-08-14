<?php

require_once(Mage::getModuleDir('', 'Wisepricer_Syncer2') . DS . 'Model' . DS . 'Abstract.php');

class Wisepricer_Syncer2_Model_Exporter extends Wisepricer_Syncer2_Model_Abstract
{

    public function perform()
    {
        parent::perform();

        try {

            $fileName = $this->getName() . '.csv';
            $csvFilePath = $this->getHelper()->getExportPath($fileName);

            if (!file_exists($csvFilePath)) {
                $this->prepareCsvFile($csvFilePath);
            }

            $this->callbackRequest(self::REMOTE_SUCCESS_CODE, null, array(
                'file_url' => Mage::getUrl('wisesyncer2/api/export', array(
                    'token' => $this->getHelper()->getToken(),
                    'file'  => $fileName,
                ))
            ));

        } catch (Exception $e) {
            $this->getHelper()->log("Failed on export: " . $e->getMessage());
            throw $e;
        }
    }

    protected function prepareCsvFile($filePath)
    {
        $this->getHelper()->log("Creating CSV file: $filePath");

        $io = new Varien_Io_File();
        $attributes = $this->getData('attributes');
        $collection = $this->getFilteredCollection();
        $temporaryFile = pathinfo($filePath, PATHINFO_FILENAME) . '.tmp';

        $io->setAllowCreateFolders(true);
        $io->open(array('path' => pathinfo($filePath, PATHINFO_DIRNAME)));
        $io->streamOpen($temporaryFile, 'w+');
        $io->streamLock(true);

        if (!$io->streamWriteCsv($attributes)) {
            $this->getHelper()->exception("Failed on writing headers to CSV file.");
        }

        //Add a page size to the result set.
        $collection->setPageSize(100);

        //discover how many page the result will be.
        $pages = $collection->getLastPageNumber();

        $currentPage = 1;

        do {

            $collection->setCurPage($currentPage);
            $collection->load();

            foreach ($collection as $item) {
                $data = array();

                foreach ($attributes as $attributeName) {
                    $data[] = $item->getData($attributeName);
                }

                if (!$io->streamWriteCsv($data)) {
                    $this->getHelper()->exception("Failed on writing data to CSV file.");
                }
            }

            $currentPage++;

            //make the collection unload the data in memory so it will pick up the next page when load() is called.
            $collection->clear();

        } while ($currentPage <= $pages);

        $io->streamUnlock();
        $io->streamClose();

        if (!$io->mv($temporaryFile, $filePath)) {
            $this->getHelper()->exception("Failed on moving $temporaryFile to $filePath");
        }

        $this->getHelper()->log("File writing finished.");
    }

    protected function getFilteredCollection()
    {
        $attributes        = $this->getData('attributes');
        $enabledOnly       = $this->getData('enabled_only');
        $productTypeFilter = $this->getData('product_type_filter');
        $excludeAttribute  = $this->getData('exclude_attribute');

        /**
         * @var $collection Mage_Catalog_Model_Resource_Product_Collection
         */
        $collection = Mage::getModel('catalog/product')->getCollection()->addStoreFilter($this->getStoreId());

        /**
         * Adding attributes to select, but validating each attribute. If the attribute is specified, but
         * does not exists, we will get FATAL ERROR, the job will be interrupted, with no clue what happened.
         */
        if (is_array($attributes) && !empty($attributes)) {
            foreach ($attributes as $attributeCode) {
                if ($this->getHelper()->checkAttributeExists($attributeCode)) {
                    $collection->addAttributeToSelect($attributeCode);
                }
            }
        }

        if ($enabledOnly) {
            $collection->addFieldToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED);
        }

        if (is_array($productTypeFilter) && !empty($productTypeFilter)) {
            $collection->addAttributeToFilter('type_id', array('in' => $productTypeFilter));
        }

        if ($excludeAttribute && $this->getHelper()->checkAttributeExists($excludeAttribute)) {

            $collection->addAttributeToFilter(
                array(
                    array('attribute' => $excludeAttribute, 'neq' => 1),
                    array('attribute' => $excludeAttribute, 'null' => true),
                ), '', 'left'
            );
        }

        // die(print_r($collection->getSelect()->__toString(), true));

        return $collection;
    }

}