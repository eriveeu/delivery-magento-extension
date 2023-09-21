<?php
declare(strict_types=1);

namespace EriveEu\GreenToHomeShipping\Plugin;

use Erive\Delivery\Api\CompanyApi;
use Erive\Delivery\Api\CompanyApiFactory;
use Erive\Delivery\ApiException;
use Erive\Delivery\ApiExceptionFactory;
use Erive\Delivery\Configuration;
use Erive\Delivery\Model\Address;
use Erive\Delivery\Model\AddressFactory;
use Erive\Delivery\Model\Customer;
use Erive\Delivery\Model\CustomerFactory;
use Erive\Delivery\Model\Parcel;
use Erive\Delivery\Model\ParcelFactory;
use Erive\Delivery\Model\ParcelStatus;
use Erive\Delivery\Model\CreatedParcel;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment as ShipmentResource;
use EriveEu\GreenToHomeShipping\Model\Carrier\GreenToHome;

/**
 * Class ShipmentRepositoryPlugin
 */
class ShipmentResourcePlugin
{
    public const CARRIERS_GREENTOHOME = 'carriers/greentohome/';
    public const GREENTOHOME_CUSTOMER_ID = 'greentohome_customer_id';
    public const GREENTOHOME_PARCEL_ID = 'greentohome_parcel_id';
    public const API_KEY = 'api_key';

    /**
     * @param ScopeConfigInterface $_scopeConfig
     * @param ShipmentRepositoryInterface $_shipmentRepository
     * @param TrackFactory $_trackFactory
     * @param CustomerRepositoryInterface $_customerRepository
     * @param ManagerInterface $_messageManager
     * @param EncryptorInterface $_encryptor
     * @param CustomerFactory $_customerFactory
     * @param AddressFactory $_addressFactory
     * @param CompanyApiFactory $_companyApiFactory
     * @param ParcelFactory $_parcelFactory
     * @param ApiExceptionFactory $_apiExceptionFactory
     * @param Configuration $_configuration
     */
    public function __construct(
        private readonly ScopeConfigInterface          $_scopeConfig,
        private readonly ShipmentRepositoryInterface   $_shipmentRepository,
        private readonly TrackFactory                  $_trackFactory,
        private readonly CustomerRepositoryInterface   $_customerRepository,
        private readonly ManagerInterface              $_messageManager,
        private readonly EncryptorInterface            $_encryptor,
        private readonly CustomerFactory               $_customerFactory,
        private readonly AddressFactory                $_addressFactory,
        private readonly CompanyApiFactory             $_companyApiFactory,
        private readonly ParcelFactory                 $_parcelFactory,
        private readonly ApiExceptionFactory           $_apiExceptionFactory,
        private readonly Configuration                 $_configuration
    ){}

    /**
     * @param ShipmentResource $subject
     * @param ShipmentResource $pluginResult
     * @param Shipment $shipment
     * @return ShipmentResource
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSave(
        ShipmentResource $subject,
        ShipmentResource $pluginResult,
        Shipment         $shipment
    ): ShipmentResource
    {
        //stop if shipping method is other than greentohome or if shipment has been submitted to api before
        if ($shipment->getOrder()->getShippingMethod() != GreenToHome::CARRIER_CODE . '_' . GreenToHome::METHOD_CODE || $shipment->getData(self::GREENTOHOME_PARCEL_ID) !== null) {
            return $pluginResult;
        }

        try {
            $magentoCustomer = $this->_customerRepository->getById($shipment->getOrder()->getCustomerId());
        } catch (\Exception $e) {
            //catch for guest orders
            $magentoCustomer = null;
        }

        $shipmentItems = $shipment->getAllItems();
        $totalWeight = 0;
        foreach ($shipmentItems as $item) {
            $totalWeight += (float)$item->getWeight() * $item->getQty();
        }

        /** @var Address $address */
        $address = $this->_addressFactory->create();
        $fullStreet = $shipment->getOrder()->getShippingAddress()->getStreet();
        $fullStreet = implode(' ', $fullStreet); //implode multiple streen lines into one string

        preg_match('/^(.*?)(\d.*)$/', trim($fullStreet), $matches); //extract street number
        if(count($matches) > 2) {
            $fullStreet = trim($matches[1]);
            $address->setStreetNumber($matches[2]);
        }
        $address->setStreet($fullStreet);
        $address->setCity($shipment->getOrder()->getShippingAddress()->getCity());
        $address->setZip($shipment->getOrder()->getShippingAddress()->getPostcode());
        $address->setCountry($shipment->getOrder()->getShippingAddress()->getCountryId());
        /** @var Customer $customer */
        $customer = $this->_customerFactory->create();
        $customer->setAddress($address);
        $customer->setName($shipment->getOrder()->getCustomerName());
        $customer->setEmail($shipment->getOrder()->getCustomerEmail());
        $customer->setPhone($shipment->getOrder()->getShippingAddress()->getTelephone());

        if ($magentoCustomer?->getCustomAttribute(self::GREENTOHOME_CUSTOMER_ID)?->getValue()) {
            $customer->setId($magentoCustomer->getCustomAttribute(self::GREENTOHOME_CUSTOMER_ID)->getValue());
        }

        /** @var Parcel $parcel */
        $parcel = $this->_parcelFactory->create();
        $parcel->setTo($customer);
        $parcel->setWeight((float)$totalWeight);
        $parcel->setExternalReference($shipment->getIncrementId());
        $parcel->setStatus(ParcelStatus::STATUS_ANNOUNCED);

        try {
            $apiInstance = $this->getCompanyApiInstance();
            $result = $apiInstance->submitParcel($parcel);

            if ($result instanceof CreatedParcel) {

                if ($result->getSuccess() === false) {
                    $errorMsg = method_exists($result, 'getMessage') ? (string)$result->getMessage() : 'Unknown error as no getMessage method found';
                    throw $this->_apiExceptionFactory->create(['message' => $errorMsg]);
                }

                $resolvedParcel = $result->getParcel();

                $shipment->setData(self::GREENTOHOME_PARCEL_ID, $resolvedParcel->getId());
                $shipment->setShippingLabel(file_get_contents($resolvedParcel->getLabelUrl()));

                $track = $this->_trackFactory->create();
                $track->setCarrierCode(GreenToHome::CARRIER_CODE);
                $track->setTitle($this->getConfigData('name'));
                $track->setTrackNumber($resolvedParcel->getId());
                $shipment->addTrack($track);

                $this->_shipmentRepository->save($shipment);

                if ($magentoCustomer?->getCustomAttribute(self::GREENTOHOME_CUSTOMER_ID)?->getValue() !== $resolvedParcel->getTo()->getId()) {
                    $magentoCustomer->setCustomAttribute(self::GREENTOHOME_CUSTOMER_ID, $resolvedParcel->getTo()->getId());
                    try {
                        $this->_customerRepository->save($magentoCustomer);
                    } catch (\Exception $e) {
                    }
                }
            } else {
                throw $this->_apiExceptionFactory->create(['message' => 'result is not an instance of CreatedParcel']);
            }

        } catch (ApiException $e) {
            $this->_messageManager->addError(
                __('Exception when calling CompanyApi->submitParcel: %1', $e->getMessage())
            );
        }
        return $pluginResult;
    }

    /**
     * @return CompanyApi
     */
    public function getCompanyApiInstance(): CompanyApi
    {
        $this->_configuration->setHost($this->_configuration->getHostFromSettings($this->getConfigData('environment')));
        $this->_configuration->setApiKey('key', $this->getDecryptedConfigData(self::API_KEY));
        return $this->_companyApiFactory->create(['config' => $this->_configuration]);
    }

    /**
     * Retrieve information from carrier configuration
     *
     * @param string $field
     * @return  string
     */
    private function getConfigData(string $field): string
    {
        return $this->_scopeConfig->getValue(self::CARRIERS_GREENTOHOME . $field) ?? '';
    }

    /**
     * Get decrypted config data
     *
     * @param string $field
     * @return string
     */
    public function getDecryptedConfigData(string $field): string
    {
        return $this->_encryptor->decrypt($this->_scopeConfig->getValue(self::CARRIERS_GREENTOHOME . $field) ?? '');
    }

}

