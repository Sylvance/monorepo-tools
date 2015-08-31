<?php

namespace SS6\ShopBundle\Model\Product\Pricing;

use SS6\ShopBundle\Model\Pricing\BasePriceCalculation;
use SS6\ShopBundle\Model\Pricing\Currency\CurrencyFacade;
use SS6\ShopBundle\Model\Pricing\Group\PricingGroup;
use SS6\ShopBundle\Model\Pricing\PricingService;
use SS6\ShopBundle\Model\Pricing\PricingSetting;
use SS6\ShopBundle\Model\Product\Pricing\ProductManualInputPriceRepository;
use SS6\ShopBundle\Model\Product\Pricing\ProductPrice;
use SS6\ShopBundle\Model\Product\Product;
use SS6\ShopBundle\Model\Product\ProductRepository;

class ProductPriceCalculation {

	/**
	 * @var \SS6\ShopBundle\Model\Pricing\BasePriceCalculation
	 */
	private $basePriceCalculation;

	/**
	 * @var \SS6\ShopBundle\Model\Pricing\PricingSetting
	 */
	private $pricingSetting;

	/**
	 * @var \SS6\ShopBundle\Model\Product\Pricing\ProductManualInputPriceRepository
	 */
	private $productManualInputPriceRepository;

	/**
	 * @var \SS6\ShopBundle\Model\Pricing\Currency\CurrencyFacade
	 */
	private $currencyFacade;

	/**
	 * @var \SS6\ShopBundle\Model\Product\ProductRepository
	 */
	private $productRepository;

	/**
	 * @var \SS6\ShopBundle\Model\Pricing\PricingService
	 */
	private $pricingService;

	public function __construct(
		BasePriceCalculation $basePriceCalculation,
		PricingSetting $pricingSetting,
		ProductManualInputPriceRepository $productManualInputPriceRepository,
		CurrencyFacade $currencyFacade,
		ProductRepository $productRepository,
		PricingService $pricingService
	) {
		$this->pricingSetting = $pricingSetting;
		$this->basePriceCalculation = $basePriceCalculation;
		$this->productManualInputPriceRepository = $productManualInputPriceRepository;
		$this->currencyFacade = $currencyFacade;
		$this->productRepository = $productRepository;
		$this->pricingService = $pricingService;
	}

	/**
	 * @param \SS6\ShopBundle\Model\Product\Product $product
	 * @param \SS6\ShopBundle\Model\Pricing\Group\PricingGroup $pricingGroup
	 * @return \SS6\ShopBundle\Model\Product\Pricing\ProductPrice
	 */
	public function calculatePrice(Product $product, PricingGroup $pricingGroup) {
		if ($product->isMainVariant()) {
			return $this->calculateMainVariantPrice($product, $pricingGroup);
		}

		$priceCalculationType = $product->getPriceCalculationType();
		if ($priceCalculationType === Product::PRICE_CALCULATION_TYPE_AUTO) {
			return $this->calculateBasePriceForPricingGroupAuto($product, $pricingGroup);
		} elseif ($priceCalculationType === Product::PRICE_CALCULATION_TYPE_MANUAL) {
			return $this->calculateBasePriceForPricingGroupManual($product, $pricingGroup);
		} else {
			$message = 'Product price calculation type ' . $priceCalculationType . ' is not supported';
			throw new \SS6\ShopBundle\Model\Product\Exception\InvalidPriceCalculationTypeException($message);
		}
	}

	/**
	 * @param \SS6\ShopBundle\Model\Product\Product $mainVariant
	 * @param \SS6\ShopBundle\Model\Pricing\Group\PricingGroup $pricingGroup
	 * @return \SS6\ShopBundle\Model\Product\Pricing\ProductPrice
	 */
	private function calculateMainVariantPrice(Product $mainVariant, PricingGroup $pricingGroup) {
		$variants = $this->productRepository->getAllSellableVariantsByMainVariant(
			$mainVariant,
			$pricingGroup->getDomainId(),
			$pricingGroup
		);
		if (count($variants) === 0) {
			$message = 'Main variant ID = ' . $mainVariant->getId() . ' has no sellable variants.';
			throw new \SS6\ShopBundle\Model\Product\Pricing\Exception\CalculatePriceException($message);
		}

		$variantPrices = [];
		foreach ($variants as $variant) {
			$variantPrices[] = $this->calculatePrice($variant, $pricingGroup);
		}

		$minVariantPrice = $this->pricingService->getMinimumPrice($variantPrices);
		$from = $this->pricingService->areDifferent($variantPrices);

		return new ProductPrice($minVariantPrice, $from);
	}

	/**
	 * @param \SS6\ShopBundle\Model\Product\Product $product
	 * @return \SS6\ShopBundle\Model\Pricing\Price
	 */
	public function calculateBasePrice(Product $product) {
		return $this->basePriceCalculation->calculateBasePrice(
				$product->getPrice(),
				$this->pricingSetting->getInputPriceType(),
				$product->getVat()
			);
	}

	/**
	 * @param \SS6\ShopBundle\Model\Product\Product $product
	 * @param \SS6\ShopBundle\Model\Pricing\Group\PricingGroup $pricingGroup
	 * @return \SS6\ShopBundle\Model\Product\Pricing\ProductPrice
	 */
	private function calculateBasePriceForPricingGroupManual(Product $product, PricingGroup $pricingGroup) {
		$manualInputPrice = $this->productManualInputPriceRepository->findByProductAndPricingGroup($product, $pricingGroup);
		if ($manualInputPrice !== null) {
			$price = $manualInputPrice->getInputPrice();
		} else {
			$price = 0;
		}
		$price = $this->basePriceCalculation->calculateBasePrice(
			$price,
			$this->pricingSetting->getInputPriceType(),
			$product->getVat()
		);

		return new ProductPrice($price, false);
	}

	/**
	 * @param \SS6\ShopBundle\Model\Product\Product $product
	 * @param \SS6\ShopBundle\Model\Pricing\Group\PricingGroup $pricingGroup
	 * @return \SS6\ShopBundle\Model\Product\Pricing\ProductPrice
	 */
	private function calculateBasePriceForPricingGroupAuto(Product $product, PricingGroup $pricingGroup) {
		$basePrice = $this->calculateBasePrice($product);

		$price = $this->basePriceCalculation->applyCoefficients(
			$basePrice,
			$product->getVat(),
			[$pricingGroup->getCoefficient(), $this->getDomainDefaultCurrencyReversedExchangeRate($pricingGroup)]
		);

		return new ProductPrice($price, false);
	}

	/**
	 * @param \SS6\ShopBundle\Model\Pricing\Group\PricingGroup $pricingGroup
	 * @return string
	 */
	private function getDomainDefaultCurrencyReversedExchangeRate(PricingGroup $pricingGroup) {
		$domainId = $pricingGroup->getDomainId();
		$domainDefaultCurrencyId = $this->pricingSetting->getDomainDefaultCurrencyIdByDomainId($domainId);
		$currency = $this->currencyFacade->getById($domainDefaultCurrencyId);

		return $currency->getReversedExchangeRate();
	}

}
