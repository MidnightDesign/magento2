<?php
/**
 * Configurable product type resource model
 *
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ConfigurableProduct\Model\ResourceModel\Product\Type;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Api\Data\OptionInterface;

class Configurable extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Catalog product relation
     *
     * @var \Magento\Catalog\Model\ResourceModel\Product\Relation
     */
    protected $catalogProductRelation;

    /**
     * Product metadata pool
     *
     * @var \Magento\Framework\Model\Entity\MetadataPool
     */
    private $metadataPool;

    /**
     * Product entity link field
     *
     * @var string
     */
    private $productEntityLinkField;

    /**
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Catalog\Model\ResourceModel\Product\Relation $catalogProductRelation
     * @param string $connectionName
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Catalog\Model\ResourceModel\Product\Relation $catalogProductRelation,
        $connectionName = null
    ) {
        $this->catalogProductRelation = $catalogProductRelation;
        parent::__construct($context, $connectionName);
    }

    /**
     * Init resource
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('catalog_product_super_link', 'link_id');
    }

    /**
     * Get product entity id by product attribute
     *
     * @param OptionInterface $option
     * @return int
     */
    public function getEntityIdByAttribute(OptionInterface $option)
    {
        $select = $this->getConnection()->select()->from(
            ['e' => $this->getTable('catalog_product_entity')],
            ['e.entity_id']
        )->where(
            'e.' . $this->getProductEntityLinkField() . '=?',
            $option->getProductId()
        )->limit(1);

        return (int) $this->getConnection()->fetchOne($select);
    }

    /**
     * Save configurable product relations
     *
     * @param \Magento\Catalog\Model\Product $mainProduct the parent id
     * @param array $productIds the children id array
     * @return $this
     */
    public function saveProducts($mainProduct, array $productIds)
    {
        if (!$mainProduct instanceof ProductInterface) {
            return $this;
        }

        $productId = $mainProduct->getData($this->getProductEntityLinkField());

        $data = [];
        foreach ($productIds as $id) {
            $data[] = ['product_id' => (int) $id, 'parent_id' => (int) $productId];
        }

        if (!empty($data)) {
            $this->getConnection()->insertOnDuplicate(
                $this->getMainTable(),
                $data,
                ['product_id', 'parent_id']
            );
        }

        $where = ['parent_id = ?' => $productId];
        if (!empty($productIds)) {
            $where['product_id NOT IN(?)'] = $productIds;
        }

        $this->getConnection()->delete($this->getMainTable(), $where);

        // configurable product relations should be added to relation table
        $this->catalogProductRelation->processRelations($productId, $productIds);

        return $this;
    }

    /**
     * Retrieve Required children ids
     * Return grouped array, ex array(
     *   group => array(ids)
     * )
     *
     * @param int|array $parentId
     * @param bool $required
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getChildrenIds($parentId, $required = true)
    {
        $select = $this->getConnection()->select()->from(
            ['l' => $this->getMainTable()],
            ['product_id', 'parent_id']
        )->join(
            ['p' => $this->getTable('catalog_product_entity')],
            'p.' . $this->getProductEntityLinkField() . ' = l.parent_id',
            []
        )->join(
            ['e' => $this->getTable('catalog_product_entity')],
            'e.entity_id = l.product_id AND e.required_options = 0',
            []
        )->where(
            'p.entity_id IN (?)',
            $parentId
        );

        $childrenIds = [0 => []];
        foreach ($this->getConnection()->fetchAll($select) as $row) {
            $childrenIds[0][$row['product_id']] = $row['product_id'];
        }

        return $childrenIds;
    }

    /**
     * Retrieve parent ids array by required child
     *
     * @param int|array $childId
     * @return string[]
     */
    public function getParentIdsByChild($childId)
    {
        $parentIds = [];
        $select = $this->getConnection()
            ->select()
            ->from(['l' => $this->getMainTable()], [])
            ->join(
                ['e' => $this->getTable('catalog_product_entity')],
                'e.' . $this->getProductEntityLinkField() . ' = l.parent_id',
                ['e.entity_id']
            )->where('l.product_id IN(?)', $childId);

        foreach ($this->getConnection()->fetchAll($select) as $row) {
            $parentIds[] = $row['entity_id'];
        }

        return $parentIds;
    }

    /**
     * Collect product options with values according to the product instance and attributes, that were received
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param array $attributes
     * @return array
     */
    public function getConfigurableOptions($product, $attributes)
    {
        $attributesOptionsData = [];
        $productId = $product->getData($this->getProductEntityLinkField());
        foreach ($attributes as $superAttribute) {
            $select = $this->getConnection()->select()->from(
                ['super_attribute' => $this->getTable('catalog_product_super_attribute')],
                [
                    'sku' => 'entity.sku',
                    'product_id' => 'product_entity.entity_id',
                    'attribute_code' => 'attribute.attribute_code',
                    'option_title' => 'option_value.value',
                    'super_attribute_label' => 'attribute_label.value',
                ]
            )->joinInner(
                ['product_entity' => $this->getTable('catalog_product_entity')],
                "product_entity.{$this->getProductEntityLinkField()} = super_attribute.product_id",
                []
            )->joinInner(
                ['product_link' => $this->getTable('catalog_product_super_link')],
                'product_link.parent_id = super_attribute.product_id',
                []
            )->joinInner(
                ['attribute' => $this->getTable('eav_attribute')],
                'attribute.attribute_id = super_attribute.attribute_id',
                []
            )->joinInner(
                ['entity' => $this->getTable('catalog_product_entity')],
                'entity.entity_id = product_link.product_id',
                []
            )->joinInner(
                ['entity_value' => $superAttribute->getBackendTable()],
                implode(
                    ' AND ',
                    [
                        'entity_value.attribute_id = super_attribute.attribute_id',
                        'entity_value.store_id = 0',
                        "entity_value.{$this->getProductEntityLinkField()} = "
                        . "entity.{$this->getProductEntityLinkField()}"
                    ]
                ),
                []
            )->joinLeft(
                ['option_value' => $this->getTable('eav_attribute_option_value')],
                implode(
                    ' AND ',
                    [
                        'option_value.option_id = entity_value.value',
                        'option_value.store_id = ' . \Magento\Store\Model\Store::DEFAULT_STORE_ID
                    ]
                ),
                []
            )->joinLeft(
                ['attribute_label' => $this->getTable('catalog_product_super_attribute_label')],
                implode(
                    ' AND ',
                    [
                        'super_attribute.product_super_attribute_id = attribute_label.product_super_attribute_id',
                        'attribute_label.store_id = ' . \Magento\Store\Model\Store::DEFAULT_STORE_ID
                    ]
                ),
                []
            )->where(
                'super_attribute.product_id = ?',
                $productId
            );
            $attributesOptionsData[$superAttribute->getAttributeId()] = $this->getConnection()->fetchAll($select);
        }
        return $attributesOptionsData;
    }

    /**
     * Get product metadata pool
     *
     * @return \Magento\Framework\Model\Entity\MetadataPool
     */
    private function getMetadataPool()
    {
        if (!$this->metadataPool) {
            $this->metadataPool = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Model\Entity\MetadataPool::class);
        }
        return $this->metadataPool;
    }

    /**
     * Get product entity link field
     *
     * @return string
     */
    private function getProductEntityLinkField()
    {
        if (!$this->productEntityLinkField) {
            $this->productEntityLinkField = $this->getMetadataPool()
                ->getMetadata(\Magento\Catalog\Api\Data\ProductInterface::class)
                ->getLinkField();
        }
        return $this->productEntityLinkField;
    }
}
