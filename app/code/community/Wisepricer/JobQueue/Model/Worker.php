<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2015 Jordan Owens <jkowens@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

set_include_path(get_include_path().PS.Mage::getBaseDir('lib').DS.'DJJob');

require_once('DJJob.php');

class Wisepricer_JobQueue_Model_Worker extends Mage_Core_Model_Abstract
{
    const DEFAULT_QUEUE = 'default';

    private $workerName;
    private $queue;
            
    public function __construct() {
        list($hostname, $pid) = array(trim(`hostname`), getmypid());
        $this->workerName = "host::$hostname pid::$pid";
        $this->queue = Mage::getStoreConfig('jobqueue/config/queue');
        if(empty($this->queue)) {
            $this->queue = self::DEFAULT_QUEUE; 
        }
    }

    public function getQueue() {
        return $this->queue;
    }

    public function setQueue($queue) {
        $this->queue = $queue;
    }

    public function getWorkerName() {
        return $this->workerName;
    }
    
    public function executeJobs($schedule=null) {
        if(!Mage::getStoreConfig('jobqueue/config/enabled')) {
            return;
        }

        if($schedule) {
            $jobsRoot = Mage::getConfig()->getNode('crontab/jobs');
            $jobConfig = $jobsRoot->{$schedule->getJobCode()};
            $queue = $jobConfig->queue;
            if($queue) {
                $this->setQueue($queue);
            }
        }

        $this->setupDJJob();

        try {
            $collection = Mage::getModel('jobqueue/job')->getCollection();
            $collection->addFieldToFilter('queue', array('eq' => $this->getQueue()))
            ->addFieldToFilter('run_at', array(
                array('null' => true),
                array('lteq' => now())
                ))
            ->addFieldToFilter(array('locked_at', 'locked_by'), array(
                array('locked_at', 'null' => true),
                array('locked_by', 'eq' => $this->workerName)               
                ))              
            ->addFieldToFilter('failed_at', array('null' => true))
            ->addFieldToFilter('attempts', array('lt' => (int)Mage::getStoreConfig('jobqueue/config/max_attempts')));

            // randomly order to prevent lock contention among workers
            $collection->getSelect()->order(new Zend_Db_Expr('RAND()'));
            $collection->load();

            foreach($collection as $row) {
                $job = new DJJob($this->workerName, $row->getId(), array(
                    "max_attempts" => Mage::getStoreConfig('jobqueue/config/max_attempts')
                    ));
                if ($job->acquireLock()) {
                    $job->run();
                }
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    protected function setupDJJob() {
        $config  = Mage::getConfig()->getResourceConnectionConfig("default_setup");
        
        $dsn = "";
        if (strpos($config->host, '/') !== false) {
            $dsn = "mysql:unix_socket=" . $config->host . ";dbname=" . $config->dbname;
        } else {
            $dsn = "mysql:host=" . $config->host . ";dbname=" . $config->dbname . ";port=" . $config->port;
        } 

        DJJob::configure(
            $dsn, 
            array('mysql_user' => $config->username, 'mysql_pass' => $config->password),
            Mage::getSingleton('core/resource')->getTableName('jobqueue/job')
            );

        if(!empty($config->initStatements)) {
            DJJob::runQuery($config->initStatements);
        }
    }
}
