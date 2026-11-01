<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Service;

use Ibexa\Contracts\Core\Repository\FieldTypeService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;

final class Fingerprint
{
    public function __construct(private readonly FieldTypeService $types)
    {
    }

    public function of(Content $content): string
    {
        $values = [];
        foreach ($content->getFields() as $field) {
            $values[$field->getFieldDefinitionIdentifier().':'.$field->getLanguageCode()] = $this->types->getFieldType($field->getFieldTypeIdentifier())->toHash($field->getValue());
        }
        $definitions = [];
        foreach ($content->getContentType()->getFieldDefinitions() as $definition) {
            $definitions[$definition->getIdentifier()] = [
                $definition->getFieldTypeIdentifier(), $definition->isRequired, $definition->isTranslatable,
                $definition->getValidatorConfiguration(), $definition->getFieldSettings(),
            ];
        }

        return hash('sha256', serialize($this->canonical([
            'fields' => $values, 'schema' => $definitions,
            'modified' => $content->getVersionInfo()->modificationDate->getTimestamp(),
            'languages' => $content->getVersionInfo()->getLanguageCodes(),
        ])));
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('U.u');
        }
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map($this->canonical(...), $value);
        }

        return $value;
    }
}
