<?php declare(strict_types=1);

namespace TrustPaymentsPayment\Core\Util\Analytics;

use TrustPayments\Sdk\ApiClient;

/**
 * Class Analytics
 *
 * @package TrustPaymentsPayment\Core\Util\Analytics
 */
class Analytics {

	public const SHOP_SYSTEM             = 'x-trustpayments-shop-system';
	public const SHOP_SYSTEM_VERSION     = 'x-trustpayments-shop-system-version';
	public const SHOP_SYSTEM_AND_VERSION = 'x-trustpayments-shop-system-and-version';
	public const PLUGIN_FEATURE          = 'x-trustpayments-shop-plugin-feature';

	/**
	 * @return array
	 */
	public static function getDefaultData()
	{
		return [
			self::SHOP_SYSTEM             => 'shopware',
			self::SHOP_SYSTEM_VERSION     => '6',
			self::SHOP_SYSTEM_AND_VERSION => 'shopware-6',
		];
	}

	/**
	 * @param \TrustPayments\Sdk\ApiClient $apiClient
	 */
	public static function addHeaders(ApiClient &$apiClient)
	{
		$data = self::getDefaultData();
		foreach ($data as $key => $value) {
			$apiClient->addDefaultHeader($key, $value);
		}
	}
}


