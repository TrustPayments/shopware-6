<?php declare(strict_types=1);

namespace TrustPaymentsPayment\Core\Api\PaymentMethodConfiguration\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Checkout\Cart\Rule\CartAmountRule,
	Content\ImportExport\DataAbstractionLayer\Serializer\Entity\MediaSerializer,
	Content\ImportExport\DataAbstractionLayer\Serializer\SerializerRegistry,
	Content\ImportExport\Struct\Config,
	Content\Media\MediaDefinition,
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\DataAbstractionLayer\Search\Filter\EqualsFilter,
	Framework\Plugin\Util\PluginIdProvider,
	Framework\Rule\Container\AndRule,
	Framework\Uuid\Uuid};
use Symfony\Component\DependencyInjection\ContainerInterface;
use TrustPayments\Sdk\{
	ApiClient,
	Model\CreationEntityState,
	Model\EntityQuery,
	Model\PaymentMethodConfiguration,
	Model\RestLanguage};
use TrustPaymentsPayment\Core\{
	Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity,
	Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntityDefinition,
	Checkout\PaymentHandler\TrustPaymentsPaymentHandler,
	Settings\Service\SettingsService,
	Util\LocaleCodeProvider};
use TrustPaymentsPayment\TrustPaymentsPayment;


/**
 * Class PaymentMethodConfigurationService
 *
 * @package TrustPaymentsPayment\Core\Api\PaymentMethodConfiguration\Service
 */
class PaymentMethodConfigurationService {

	public const TRUSTPAYMENTS_AVAILABILITY_RULE_NAME = 'TrustPaymentsAvailabilityRule';

	/**
	 * @var \TrustPaymentsPayment\Core\Settings\Service\SettingsService
	 */
	protected $settingsService;

	/**
	 * @var \TrustPayments\Sdk\ApiClient
	 */
	protected $apiClient;

	/**
	 * Space Id
	 *
	 * @var int
	 */
	protected $spaceId;

	/**
	 * @var \Symfony\Component\DependencyInjection\ContainerInterface
	 */
	protected $container;

	/**
	 * @var \Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\SerializerRegistry
	 */
	protected $serializerRegistry;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger;

	/**
	 * @var ?string $salesChannelId
	 */
	private $salesChannelId;

	/**
	 * @var \Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\Entity\MediaSerializer
	 */
	private $mediaSerializer;

	/**
	 * @var
	 */
	private $languages;

	/**
	 * @var \TrustPaymentsPayment\Core\Util\LocaleCodeProvider
	 */
	private $localeCodeProvider;

	/**
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	private $ruleRepository;

	/**
	 * @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface
	 */
	private $paymentMethodRepository;

	/**
	 * PaymentMethodConfigurationService constructor.
	 *
	 * @param \TrustPaymentsPayment\Core\Settings\Service\SettingsService                        $settingsService
	 * @param \Symfony\Component\DependencyInjection\ContainerInterface                                  $container
	 * @param \Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\Entity\MediaSerializer $mediaSerializer
	 * @param \Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\SerializerRegistry     $serializerRegistry
	 */
	public function __construct(
		SettingsService $settingsService,
		ContainerInterface $container,
		MediaSerializer $mediaSerializer,
		SerializerRegistry $serializerRegistry
	)
	{
		$this->container               = $container;
		$this->ruleRepository          = $this->container->get('rule.repository');
		$this->settingsService         = $settingsService;
		$this->mediaSerializer         = $mediaSerializer;
		$this->serializerRegistry      = $serializerRegistry;
		$this->localeCodeProvider      = $this->container->get(LocaleCodeProvider::class);
		$this->paymentMethodRepository = $this->container->get('payment_method.repository');
	}

	/**
	 * @param \Psr\Log\LoggerInterface $logger
	 *
	 * @internal
	 * @required
	 *
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * @return \TrustPayments\Sdk\ApiClient
	 */
	public function getApiClient(): ApiClient
	{
		return $this->apiClient;
	}

	/**
	 * @param \TrustPayments\Sdk\ApiClient $apiClient
	 *
	 * @return \TrustPaymentsPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
	 */
	public function setApiClient(ApiClient $apiClient): PaymentMethodConfigurationService
	{
		$this->apiClient = $apiClient;
		return $this;
	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return array
	 * @throws \TrustPayments\Sdk\ApiException
	 * @throws \TrustPayments\Sdk\Http\ConnectionException
	 * @throws \TrustPayments\Sdk\VersioningException
	 */
	public function synchronize(Context $context): array
	{
		// Configuration
		$settings = $this->settingsService->getSettings($this->getSalesChannelId());
		$this->setSpaceId($settings->getSpaceId())
			 ->setApiClient($settings->getApiClient());

		$this->disablePaymentMethodConfigurations($context);
		$this->enablePaymentMethodConfigurations($context);
		$this->disableOrphanedPaymentMethods();
		return [];
	}

	/**
	 * Get sales channel id
	 *
	 * @return string|null
	 */
	public function getSalesChannelId(): ?string
	{
		return $this->salesChannelId;
	}

	/**
	 * Set sales channel id
	 *
	 * @param string|null $salesChannelId
	 *
	 * @return \TrustPaymentsPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
	 */
	public function setSalesChannelId(?string $salesChannelId = null): PaymentMethodConfigurationService
	{
		$this->salesChannelId = $salesChannelId;
		return $this;
	}

	/**
	 * @param \Shopware\Core\Framework\Context $context
	 */
	private function disablePaymentMethodConfigurations(Context $context): void
	{
		$data     = [];
		$pmdata   = [];
		$criteria = (new Criteria())
			->addFilter(new EqualsFilter('spaceId', $this->getSpaceId()));

		$trustPaymentsPMConfigurationRepository = $this->container->get(PaymentMethodConfigurationEntityDefinition::ENTITY_NAME . '.repository');

		$paymentMethodConfigurationEntities = $trustPaymentsPMConfigurationRepository
			->search($criteria, $context)
			->getEntities();

		/**
		 * @var $paymentMethodConfigurationEntity \TrustPaymentsPayment\Core\Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity
		 */
		foreach ($paymentMethodConfigurationEntities as $paymentMethodConfigurationEntity) {
			$data[] = [
				'id'    => $paymentMethodConfigurationEntity->getId(),
				'state' => CreationEntityState::INACTIVE,
			];

			$pmdata[] = [
				'id'     => $paymentMethodConfigurationEntity->getId(),
				'active' => false,
			];
		}

		$trustPaymentsPMConfigurationRepository->update($data, $context);

		$this->paymentMethodRepository->update($pmdata, $context);
	}

	/**
	 * Full proof method to disable any orphaned payment methods
	 *
	 */
	protected function disableOrphanedPaymentMethods(): void
	{
		try {
			$query = "UPDATE payment_method 
				  	  SET active=0 
				  	  WHERE handler_identifier=:handler_identifier AND id NOT IN (
				  	  	SELECT payment_method_id FROM trustpayments_payment_method_configuration
				  	  )";

			$params = [
				'handler_identifier' => TrustPaymentsPaymentHandler::class,
			];

			$connection = $this->container->get(Connection::class);
			$connection->executeQuery($query, $params);
		} catch (\Exception $exception) {
			$this->logger->critical($exception->getMessage());
		}
	}

	/**
	 * @param string                           $paymentMethodId
	 * @param bool                             $active
	 * @param \Shopware\Core\Framework\Context $context
	 */
	protected function setPaymentMethodIsActive(string $paymentMethodId, bool $active, Context $context): void
	{
		$paymentMethod = [
			'id'     => $paymentMethodId,
			'active' => $active,
		];
		$this->paymentMethodRepository->update([$paymentMethod], $context);
	}

	/**
	 * Enable payment methods from TrustPayments API
	 *
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @throws \TrustPayments\Sdk\ApiException
	 * @throws \TrustPayments\Sdk\Http\ConnectionException
	 * @throws \TrustPayments\Sdk\VersioningException
	 */
	private function enablePaymentMethodConfigurations(Context $context): void
	{
		$paymentMethodConfigurations = $this->getPaymentMethodConfigurations();
		$this->logger->debug('Updating payment methods', $paymentMethodConfigurations);

		/**
		 * @var $paymentMethodConfiguration \TrustPayments\Sdk\Model\PaymentMethodConfiguration
		 */
		foreach ($paymentMethodConfigurations as $paymentMethodConfiguration) {

			$paymentMethodConfigurationEntity = $this->getPaymentMethodConfigurationEntity(
				$paymentMethodConfiguration->getSpaceId(),
				$paymentMethodConfiguration->getId(),
				$context
			);

			$id = is_null($paymentMethodConfigurationEntity) ? Uuid::randomHex() : $paymentMethodConfigurationEntity->getId();

			$data = [
				'id'                           => $id,
				'paymentMethodConfigurationId' => $paymentMethodConfiguration->getId(),
				'paymentMethodId'              => $id,
				'data'                         => json_decode(strval($paymentMethodConfiguration), true),
				'sortOrder'                    => $paymentMethodConfiguration->getSortOrder(),
				'spaceId'                      => $paymentMethodConfiguration->getSpaceId(),
				'state'                        => CreationEntityState::ACTIVE,
			];

			$this->upsertPaymentMethod($id, $paymentMethodConfiguration, $context);


			$this->container->get(PaymentMethodConfigurationEntityDefinition::ENTITY_NAME . '.repository')
							->upsert([$data], $context);

		}
	}

	/**
	 * Fetch merchant payment methods from TrustPayments API
	 *
	 * @return \TrustPayments\Sdk\Model\PaymentMethodConfiguration[]
	 * @throws \TrustPayments\Sdk\ApiException
	 * @throws \TrustPayments\Sdk\Http\ConnectionException
	 * @throws \TrustPayments\Sdk\VersioningException
	 */
	private function getPaymentMethodConfigurations(): array
	{
		$paymentMethodConfigurations = $this
			->apiClient
			->getPaymentMethodConfigurationService()
			->search($this->getSpaceId(), new EntityQuery());


		usort($paymentMethodConfigurations, function (PaymentMethodConfiguration $item1, PaymentMethodConfiguration $item2) {
			return $item1->getSortOrder() <=> $item2->getSortOrder();
		});

		return $paymentMethodConfigurations;
	}

	/**
	 * @return int
	 */
	public function getSpaceId(): int
	{
		return $this->spaceId;
	}

	/**
	 * @param int $spaceId
	 *
	 * @return \TrustPaymentsPayment\Core\Api\PaymentMethodConfiguration\Service\PaymentMethodConfigurationService
	 */
	public function setSpaceId(int $spaceId): PaymentMethodConfigurationService
	{
		$this->spaceId = $spaceId;
		return $this;
	}

	/**
	 * @param int                              $spaceId
	 * @param int                              $paymentMethodConfigurationId
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return \TrustPaymentsPayment\Core\Api\PaymentMethodConfiguration\Entity\PaymentMethodConfigurationEntity|null
	 */
	protected function getPaymentMethodConfigurationEntity(
		int $spaceId,
		int $paymentMethodConfigurationId,
		Context $context
	): ?PaymentMethodConfigurationEntity
	{
		$criteria = (new Criteria())->addFilter(
			new EqualsFilter('spaceId', $spaceId),
			new EqualsFilter('paymentMethodConfigurationId', $paymentMethodConfigurationId)
		);

		return $this->container->get(PaymentMethodConfigurationEntityDefinition::ENTITY_NAME . '.repository')
							   ->search($criteria, $context)
							   ->getEntities()
							   ->first();
	}

	/**
	 * Update or insert Payment Method
	 *
	 * @param string                                                      $id
	 * @param \TrustPayments\Sdk\Model\PaymentMethodConfiguration $paymentMethodConfiguration
	 * @param \Shopware\Core\Framework\Context                            $context
	 *
	 * @throws \TrustPayments\Sdk\ApiException
	 * @throws \TrustPayments\Sdk\Http\ConnectionException
	 * @throws \TrustPayments\Sdk\VersioningException
	 */
	protected function upsertPaymentMethod(
		string $id,
		PaymentMethodConfiguration $paymentMethodConfiguration,
		Context $context
	): void
	{
		/** @var PluginIdProvider $pluginIdProvider */
		$pluginIdProvider = $this->container->get(PluginIdProvider::class);
		$pluginId         = $pluginIdProvider->getPluginIdByBaseClass(
			TrustPaymentsPayment::class,
			$context
		);

		$availabilityRuleId = $this->getAvailabilityRuleId($id, $context);

		$data = [
			'id'                 => $id,
			'handlerIdentifier'  => TrustPaymentsPaymentHandler::class,
			'availabilityRuleId' => $availabilityRuleId,
			'pluginId'           => $pluginId,
			'position'           => $paymentMethodConfiguration->getSortOrder() - 100,
			'afterOrderEnabled'  => true,
			'active'             => true,
			'translations'       => $this->getPaymentMethodConfigurationTranslation($paymentMethodConfiguration, $context),
		];

		$data['mediaId'] = $this->upsertMedia($id, $paymentMethodConfiguration, $context);

		$data = array_filter($data);

		$this->paymentMethodRepository->upsert([$data], $context);
	}

	/**
	 * Get payment method availability rule
	 *
	 * @param string                           $id
	 * @param \Shopware\Core\Framework\Context $context
	 *
	 * @return string
	 */
	private function getAvailabilityRuleId(string $id, Context $context): string
	{
		/**
		 * @var \Shopware\Core\Checkout\Payment\PaymentMethodEntity $paymentMethod
		 */
		$paymentMethod = $this->paymentMethodRepository->search((new Criteria([$id])), $context)->first();
		if(!(
			is_null($paymentMethod) ||
			is_null($paymentMethod->getAvailabilityRuleId())
		)){
			return $paymentMethod->getAvailabilityRuleId();
		}

		$criteria         = (new Criteria())->addFilter(new EqualsFilter('name', self::TRUSTPAYMENTS_AVAILABILITY_RULE_NAME));
		$availabilityRule = $this->ruleRepository->search($criteria, $context)->first();

		if (!is_null($availabilityRule)) {
			return $availabilityRule->getId();
		}

		$ruleId = Uuid::randomHex();
		$data   = [
			'id'          => $ruleId,
			'name'        => self::TRUSTPAYMENTS_AVAILABILITY_RULE_NAME,
			'priority'    => 1,
			'description' => 'Determines whether or not TrustPayments payment methods are available for the given rule context.',
			'conditions'  => [
				[
					'type'     => (new AndRule())->getName(),
					'children' => [
						[
							'type'  => (new CartAmountRule())->getName(),
							'value' => [
								'operator' => CartAmountRule::OPERATOR_GT,
								'amount'   => 0.00,
							],
						],
					],
				],
			],
		];

		$this->ruleRepository->create([$data], $context);

		return $ruleId;
	}

	/**
	 * @param \TrustPayments\Sdk\Model\PaymentMethodConfiguration $paymentMethodConfiguration
	 * @param \Shopware\Core\Framework\Context                            $context
	 *
	 * @return array
	 * @throws \TrustPayments\Sdk\ApiException
	 * @throws \TrustPayments\Sdk\Http\ConnectionException
	 * @throws \TrustPayments\Sdk\VersioningException
	 */
	protected function getPaymentMethodConfigurationTranslation(PaymentMethodConfiguration $paymentMethodConfiguration, Context $context): array
	{
		$translations = [];
		$locales      = $this->localeCodeProvider->getAvailableLocales($context);
		foreach ($locales as $locale) {
			$translations[$locale] = [
				'name'        => $this->translate($paymentMethodConfiguration->getResolvedTitle(), $locale) ?? $paymentMethodConfiguration->getName(),
				'description' => $this->translate($paymentMethodConfiguration->getResolvedDescription(), $locale) ?? '',
			];
		}
		return $translations;
	}

	/**
	 * @param array  $translatedString
	 * @param string $locale
	 *
	 * @return string|null
	 * @throws \TrustPayments\Sdk\ApiException
	 * @throws \TrustPayments\Sdk\Http\ConnectionException
	 * @throws \TrustPayments\Sdk\VersioningException
	 */
	protected function translate(array $translatedString, string $locale): ?string
	{
		$translation = null;

		if (isset($translatedString[$locale])) {
			$translation = $translatedString[$locale];
		}

		if (is_null($translation)) {

			$primaryLanguage = $this->findPrimaryLanguage($locale);
			if (!is_null($primaryLanguage) && isset($translatedString[$primaryLanguage->getIetfCode()])) {
				$translation = $translatedString[$primaryLanguage->getIetfCode()];
			}

			if (is_null($translation) && isset($translatedString['en-US'])) {
				$translation = $translatedString['en-US'];
			}
		}

		return $translation;
	}

	/**
	 * Returns the primary language in the given group.
	 *
	 * @param $code
	 *
	 * @return \TrustPayments\Sdk\Model\RestLanguage|null
	 * @throws \TrustPayments\Sdk\ApiException
	 * @throws \TrustPayments\Sdk\Http\ConnectionException
	 * @throws \TrustPayments\Sdk\VersioningException
	 */
	protected function findPrimaryLanguage(string $code): ?RestLanguage
	{
		$code = substr($code, 0, 2);
		foreach ($this->getLanguages() as $language) {
			if (($language->getIso2Code() == $code) && $language->getPrimaryOfGroup()) {
				return $language;
			}
		}
		return null;
	}

	/**
	 *
	 * @return array
	 * @throws \TrustPayments\Sdk\ApiException
	 * @throws \TrustPayments\Sdk\Http\ConnectionException
	 * @throws \TrustPayments\Sdk\VersioningException
	 */
	protected function getLanguages(): array
	{
		if (is_null($this->languages)) {
			$this->languages = $this->apiClient->getLanguageService()->all();
		}
		return $this->languages;
	}

	/**
	 * Upload Payment Method icons
	 *
	 * @param string                                                      $id
	 * @param \TrustPayments\Sdk\Model\PaymentMethodConfiguration $paymentMethodConfiguration
	 * @param \Shopware\Core\Framework\Context                            $context
	 *
	 * @return string|null
	 */
	protected function upsertMedia(string $id, PaymentMethodConfiguration $paymentMethodConfiguration, Context $context): ?string
	{
		try {
			$mediaDefaultFolderRepository = $this->container->get('media_default_folder.repository');
			$mediaDefaultFolderRepository->upsert([
				[
					'id'                => $id,
					'associationFields' => [],
					'entity'            => 'payment_method_' . $paymentMethodConfiguration->getId(),
				],
			], $context);

			$mediaFolderRepository = $this->container->get('media_folder.repository');
			$mediaFolderRepository->upsert([
				[
					'id'                     => $id,
					'defaultFolderId'        => $id,
					'name'                   => $paymentMethodConfiguration->getName(),
					'useParentConfiguration' => false,
					'configuration'          => [],
				],
			], $context);

			/**
			 * @var \Shopware\Core\Content\Media\MediaDefinition
			 */
			$mediaDefinition = $this->container->get(MediaDefinition::class);
			$this->mediaSerializer->setRegistry($this->serializerRegistry);
			$data = [
				'id'            => $id,
				'title'         => $paymentMethodConfiguration->getName(),
				'url'           => $paymentMethodConfiguration->getResolvedImageUrl(),
				'mediaFolderId' => $id,
			];
			$data = $this->mediaSerializer->deserialize(new Config([], []), $mediaDefinition, $data);
			$this->container->get('media.repository')->upsert([$data], $context);
			return $id;
		} catch (\Exception $e) {
			$this->logger->critical($e->getMessage(), [$e->getTraceAsString()]);
			return null;
		}
	}


}