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

abstract class Wisepricer_JobQueue_Model_Job_Abstract extends Mage_Core_Model_Abstract
{
  private $name;
  private $storeId;

  public function __construct($name=null) {
    $this->name = $name ? $name : $this->getType();
    $this->setStoreId(Mage::app()->getStore()->getStoreId());
  }

  public abstract function perform();

  public function performImmediate($retryQueue="default") {
    try {
      $this->perform();
    } catch(Exception $e) {
      $this->enqueue($retryQueue);
      Mage::logException($e);
    }
  }

  public function enqueue($queue="default", $run_at=null) {
    $job = Mage::getModel('jobqueue/job');
    $job->setStoreId($this->getStoreId());
    $job->setName($this->getName());
    $job->setHandler(serialize($this));
    $job->setQueue($queue);
    $job->setRunAt($run_at);
    $job->setCreatedAt(now());
    $job->save();
  }

  public function setName($name) 
  {
    $this->name = $name;
    return $this;
  }

  public function getName() 
  {
    return $this->name;
  }

  public function setStoreId($storeId) 
  {
    $this->storeId = $storeId;
    return $this;
  }

  public function getStoreId() 
  {
    return $this->storeId;
  }	

  public function getType() 
  {
    return get_class($this);
  }
}
