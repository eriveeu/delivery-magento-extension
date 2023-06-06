<?php
declare(strict_types=1);

namespace EriveEu\GreenToHomeShipping\Model\Config\Source;

/**
 * Return environment
 */
class Environment implements \Magento\Framework\Data\OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [['value' => '0', 'label' => __('Dev')],['value' => '1', 'label' => __('Stage')],['value' => '2', 'label' => __('Production')]];
    }

}
