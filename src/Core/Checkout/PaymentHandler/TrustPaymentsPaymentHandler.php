<?php declare(strict_types=1);

namespace TrustPaymentsPayment\Core\Checkout\PaymentHandler;

use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Checkout\Payment\Cart\AsyncPaymentTransactionStruct,
	Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface,
	Checkout\Payment\Exception\AsyncPaymentFinalizeException,
	Checkout\Payment\Exception\AsyncPaymentProcessException,
	Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException,
	Framework\Validation\DataBag\RequestDataBag,
	System\SalesChannel\SalesChannelContext,};
use Symfony\Component\{
	HttpFoundation\RedirectResponse,
	HttpFoundation\Request,};
use TrustPayments\Sdk\Model\TransactionState;
use TrustPaymentsPayment\Core\Api\Transaction\Service\TransactionService;


/**
 * Class TrustPaymentsPaymentHandler
 *
 * @package TrustPaymentsPayment\Core\Checkout\PaymentHandler
 */
class TrustPaymentsPaymentHandler implements AsynchronousPaymentHandlerInterface {

	/**
	 * @var \TrustPaymentsPayment\Core\Api\Transaction\Service\TransactionService
	 */
	protected $transactionService;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	private $logger;

	/**
	 * TrustPaymentsPaymentHandler constructor.
	 *
	 * @param \TrustPaymentsPayment\Core\Api\Transaction\Service\TransactionService $transactionService
	 */
	public function __construct(TransactionService $transactionService)
	{
		$this->transactionService = $transactionService;
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * The pay function will be called after the customer completed the order.
	 * Allows to process the order and store additional information.
	 *
	 * A redirect to the url will be performed
	 *
	 * @param \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction
	 * @param \Shopware\Core\Framework\Validation\DataBag\RequestDataBag         $dataBag
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelContext             $salesChannelContext
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function pay(
		AsyncPaymentTransactionStruct $transaction,
		RequestDataBag $dataBag,
		SalesChannelContext $salesChannelContext
	): RedirectResponse
	{
		try {
			$redirectUrl = $this->transactionService->create($transaction, $salesChannelContext);
			return new RedirectResponse($redirectUrl);

		} catch (\Exception $e) {
			$errorMessage = 'An error occurred during the communication with external payment gateway : ' . $e->getMessage();
			$this->logger->critical($errorMessage);
			throw new AsyncPaymentProcessException($transaction->getOrderTransaction()->getId(), $errorMessage);
		}
	}

	/**
	 * The finalize function will be called when the user is redirected back to shop from the payment gateway.
	 *
	 * Throw a @see AsyncPaymentFinalizeException exception if an error ocurres while calling an external payment API
	 * Throw a @see CustomerCanceledAsyncPaymentException exception if the customer canceled the payment process on
	 * payment provider page
	 *
	 * @param \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct $transaction
	 * @param \Symfony\Component\HttpFoundation\Request                          $request
	 * @param \Shopware\Core\System\SalesChannel\SalesChannelContext             $salesChannelContext
	 * @throws \TrustPayments\Sdk\ApiException
	 * @throws \TrustPayments\Sdk\Http\ConnectionException
	 * @throws \TrustPayments\Sdk\VersioningException
	 */
	public function finalize(
		AsyncPaymentTransactionStruct $transaction,
		Request $request,
		SalesChannelContext $salesChannelContext
	): void
	{
		$transactionEntity = $this->transactionService->getByOrderId(
			$transaction->getOrder()->getId(),
			$salesChannelContext->getContext()
		);

		$trustPaymentsTransaction = $this->transactionService->read(
			$transactionEntity->getTransactionId(),
			$salesChannelContext->getSalesChannel()->getId()
		);

		if (in_array($trustPaymentsTransaction->getState(), [TransactionState::FAILED])) {
			$errorMessage = strtr('Customer canceled payment for :orderId on SalesChannel :salesChannelName', [
				':orderId'          => $transaction->getOrder()->getId(),
				':salesChannelName' => $salesChannelContext->getSalesChannel()->getName(),
			]);
			$this->logger->info($errorMessage);
			throw new CustomerCanceledAsyncPaymentException($transaction->getOrder()->getId());
		}
	}
}