<?php

declare(strict_types=1);

namespace Pally\Payment\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Pally\Payment\Model\Webhook\Processor;
use Pally\Payment\Model\Webhook\SignatureVerifier;
use Psr\Log\LoggerInterface;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SignatureVerifier $signatureVerifier,
        private readonly Processor $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        $invId = (string) $this->request->getParam('InvId', '');
        $outSum = (string) $this->request->getParam('OutSum', '');
        $signatureValue = (string) $this->request->getParam('SignatureValue', '');
        $custom = (string) $this->request->getParam('custom', '');
        $trsId = (string) $this->request->getParam('TrsId', '');
        $status = (string) $this->request->getParam('Status', '');
        $accountType = (string) $this->request->getParam('AccountType', '');
        $commission = (string) $this->request->getParam('Commission', '');

        if ($invId === '' || $outSum === '' || $signatureValue === '') {
            $this->logger->warning('Pally webhook: missing required parameters');
            return $result->setHttpResponseCode(400)->setData(['ok' => false, 'error' => 'bad_request']);
        }

        if (!$this->signatureVerifier->isValid($outSum, $invId, $signatureValue)) {
            $this->logger->error('Pally webhook: invalid signature', [
                'InvId' => $invId,
                'custom' => $custom,
            ]);
            return $result->setHttpResponseCode(401)->setData(['ok' => false, 'error' => 'invalid_signature']);
        }

        $webhookData = [
            'InvId' => $invId,
            'OutSum' => $outSum,
            'custom' => $custom,
            'TrsId' => $trsId,
            'Status' => $status,
            'AccountType' => $accountType,
            'Commission' => $commission,
        ];

        try {
            $this->processor->process($webhookData);
        } catch (\Exception $e) {
            $this->logger->error('Pally webhook: processing error', [
                'error' => $e->getMessage(),
                'InvId' => $invId,
                'custom' => $custom,
            ]);
            return $result->setHttpResponseCode(200)->setData(['ok' => true]);
        }

        return $result->setHttpResponseCode(200)->setData(['ok' => true]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
