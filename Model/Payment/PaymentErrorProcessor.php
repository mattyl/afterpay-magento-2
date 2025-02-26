<?php declare(strict_types=1);

namespace Afterpay\Afterpay\Model\Payment;

use Afterpay\Afterpay\Gateway\ErrorMessageMapper\CaptureErrorMessageMapper;
use Afterpay\Afterpay\Model\Payment\Capture\CancelOrderProcessor;
use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class PaymentErrorProcessor
{
    public const ORDER_STATUS_CODE = 'afterpay_exception_review';
    private Session $checkoutSession;
    private OrderRepositoryInterface $orderRepository;
    private CancelOrderProcessor $cancelOrderProcessor;
    private LoggerInterface $logger;

    public function __construct(
        Session                  $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        CancelOrderProcessor     $cancelOrderProcessor,
        LoggerInterface          $logger
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->cancelOrderProcessor = $cancelOrderProcessor;
        $this->logger = $logger;
    }

    public function execute(Quote $quote, \Throwable $e, Payment $payment): int
    {
        $this->logger->critical('Order placement is failed with error: ' . PHP_EOL . $e);
        if (($this->checkoutSession->getLastSuccessQuoteId() == $quote->getId()) && $this->checkoutSession->getLastOrderId()) {
            try {
                $order = $this->orderRepository->get((int)$this->checkoutSession->getLastOrderId());
                $order->addCommentToStatusHistory(
                    'Afterpay detected a Magento exception during order creation. Please review:' . $e->getMessage(),
                    self::ORDER_STATUS_CODE,
                    false
                );
                $this->orderRepository->save($order);

                return (int)$order->getEntityId();
            } catch (NoSuchEntityException $e) {
            }
        }

        if ($e instanceof CommandException && $e->getMessage() === CaptureErrorMessageMapper::STATUS_DECLINED_ERROR_MESSAGE) {
            throw $e;
        }

        $this->cancelOrderProcessor->execute($payment, (int)$quote->getId());

        if ($e instanceof LocalizedException) {
            throw $e;
        }

        throw new LocalizedException(
            __(
                'There was a problem placing your order. Please make sure your Afterpay %1 order has been refunded.',
                $payment->getAdditionalInformation(AdditionalInformationInterface::AFTERPAY_ORDER_ID)
            )
        );
    }
}
