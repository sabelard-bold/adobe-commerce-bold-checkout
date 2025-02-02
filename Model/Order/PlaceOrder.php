<?php
declare(strict_types=1);

namespace Bold\Checkout\Model\Order;

use Bold\Checkout\Api\Data\Http\Client\Response\ErrorInterfaceFactory;
use Bold\Checkout\Api\Data\PlaceOrder\Request\OrderDataInterface;
use Bold\Checkout\Api\Data\PlaceOrder\ResultInterface;
use Bold\Checkout\Api\Data\PlaceOrder\ResultInterfaceFactory;
use Bold\Checkout\Api\PlaceOrderInterface;
use Bold\Checkout\Model\ConfigInterface;
use Bold\Checkout\Model\Http\Client\Request\Validator\OrderPayloadValidator;
use Bold\Checkout\Model\Http\Client\Request\Validator\ShopIdValidator;
use Bold\Checkout\Model\Order\PlaceOrder\CreateOrderFromPayload;
use Bold\Checkout\Model\Order\PlaceOrder\ProcessOrder;
use Bold\Checkout\Model\Order\PlaceOrder\Progress;
use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Place magento order with bold payment service.
 */
class PlaceOrder implements PlaceOrderInterface
{
    /**
     * @var ResultInterfaceFactory
     */
    private $responseFactory;

    /**
     * @var ErrorInterfaceFactory
     */
    private $errorFactory;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var ShopIdValidator
     */
    private $shopIdValidator;

    /**
     * @var OrderPayloadValidator
     */
    private $orderPayloadValidator;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var CreateOrderFromPayload
     */
    private $createOrderFromPayload;

    /**
     * @var ProcessOrder
     */
    private $processOrder;

    /**
     * @var Progress
     */
    private $progress;

    /**
     * @param ShopIdValidator $shopIdValidator
     * @param OrderPayloadValidator $orderPayloadValidator
     * @param CartRepositoryInterface $cartRepository
     * @param ResultInterfaceFactory $responseFactory
     * @param ErrorInterfaceFactory $errorFactory
     * @param ConfigInterface $config
     * @param CreateOrderFromPayload $createOrderFromPayload
     * @param ProcessOrder $processOrder
     * @param Progress $progress
     */
    public function __construct(
        ShopIdValidator $shopIdValidator,
        OrderPayloadValidator $orderPayloadValidator,
        CartRepositoryInterface $cartRepository,
        ResultInterfaceFactory $responseFactory,
        ErrorInterfaceFactory $errorFactory,
        ConfigInterface $config,
        CreateOrderFromPayload $createOrderFromPayload,
        ProcessOrder $processOrder,
        Progress $progress
    ) {
        $this->responseFactory = $responseFactory;
        $this->errorFactory = $errorFactory;
        $this->cartRepository = $cartRepository;
        $this->shopIdValidator = $shopIdValidator;
        $this->orderPayloadValidator = $orderPayloadValidator;
        $this->config = $config;
        $this->createOrderFromPayload = $createOrderFromPayload;
        $this->processOrder = $processOrder;
        $this->progress = $progress;
    }

    /**
     * @inheritDoc
     */
    public function place(string $shopId, OrderDataInterface $order): ResultInterface
    {
        if ($this->progress->isInProgress($order)) {
            return $this->getValidationErrorResponse(
                __('Order for cart id: "%1" already in progress.', $order->getQuoteId())->render()
            );
        }
        $this->progress->start($order);
        try {
            $this->orderPayloadValidator->validate($order);
            $quote = $this->cartRepository->get($order->getQuoteId());
            $this->shopIdValidator->validate($shopId, $quote->getStoreId());
        } catch (LocalizedException $e) {
            $this->progress->stop($order);
            return $this->getValidationErrorResponse($e->getMessage());
        }
        try {
            $websiteId = (int)$quote->getStore()->getWebsiteId();
            $magentoOrder = $this->config->isCheckoutTypeSelfHosted($websiteId)
                ? $this->processOrder->process($order)
                : $this->createOrderFromPayload->createOrder($order, $quote);
        } catch (Exception $e) {
            $this->progress->stop($order);
            return $this->getErrorResponse($e->getMessage());
        }
        $this->progress->stop($order);
        return $this->getSuccessResponse($magentoOrder);
    }

    /**
     * Build validation error response.
     *
     * @param string $message
     * @return ResultInterface
     */
    private function getValidationErrorResponse(string $message): ResultInterface
    {
        return $this->responseFactory->create(
            [
                'errors' => [
                    $this->errorFactory->create(
                        [
                            'message' => $message,
                            'code' => 422,
                            'type' => 'server.validation_error',
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * Build error response.
     *
     * @param string $message
     * @return ResultInterface
     */
    private function getErrorResponse(string $message): ResultInterface
    {
        return $this->responseFactory->create(
            [
                'errors' => [
                    $this->errorFactory->create(
                        [
                            'message' => $message,
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * Build order response
     *
     * @param OrderInterface $order
     * @return ResultInterface
     */
    private function getSuccessResponse(OrderInterface $order): ResultInterface
    {
        $this->processOrderItems($order);
        return $this->responseFactory->create(
            [
                'order' => $order,
            ]
        );
    }

    /**
     * @param OrderInterface $order
     * @return void
     */
    private function processOrderItems(OrderInterface $order)
    {
        $items = [];
        foreach ($order->getAllItems() as $item) {
            if (!$item->getChildren()) {
                $items[] = $item;
            }
        }
        foreach ($items as $orderItem) {
            $orderItem->getExtensionAttributes()->setProduct($orderItem->getProduct());
        }
        $order->setItems($items);
    }
}
