<?php
/**
 * ClassyLlama_AvaTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @copyright  Copyright (c) 2018 Avalara, Inc.
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace ClassyLlama\AvaTax\Framework\Interaction\Rest;

use Avalara\TransactionBuilderFactory;
use ClassyLlama\AvaTax\Exception\AvataxConnectionException;
use ClassyLlama\AvaTax\Framework\Interaction\Rest\Tax\ResultFactory as TaxResultFactory;
use ClassyLlama\AvaTax\Helper\Rest\Config as RestConfig;
use Magento\Framework\DataObjectFactory;
use Psr\Log\LoggerInterface;
use ClassyLlama\AvaTax\Framework\Interaction\Rest\ClientPool;
use ClassyLlama\AvaTax\Helper\CustomsConfig;

class Tax extends \ClassyLlama\AvaTax\Framework\Interaction\Rest
    implements \ClassyLlama\AvaTax\Api\RestTaxInterface
{
    const LINE_PARAM_NAME_UNIT_NAME = 'AvaTax.LandedCost.UnitName';
    const LINE_PARAM_NAME_UNIT_AMT = 'AvaTax.LandedCost.UnitAmount';
    const LINE_PARAM_NAME_PREF_PROGRAM = 'AvaTax.LandedCost.PreferenceProgram';
    const TRANSACTION_PARAM_NAME_SHIPPING_MODE = 'AvaTax.LandedCost.ShippingMode';

    /**
     * @var TransactionBuilderFactory
     */
    protected $transactionBuilderFactory;

    /**
     * @var TaxResultFactory
     */
    protected $taxResultFactory;

    /**
     * @var RestConfig
     */
    protected $restConfig;

    /**
     * @var CustomsConfig
     */
    protected $customsConfigHelper;

    /**
     * @param LoggerInterface $logger
     * @param DataObjectFactory $dataObjectFactory
     * @param ClientPool $clientPool
     * @param TransactionBuilderFactory $transactionBuilderFactory
     * @param TaxResultFactory $taxResultFactory
     * @param RestConfig $restConfig
     * @param CustomsConfig $customsConfigHelper
     */
    public function __construct(
        LoggerInterface $logger,
        DataObjectFactory $dataObjectFactory,
        ClientPool $clientPool,
        TransactionBuilderFactory $transactionBuilderFactory,
        TaxResultFactory $taxResultFactory,
        RestConfig $restConfig,
        CustomsConfig $customsConfigHelper
    ) {
        parent::__construct($logger, $dataObjectFactory, $clientPool);
        $this->transactionBuilderFactory = $transactionBuilderFactory;
        $this->taxResultFactory = $taxResultFactory;
        $this->restConfig = $restConfig;
        $this->customsConfigHelper = $customsConfigHelper;
    }

    /**
     * REST call to post tax transaction
     *
     * @param \Magento\Framework\DataObject $request
     * @param null|bool                     $isProduction
     * @param null|string|int               $scopeId
     * @param string                        $scopeType
     * @param array                         $params
     *
     * @return \ClassyLlama\AvaTax\Framework\Interaction\Rest\Tax\Result
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws AvataxConnectionException
     * @throws \Exception
     */
    public function getTax( $request, $isProduction = null, $scopeId = null, $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $params = [])
    {
        $client = $this->getClient( $isProduction, $scopeId, $scopeType);
        $client->withCatchExceptions(false);

        /** @var \Avalara\TransactionBuilder $transactionBuilder */
        $transactionBuilder = $this->transactionBuilderFactory->create([
            'client' => $client,
            'companyCode' => $request->getCompanyCode(),
            'type' => $request->getType(),
            'customerCode' => $request->getCustomerCode(),
        ]);

        $this->setTransactionDetails($transactionBuilder, $request);
        $this->setLineDetails($transactionBuilder, $request);
        $this->setAddressDetails($transactionBuilder, $request);

        $resultObj = null;

        try {
            $resultObj = $transactionBuilder->create();
        }
        catch (\GuzzleHttp\Exception\ClientException $clientException) {
            $this->handleException($clientException, $request);
        }

//        $this->validateResult($resultObj, $request);

        $resultGeneric = $this->formatResult($resultObj);
        /** @var \ClassyLlama\AvaTax\Framework\Interaction\Rest\Tax\Result $result */
        $result = $this->taxResultFactory->create(['data' => $resultGeneric->getData()]);

        /**
         * We store the request on the result so we can map request items to response items
         */
        $result->setRequest($request);

        return $result;
    }

    /**
     * Set transaction-level fields for request
     *
     * @param \Avalara\TransactionBuilder $transactionBuilder
     * @param \Magento\Framework\DataObject $request
     */
    protected function setTransactionDetails($transactionBuilder, $request)
    {
        if ($request->getCommit()) {
            $transactionBuilder->withCommit();
        }
        if ($request->hasIsSellerImporterOfRecord()) {
            $transactionBuilder->withSellerIsImporterOfRecord($request->getIsSellerImporterOfRecord());
        }

        if ($request->hasCode()) {
            $transactionBuilder->withTransactionCode($request->getCode());
        }
        if ($request->hasBusinessIdentificationNo()) {
            $transactionBuilder->withBusinessIdentificationNo($request->getBusinessIdentificationNo());
        }
        if ($request->hasCurrencyCode()) {
            $transactionBuilder->withCurrencyCode($request->getCurrencyCode());
        }
        if ($request->hasEntityUseCode()) {
            $transactionBuilder->withEntityUseCode($request->getEntityUseCode());
        }
        if ($request->hasDiscount()) {
            $transactionBuilder->withDiscountAmount($request->getDiscount());
        }
        if ($request->hasExchangeRate()) {
            $transactionBuilder->withExchangeRate($request->getExchangeRate(), $request->getExchangeRateEffectiveDate());
        }
        if ($request->hasReportingLocationCode()) {
            $transactionBuilder->withReportingLocationCode($request->getReportingLocationCode());
        }
        if ($request->hasPurchaseOrderNo()) {
            $transactionBuilder->withPurchaseOrderNo($request->getPurchaseOrderNo());
        }
        if ($request->hasReferenceCode()) {
            $transactionBuilder->withReferenceCode($request->getReferenceCode());
        }
        if ($request->hasTaxOverride()) {
            $override = $request->getTaxOverride();
            if (is_object($override)) {
                $transactionBuilder->withTaxOverride($override->getType(), $override->getReason(), $override->getTaxAmount(), $override->getTaxDate());
            }
        }
        if($request->hasShippingMode()) {
            $transactionBuilder->withParameter(self::TRANSACTION_PARAM_NAME_SHIPPING_MODE, $request->getShippingMode());
        }
    }

    /**
     * Set address entries and fields for request
     *
     * @param \Avalara\TransactionBuilder $transactionBuilder
     * @param \Magento\Framework\DataObject $request
     * @throws \Exception
     */
    protected function setLineDetails($transactionBuilder, $request)
    {
        if ($request->hasLines()) {
            foreach ($request->getLines() as $line) {
                $amount = ($line->hasAmount()) ? $line->getAmount() : 0;
                $transactionBuilder->withLine($amount, $line->getQuantity(), $line->getItemCode(), $line->getTaxCode());

                if ($line->getTaxIncluded()) {
                    $transactionBuilder->withLineTaxIncluded();
                }

                if ($line->hasDescription()) {
                    $transactionBuilder->withLineDescription($line->getDescription());
                }
                if ($line->hasDiscounted()) {
                    $transactionBuilder->withItemDiscount($line->getDiscounted());
                }
                if ($line->hasRef1() || $line->hasRef2()) {
                    $transactionBuilder->withLineCustomFields($line->getRef1(), $line->getRef2());
                }

                if ($this->customsConfigHelper->enabled()) {
                    if ($line->hasHsCode()) {
                        $transactionBuilder->withLineHsCode($line->getHsCode());
                    }
                    if ($line->hasUnitName()) {
                        $transactionBuilder->withLineParameter(self::LINE_PARAM_NAME_UNIT_NAME, $line->getUnitName());
                    }
                    if ($line->hasUnitAmount()) {
                        $transactionBuilder->withLineParameter(self::LINE_PARAM_NAME_UNIT_AMT, $line->getUnitAmount());
                    }
                    if ($line->hasPreferenceProgram()) {
                        $transactionBuilder->withLineParameter(self::LINE_PARAM_NAME_PREF_PROGRAM, $line->getPreferenceProgram());
                    }
                }

                /**
                 * It's only here that we can set the line number on the request items, when we're sure it will be the same as the line number in the response
                 */
                $line->setNumber($transactionBuilder->getCurrentLineNumber());
            }
        }
    }

    /**
     * Set line item entries and fields for request
     *
     * @param \Avalara\TransactionBuilder $transactionBuilder
     * @param \Magento\Framework\DataObject $request
     */
    protected function setAddressDetails($transactionBuilder, $request)
    {
        if ($request->hasAddresses()) {
            foreach ($request->getAddresses() as $type => $address) {
                $transactionBuilder->withAddress(
                    $type,
                    $address->getLine1(),
                    $address->getLine2(),
                    $address->getLine3(),
                    $address->getCity(),
                    $address->getRegion(),
                    $address->getPostalCode(),
                    $address->getCountry()
                );
            }
        }
    }
}
