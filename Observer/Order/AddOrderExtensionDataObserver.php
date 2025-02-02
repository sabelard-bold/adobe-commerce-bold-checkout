<?php
declare(strict_types=1);

namespace Bold\Checkout\Observer\Order;

use Bold\Checkout\Model\Order\OrderExtensionDataFactory;
use Bold\Checkout\Model\ResourceModel\Order\OrderExtensionData;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Add bold data to magento order observer.
 */
class AddOrderExtensionDataObserver implements ObserverInterface
{
    /**
     * @var OrderExtensionDataFactory
     */
    private $orderExtensionDataFactory;

    /**
     * @var OrderExtensionData
     */
    private $orderExtensionDataResource;

    /**
     * @var array
     */
    private $orderExtensionData = [];

    /**
     * @var EventManagerInterface
     */
    private $eventManager;

    /**
     * @param OrderExtensionDataFactory $orderExtensionDataFactory
     * @param OrderExtensionData $orderExtensionDataResource
     * @param EventManagerInterface $eventManager
     */
    public function __construct(
        OrderExtensionDataFactory $orderExtensionDataFactory,
        OrderExtensionData $orderExtensionDataResource,
        EventManagerInterface $eventManager
    ) {
        $this->orderExtensionDataFactory = $orderExtensionDataFactory;
        $this->orderExtensionDataResource = $orderExtensionDataResource;
        $this->eventManager = $eventManager;
    }

    /**
     * Add bold order data to magento order.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getOrder();
        $orderExtensionData = $this->orderExtensionData[$order->getId()] ?? null;
        if (!$orderExtensionData) {
            $orderExtensionData = $this->orderExtensionDataFactory->create();
            $this->orderExtensionDataResource->load($orderExtensionData, $order->getId(), OrderExtensionData::ORDER_ID);
            $this->orderExtensionData[$order->getId()] = $orderExtensionData;
        }
        $order->getExtensionAttributes()->setPublicId($orderExtensionData->getPublicId());
        $order->getExtensionAttributes()->setFulfillmentStatus($orderExtensionData->getFulfillmentStatus());
        $order->getExtensionAttributes()->setFinancialStatus($orderExtensionData->getFinancialStatus());

        $this->eventManager->dispatch(
            'checkout_add_extension_data_to_order_after',
            ['order' => $order, 'orderExtensionData' => $orderExtensionData]
        );
    }
}
