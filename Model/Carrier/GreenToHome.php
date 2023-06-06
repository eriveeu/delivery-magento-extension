<?php
declare(strict_types=1);

namespace EriveEu\GreenToHomeShipping\Model\Carrier;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Directory\Helper\Data;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Xml\Security;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\Error;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory as RateErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory as RateMethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory as RateFactory;
use Magento\Shipping\Model\Simplexml\ElementFactory;
use Magento\Shipping\Model\Tracking\Result\ErrorFactory as TrackErrorFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory as TrackStatusFactory;
use Magento\Shipping\Model\Tracking\ResultFactory as TrackFactory;
use Psr\Log\LoggerInterface;

/**
 * GreenToHome shipping model
 */
class GreenToHome extends AbstractCarrierOnline implements CarrierInterface
{

    public const CARRIER_CODE = 'greentohome';
    public const METHOD_CODE = 'greentohome';

    /**
     * @var string
     */
    protected $_code = self::CARRIER_CODE;

    /**
     * @var bool
     */
    protected $_isFixed = true;


    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param RateErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param Security $xmlSecurity
     * @param ElementFactory $xmlElFactory
     * @param RateFactory $rateFactory
     * @param RateMethodFactory $rateMethodFactory
     * @param TrackFactory $trackFactory
     * @param TrackErrorFactory $trackErrorFactory
     * @param TrackStatusFactory $trackStatusFactory
     * @param RegionFactory $regionFactory
     * @param CountryFactory $countryFactory
     * @param CurrencyFactory $currencyFactory
     * @param Data $directoryData
     * @param StockRegistryInterface $stockRegistry
     * @param array $data
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct(
        ScopeConfigInterface   $scopeConfig,
        RateErrorFactory       $rateErrorFactory,
        LoggerInterface        $logger,
        Security               $xmlSecurity,
        ElementFactory         $xmlElFactory,
        RateFactory            $rateFactory,
        RateMethodFactory      $rateMethodFactory,
        TrackFactory           $trackFactory,
        TrackErrorFactory      $trackErrorFactory,
        TrackStatusFactory     $trackStatusFactory,
        RegionFactory          $regionFactory,
        CountryFactory         $countryFactory,
        CurrencyFactory        $currencyFactory,
        Data                   $directoryData,
        StockRegistryInterface $stockRegistry,
        array                  $data = [],
    )
    {
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElFactory,
            $rateFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $directoryData,
            $stockRegistry,
            $data
        );
    }

    /**
     * Collect and get rates
     *
     * @param RateRequest $request
     * @return false|Error|Result
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $shippingPrice = $this->getConfigData('price');

        $result = $this->_rateFactory->create();

        if ($shippingPrice !== false) {
            $method = $this->_rateMethodFactory->create();

            $method->setCarrier($this->_code);
            $method->setCarrierTitle($this->getConfigData('title'));

            $method->setMethod($this->_code);
            $method->setMethodTitle($this->getConfigData('name'));

            if ($request->getFreeShipping() === true) {
                $shippingPrice = '0.00';
            }

            $method->setPrice($shippingPrice);
            $method->setCost($shippingPrice);

            $result->append($method);

        }

        if (!$this->isZipCodeAllowed($request->getDestPostcode())) {
            $error = $this->_rateErrorFactory->create();
            $error->setCarrier($this->_code);
            $error->setCarrierTitle($this->getConfigData('name'));
            $errorMsg = $this->getConfigData('specificerrmsg');
            $error->setErrorMessage(
                $errorMsg ? $errorMsg : __(
                    'This shipping method is not available with the desired shipping address.'
                )
            );

            if ($this->getConfigData('showmethod')) {
                return $error;
            }

            return false;

        }

        return $result;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public function getAllowedMethods(): array
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    /**
     * @param $zipCode
     * @return bool
     */
    private function isZipCodeAllowed($zipCode): bool
    {
        $allowedRegions = array_map('trim', explode(',', $this->getConfigData('region')));

        foreach ($allowedRegions as $allowedRegion) {
            $shorterLength = min(strlen($allowedRegion), strlen($zipCode));
            $allowedRegion = substr($allowedRegion, 0, $shorterLength);
            $zipCodeToCompare = substr($zipCode, 0, $shorterLength);

            if ($zipCodeToCompare === $allowedRegion) {
                return true;
            }
        }

        return false;
    }

    /**
     * Not in use, cause isShippingLabelsAvailable returns false
     *
     * @param DataObject $request
     * @return void|null
     */
    protected function _doShipmentRequest(DataObject $request)
    {
        return null;
    }

    /**
     * Generate details for tracking popup window
     *
     * @param string|string[] $trackNumber
     * @return \Magento\Shipping\Model\Tracking\Result|null
     */
    public function getTracking(array|string $trackNumber): ?\Magento\Shipping\Model\Tracking\Result
    {
        if (is_array($trackNumber)) {
            $trackNumber = $trackNumber[0];
        }
        $result = $this->_trackFactory->create();

        $tracking = $this->_trackStatusFactory->create();
        $tracking->setCarrier($this->_code);
        $tracking->setCarrierTitle($this->getConfigData('name') . ' - ' . $this->getConfigData('title'));
        $tracking->setTracking($trackNumber);
        $trackingUrl = $this->getConfigData('tracking_url');
        if (!str_ends_with($trackingUrl, '/')) {
            $trackingUrl .= '/';
        }
        $tracking->setUrl($trackingUrl . $trackNumber);
        $result->append($tracking);

        return $result;
    }


    /**
     * Check if carrier has shipping label option available
     *
     * @return bool
     */
    public function isShippingLabelsAvailable(): bool
    {
        return false;
    }

}
