<?php
class Wisepricer_Syncer2_Model_Observer
{
    /**
     * Allow access to API Controller
     * @param Varien_Event_Observer $observer
     */
    public function skipWebsiteRestriction(Varien_Event_Observer $observer)
    {
        $event = $observer->getEvent();
        if ($event && $event->getController() instanceof Wisepricer_Syncer2_ApiController) {
            if ($result = $event->getResult()) {
                $result->setShouldProceed(false);
            }
        }
    }
}
