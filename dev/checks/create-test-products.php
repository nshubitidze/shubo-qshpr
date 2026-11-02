<?php
/**
 * Dev helper: a "Quickshipper Test" category with parcels of known weights, to
 * see how courier prices change with the cart weight at checkout. Weights are
 * in kg and converted to the store's weight unit (Magento's default is lbs).
 *
 * Chosen around OnWay's weight brackets as the sandbox answered on 2026-10-01:
 * up to 1 kg none, (1, 2] 6.60, (2, 5] 8.60, (5, 10] 10.60, above 10 kg none.
 * Wolt and Glovo price the same whatever the weight.
 *
 * Idempotent: an existing SKU gets its name, weight and category updated.
 * Run inside the phpfpm container: php <module-dir>/dev/checks/create-test-products.php
 */

declare(strict_types=1);

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

require (require __DIR__ . '/_root.php') . '/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

const CATEGORY_NAME = 'Quickshipper Test';
const PRICE = 20.00;
const KG_PER_LB = 0.45359237;
const PARCELS = [
    // sku suffix => weight in kg
    '1KG' => 1.0,
    '1.5KG' => 1.5,
    '3KG' => 3.0,
    '7KG' => 7.0,
    '12KG' => 12.0,
];

$storeManager = $om->get(StoreManagerInterface::class);
$rootCategoryId = (int)$storeManager->getStore()->getRootCategoryId();
$websiteId = (int)$storeManager->getStore()->getWebsiteId();
$weightUnit = (string)$om->get(ScopeConfigInterface::class)->getValue('general/locale/weight_unit', 'store');

// Category, reused if it already exists under the store's root.
$categoryRepository = $om->get(CategoryRepositoryInterface::class);
$existing = $om->get(CategoryCollectionFactory::class)->create()
    ->addAttributeToFilter('name', CATEGORY_NAME)
    ->addAttributeToFilter('parent_id', $rootCategoryId)
    ->getFirstItem();
if ($existing->getId()) {
    $categoryId = (int)$existing->getId();
    echo "category exists: id=$categoryId \"" . CATEGORY_NAME . "\"\n";
} else {
    /** @var Category $category */
    $category = $om->create(Category::class);
    $category->setName(CATEGORY_NAME)
        ->setParentId($rootCategoryId)
        ->setIsActive(true)
        ->setIncludeInMenu(true)
        ->setUrlKey('quickshipper-test');
    $categoryId = (int)$categoryRepository->save($category)->getId();
    echo "category created: id=$categoryId \"" . CATEGORY_NAME . "\"\n";
}

$repository = $om->get(ProductRepositoryInterface::class);
foreach (PARCELS as $suffix => $kg) {
    $sku = 'QSHPR-TEST-' . $suffix;
    $label = rtrim(rtrim(number_format($kg, 1, '.', ''), '0'), '.') . ' kg';
    $weight = $weightUnit === 'lbs' ? round($kg / KG_PER_LB, 4) : $kg;

    try {
        $product = $repository->get($sku, true);
        $action = 'updated';
    } catch (NoSuchEntityException) {
        /** @var Product $product */
        $product = $om->create(Product::class);
        $product->setTypeId('simple')
            ->setAttributeSetId((int)$product->getDefaultAttributeSetId())
            ->setSku($sku)
            ->setPrice(PRICE)
            ->setStatus(Status::STATUS_ENABLED)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setWebsiteIds([$websiteId])
            ->setUrlKey('quickshipper-test-parcel-' . strtolower(str_replace('.', '-', $suffix)))
            ->setStockData(['qty' => 1000, 'is_in_stock' => 1, 'manage_stock' => 1]);
        $action = 'created';
    }

    $product->setName('Quickshipper Test Parcel – ' . $label)
        ->setWeight($weight)
        ->setCategoryIds([$categoryId]);
    $saved = $repository->save($product);
    printf("%s: id=%d sku=%s weight=%s %s (%s)\n", $action, $saved->getId(), $sku, $weight, $weightUnit, $label);
}
