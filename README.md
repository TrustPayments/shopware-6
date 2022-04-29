> This plugin supports the Trust Enterprise Platform which can be identified by the URL for the platform ep.trustpayments.com.

TrustPayments Payment for Shopware 6
=============================

The TrustPayments Payment plugin wraps around the TrustPayments API. This library facilitates your interaction with various services such as transactions.

## Requirements

- PHP 7.4 and above
- Shopware 6.4 and above

## Installation

You can use **Composer** or **install manually**

### Composer

The preferred method is via [composer](https://getcomposer.org). Follow the
[installation instructions](https://getcomposer.org/doc/00-intro.md) if you do not already have
composer installed.

Once composer is installed, execute the following command in your project root to install this library:

```bash
composer require trustpayments/shopware-6
php bin/console plugin:refresh
php bin/console plugin:install --activate --clearCache TrustPaymentsPayment
```

#### Update via composer
```bash
composer update trustpayments/shopware-6
php bin/console plugin:refresh
php bin/console plugin:install --activate --clearCache TrustPaymentsPayment
```

### Manual Installation

Alternatively you can download the package in its entirety. The [Releases](../../releases) page lists all stable versions.

Uncompress the zip file you download, and include the autoloader in your project:

```bash
# unzip to ShopwareInstallDir/custom/plugins/TrustPaymentsPayment
composer require trustpayments/sdk 3.0.1
php bin/console plugin:refresh
php bin/console plugin:install --activate --clearCache TrustPaymentsPayment
```

## Usage
The library needs to be configured with your account's space id, user id, and application key which are available in your TrustPayments
account dashboard.

### Logs and debugging
To view the logs please run the command below:
```bash
cd shopware/install/dir
tail -f var/log/trustpayments_payment*.log
```

## Documentation

[Documentation](https://plugin-documentation.ep.trustpayments.com/TrustPayments/shopware-6/4.0.32/docs/en/documentation.html)

## License

Please see the [license file](https://github.com/TrustPayments/shopware-6/blob/master/LICENSE.txt) for more information.