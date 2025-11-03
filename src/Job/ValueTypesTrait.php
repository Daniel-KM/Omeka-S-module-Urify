<?php declare(strict_types=1);

namespace Urify\Job;

trait ValueTypesTrait
{
    protected function dataTypesFromValueTypes(?array $valueTypes): array
    {
        if (!$valueTypes) {
            return [];
        }

        $result = [];

        if (in_array('literal', $valueTypes)) {
            $result[] = 'literal';
        }

        if (in_array('uri', $valueTypes)) {
            $result[] = 'uri';
        }

        if (in_array('specified', $valueTypes)) {
            $result[] = $this->dataType;
        }

        if (in_array('custom_vocab_literal', $valueTypes)) {
            $mainCustomVocabs = $this->easyMeta->dataTypeMainCustomVocabs();
            $result = array_merge(
                $result,
                array_values(array_filter($mainCustomVocabs, fn ($v) => $v === 'literal'))
            );
        }

        if (in_array('custom_vocab_uri', $valueTypes)) {
            $mainCustomVocabs = $this->easyMeta->dataTypeMainCustomVocabs();
            $result = array_merge(
                $result,
                array_values(array_filter($mainCustomVocabs, fn ($v) => $v === 'uri'))
            );
        }

        return array_unique($result);
    }
}
