# ERIVE.delivery Shipping Method

## Table of Contents

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [License](#license)
- [Support](#support)
- [Changelog](#changelog)

## Introduction

This module adds a custom online shipping method named ERIVE.delivery.
The creation of a shipment in Magento automatically triggers the creation of a parcel with status announced in the ERIVE.delivery Cockpit.
The tracking url for the shipment is generated automatically.

## Requirements

- Magento Open Source 2.4.5 or greater
- PHP 8.1 or greater

## Installation

Install using composer or manual installation.

### Type 1: Composer

- Add the composer repository url to your composer configuration https://github.com/eriveeu/greentohome-magento-extension.git
- Install the module by running `composer require eriveeu/module-greentohomeshipping`
- Enable the module by running `php bin/magento module:enable EriveEu_GreenToHomeShipping`
- Apply database updates by running `php bin/magento setup:upgrade`
- Flush the cache by running `php bin/magento cache:flush`

### Type 2: Zip file

- Download the zip file from https://github.com/eriveeu/greentohome-magento-extension/archive/refs/heads/main.zip
- Unzip the zip file in `app/code/EriveEu`
- Enable the module by running `php bin/magento module:enable EriveEu_GreenToHomeShipping`
- Apply database updates by running `php bin/magento setup:upgrade`
- Flush the cache by running `php bin/magento cache:flush`


## Configuration

Configure the module under Stores > Configuration > Sales > Delivery Methods > ERIVE.delivery

Configuration fields not mentioned are self-explanatory or magento default.

**API Key:** Request API Key from ERIVE.delivery

**Enviroment:** ERIVE.delivery offers Dev and Stage enviroments for testing purposes. Select production for live usage.

**Tracking URL:** Base URL for the tracking links of the shipments. The tracking number will be appended automatically to this URL.

**Restrict method to regions:** If filled, the shipping method will only be available for the specified zip codes. Multiple zip codes can be entered separated by comma.
Use full or parts of zip codes. E.g. 1,23,2500: This will allow shipping to all zip codes starting with 1 (Vienna) or 23 (Bezirk Mödling) or the exact zip code of 2500 (City of Baden).

## License

GPL-3.0 License
See LICENSE.txt for details.

## Support

ERIVE GmbH, developers@erive.eu

